<?php

namespace App\Services\Forum;

use App\Models\Forum\ForumMention;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * SRS Module 14 — parse @username mentions and persist ForumMention rows
 * so notifications can fan out.
 *
 * Handle resolution:
 *  - "@amina" matches users whose email starts with "amina@…" (case-insensitive)
 *  - OR whose UserProfile.first_name equals "amina" (case-insensitive)
 *  - Self-mentions are dropped (no notify-yourself)
 *  - Inactive users are dropped (no notify-suspended)
 *  - Hard cap of 10 distinct handles per body (prevents notification spam)
 *
 * Portable: no MySQL-only functions (works on SQLite in tests + MySQL in prod).
 */
class MentionParser
{
    private const MAX_HANDLES_PER_BODY = 10;

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function extractAndPersist(Model $target, string $targetType, string $body, User $author): \Illuminate\Support\Collection
    {
        preg_match_all('/(?<![\w@])@([A-Za-z0-9._-]{2,60})/', $body, $matches);
        $handles = array_slice(array_unique(array_map('strtolower', $matches[1] ?? [])), 0, self::MAX_HANDLES_PER_BODY);
        if (empty($handles)) return collect();

        // Portable resolution: pull candidate rows and filter in PHP. For a small
        // handle count this is one indexed query on email + one join for profiles.
        // We do NOT use SUBSTRING_INDEX / regex — SQLite doesn't have them.
        $handlesLike = array_map(fn ($h) => $h . '@%', $handles);

        $users = User::query()
            ->with('profile:user_id,first_name')
            // Email match: LOWER(email) LIKE 'handle@%'
            ->where(function ($q) use ($handlesLike) {
                foreach ($handlesLike as $like) {
                    $q->orWhereRaw('LOWER(email) LIKE ?', [$like]);
                }
            })
            // OR first-name match on profile.
            ->orWhereHas('profile', function ($q) use ($handles) {
                foreach ($handles as $h) {
                    $q->orWhereRaw('LOWER(first_name) = ?', [$h]);
                }
            })
            ->limit(200) // safety upper bound
            ->get();

        // Verify each user actually matches one of the handles + is active + is not the author.
        $matched = $users->filter(function (User $u) use ($handles, $author) {
            if ((int) $u->id === (int) $author->id) return false;
            if (($u->status ?? 'active') !== 'active') return false;

            $emailPrefix = strtolower(explode('@', $u->email)[0]);
            if (in_array($emailPrefix, $handles, true)) return true;

            $firstName = strtolower((string) ($u->profile?->first_name ?? ''));
            return $firstName !== '' && in_array($firstName, $handles, true);
        })->values();

        foreach ($matched as $u) {
            ForumMention::firstOrCreate([
                'mentionable_type' => $targetType,
                'mentionable_id' => $target->id,
                'mentioned_user_id' => $u->id,
            ]);
        }
        return $matched;
    }
}
