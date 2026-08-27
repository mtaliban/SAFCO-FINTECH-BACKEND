<?php

namespace App\Http\Controllers\Api\V1\TrainerPortal;

use App\Http\Controllers\Controller;
use App\Models\TrainerProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * SRS Module 13 — Public trainer directory.
 * Anyone (even unauthenticated) may browse and view profiles.
 * Only profiles with is_public=true appear.
 */
class TrainerDirectoryController extends Controller
{
    /** Ensure every active trainer has a public TrainerProfile. Cached for 1 hour. */
    private function provisionMissingProfiles(): void
    {
        if (Cache::has('trainer_profiles_provisioned')) return;

        User::whereHas('roles', fn ($q) => $q->where('name', 'trainer'))
            ->where('status', 'active')
            ->whereDoesntHave('trainerProfile')
            ->with('profile')
            ->get()
            ->each(function (User $user) {
                $name = $user->profile?->full_name ?? explode('@', $user->email)[0];
                $base = Str::slug($name ?: 'trainer');
                do {
                    $slug = $base . '-' . Str::random(5);
                } while (TrainerProfile::where('public_slug', $slug)->exists());

                TrainerProfile::create([
                    'user_id'             => $user->id,
                    'public_slug'         => $slug,
                    'is_public'           => true,
                    'availability_status' => 'available',
                    'expertise_areas'     => [],
                    'teaching_languages'  => ['en'],
                    'timezone'            => 'Africa/Nairobi',
                ]);
            });

        Cache::put('trainer_profiles_provisioned', true, now()->addHour());
    }

    /** GET /trainers (public, throttled) */
    public function index(Request $request): JsonResponse
    {
        $this->provisionMissingProfiles();

        $q = TrainerProfile::query()
            ->with([
                'user:id,uuid,email',
                'user.profile:user_id,full_name,first_name,last_name,profile_picture',
            ])
            ->where('is_public', true);

        // Text search on headline + bio + user's full name
        if ($search = trim((string) $request->query('q', ''))) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $q->where(function ($w) use ($like) {
                $w->where('headline', 'like', $like)
                  ->orWhere('bio_long', 'like', $like)
                  ->orWhereHas('user.profile', fn ($p) => $p->where('full_name', 'like', $like));
            });
        }

        // Expertise filter — any expertise area matches
        if ($expertise = $request->query('expertise')) {
            $q->whereJsonContains('expertise_areas', $expertise);
        }

        // Verified only filter
        if ($request->boolean('verified_only')) {
            $q->where('is_verified', true);
        }

        // Minimum rating filter
        if (($min = (float) $request->query('min_rating', 0)) > 0) {
            $q->where('rating_avg', '>=', $min);
        }

        // Sort — options: 'rating' (default), 'newest', 'experience'
        $sort = $request->query('sort', 'rating');
        match ($sort) {
            'newest' => $q->orderByDesc('created_at'),
            'experience' => $q->orderByDesc('years_experience'),
            default => $q->orderByDesc('is_verified')->orderByDesc('rating_avg')->orderByDesc('rating_count'),
        };

        $paginated = $q->paginate((int) $request->query('per_page', 12));

