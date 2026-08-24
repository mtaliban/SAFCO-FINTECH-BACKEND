<?php

namespace App\Services\TrainerPortal;

use App\Models\TrainerCertification;
use App\Models\TrainerProfile;
use App\Models\TrainerQualification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * SRS Module 13 — Admin verification workflow.
 *
 * When admin verifies EVERY qualification + certification of a trainer,
 * we set trainer_profile.is_verified = true and record verified_by/at.
 * The "Certified Trainer" badge is derived from this flag on the frontend.
 */
class VerificationService
{
    public function verifyQualification(TrainerQualification $q, User $admin): void
    {
        DB::transaction(function () use ($q, $admin) {
            $q->update([
                'verification_status' => TrainerQualification::STATUS_VERIFIED,
                'verified_at' => now(),
                'verified_by' => $admin->id,
                'rejection_reason' => null,
            ]);
            $this->maybeIssueBadge($q->profile, $admin);
        });

        activity('trainer_portal')
            ->causedBy($admin)
            ->performedOn($q)
            ->withProperties([
                'institution' => $q->institution,
                'degree' => $q->degree,
                'trainer_user_id' => $q->profile->user_id,
            ])
            ->log('qualification_verified');
    }

    public function rejectQualification(TrainerQualification $q, User $admin, string $reason): void
    {
        DB::transaction(function () use ($q, $admin, $reason) {
            $q->update([
                'verification_status' => TrainerQualification::STATUS_REJECTED,
                'verified_at' => now(),
                'verified_by' => $admin->id,
                'rejection_reason' => $reason,
            ]);
            // Rejection revokes the badge.
            $q->profile->update([
                'is_verified' => false,
                'verified_at' => null,
                'verified_by' => null,
            ]);
        });

        activity('trainer_portal')
            ->causedBy($admin)
            ->performedOn($q)
            ->withProperties(['reason' => $reason, 'trainer_user_id' => $q->profile->user_id])
            ->log('qualification_rejected');
    }

    public function verifyCertification(TrainerCertification $c, User $admin): void
    {
        DB::transaction(function () use ($c, $admin) {
            $c->update([
                'verification_status' => TrainerCertification::STATUS_VERIFIED,
                'verified_at' => now(),
                'verified_by' => $admin->id,
                'rejection_reason' => null,
            ]);
            $this->maybeIssueBadge($c->profile, $admin);
        });

        activity('trainer_portal')
            ->causedBy($admin)
            ->performedOn($c)
            ->withProperties([
                'name' => $c->name,
                'issuer' => $c->issuer,
                'trainer_user_id' => $c->profile->user_id,
            ])
            ->log('certification_verified');
    }

    public function rejectCertification(TrainerCertification $c, User $admin, string $reason): void
    {
        DB::transaction(function () use ($c, $admin, $reason) {
            $c->update([
                'verification_status' => TrainerCertification::STATUS_REJECTED,
                'verified_at' => now(),
                'verified_by' => $admin->id,
                'rejection_reason' => $reason,
            ]);
            $c->profile->update([
                'is_verified' => false,
                'verified_at' => null,
                'verified_by' => null,
            ]);
        });

        activity('trainer_portal')
            ->causedBy($admin)
            ->performedOn($c)
            ->withProperties(['reason' => $reason, 'trainer_user_id' => $c->profile->user_id])
            ->log('certification_rejected');
    }

    /**
     * If EVERY qualification and certification is now verified AND there is
     * at least one of each kind of proof, promote the trainer to "verified".
     */
    private function maybeIssueBadge(TrainerProfile $profile, User $admin): void
    {
        $profile->refresh();

        if ($profile->isFullyVerified() && !$profile->is_verified) {
            $profile->update([
                'is_verified' => true,
                'verified_at' => now(),
                'verified_by' => $admin->id,
            ]);
            activity('trainer_portal')
                ->causedBy($admin)
                ->performedOn($profile)
                ->log('trainer_verified_badge_issued');
        }
    }

    /**
     * Recheck a trainer's badge status without an admin action.
     * Called from model boot() hooks after adds/deletes to keep is_verified in sync.
     */
    public function refreshBadgeState(TrainerProfile $profile): void
    {
        $profile->refresh();
        $fullyVerified = $profile->isFullyVerified();

        if ($fullyVerified && !$profile->is_verified) {
            // Rare — only happens if all creds were previously verified
            // and a new item was added and then verified without us running maybeIssueBadge.
            $profile->update([
                'is_verified' => true,
                'verified_at' => now(),
            ]);
            return;
        }

        if (!$fullyVerified && $profile->is_verified) {
            // Badge revoked because a new pending/rejected item appeared, or a
            // verified item was deleted leaving pending/rejected ones behind.
            $profile->update([
                'is_verified' => false,
                'verified_at' => null,
                'verified_by' => null,
            ]);
            activity('trainer_portal')
                ->performedOn($profile)
                ->withProperties(['reason' => 'credentials_changed'])
                ->log('trainer_verified_badge_revoked');
        }
    }
}