        return $this->success([
            'data' => $paginated->getCollection()->map(fn (TrainerProfile $tp) => $this->summarize($tp)),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /** GET /trainers/{slug} (public) */
    public function show(TrainerProfile $trainer): JsonResponse
    {
        if (!$trainer->is_public) {
            return $this->error('Trainer profile is not public.', 404);
        }

        $trainer->load([
            'user:id,uuid,email',
            'user.profile',
            'qualifications' => fn ($q) => $q->where('verification_status', 'verified'),
            'certifications' => fn ($q) => $q->where('verification_status', 'verified'),
            'experiences',
            'reviews' => fn ($q) => $q->where('status', 'published')
                ->with(['student:id,uuid,email', 'student.profile:user_id,full_name,profile_picture', 'course:id,uuid,title'])
                ->latest()->limit(20),
            'courses' => fn ($q) => $q->where('status', 'published')
                ->withCount('enrollments')
                ->limit(20),
        ]);

        return $this->success($this->serialize($trainer));
    }

    private function summarize(TrainerProfile $tp): array
    {
        return [
            'slug' => $tp->public_slug,
            'name' => $tp->user->profile?->full_name ?? $tp->user->email,
            'headline' => $tp->headline,
            'avatar' => $tp->user->profile?->profile_picture,
            'years_experience' => $tp->effectiveYearsExperience(),
            'expertise_areas' => $tp->expertise_areas ?? [],
            'is_verified' => $tp->is_verified,
            'rating_avg' => $tp->rating_avg !== null ? (float) $tp->rating_avg : null,
            'rating_count' => $tp->rating_count,
            'students_taught' => $tp->students_taught_count,
            'availability_status' => $tp->availability_status,
        ];
    }

    private function serialize(TrainerProfile $tp): array
    {
        return [
            'slug' => $tp->public_slug,
            'name' => $tp->user->profile?->full_name ?? $tp->user->email,
            'headline' => $tp->headline,
            'bio_long' => $tp->bio_long,
            'avatar' => $tp->user->profile?->profile_picture,
            'cover' => $tp->user->profile?->cover_photo,
            'years_experience' => $tp->effectiveYearsExperience(),
            'expertise_areas' => $tp->expertise_areas ?? [],
            'teaching_languages' => $tp->teaching_languages ?? [],
            'timezone' => $tp->timezone,
            'hourly_rate_tzs' => $tp->hourly_rate_tzs,
            'availability_status' => $tp->availability_status,
            'is_verified' => $tp->is_verified,
            'verified_at' => $tp->verified_at?->toIso8601String(),
            'rating_avg' => $tp->rating_avg !== null ? (float) $tp->rating_avg : null,
            'rating_count' => $tp->rating_count,
            'students_taught' => $tp->students_taught_count,
            'public_email' => $tp->accepts_direct_inquiries ? $tp->public_email : null,
            'qualifications' => $tp->qualifications->map(fn ($q) => [
                'id' => $q->uuid,
                'institution' => $q->institution,
                'degree' => $q->degree,
                'field_of_study' => $q->field_of_study,
                'start_year' => $q->start_year,
                'end_year' => $q->end_year,
            ]),
            'certifications' => $tp->certifications->map(fn ($c) => [
                'id' => $c->uuid,
                'name' => $c->name,
                'issuer' => $c->issuer,
                'credential_id' => $c->credential_id,
                'verification_url' => $c->verification_url,
                'issue_date' => $c->issue_date?->toDateString(),
                'expiry_date' => $c->expiry_date?->toDateString(),
                'is_expired' => $c->isExpired(),
                'is_expiring_soon' => $c->isExpiringSoon(),
            ]),
            'experiences' => $tp->experiences->map(fn ($e) => [
                'id' => $e->uuid,
                'title' => $e->title,
                'company' => $e->company,
                'location' => $e->location,
                'start_date' => $e->start_date?->toDateString(),
                'end_date' => $e->end_date?->toDateString(),
                'is_current' => $e->isCurrent(),
                'duration_years' => $e->durationYears(),
                'description' => $e->description,
            ]),
            'courses' => $tp->courses->map(fn ($c) => [
                'id' => $c->uuid,
                'title' => $c->title,
                'category' => $c->category,
                'thumbnail_url' => $c->thumbnail_url,
                'enrollments_count' => $c->enrollments_count ?? 0,
                'price_tzs' => $c->price_tzs,
            ]),
            'reviews' => $tp->reviews->map(fn ($r) => [
                'id' => $r->uuid,
                'rating' => $r->rating,
                'text' => $r->review_text,
                'student_name' => $r->student->profile?->full_name ?? 'Student',
                'course_title' => $r->course?->title,
                'at' => $r->created_at?->toIso8601String(),
            ]),
        ];
    }
}
