<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds one demo account per SRS role that isn't already present:
 * - student
 * - corporate_client (attached to Bank of Tanzania per SRS mfano)
 *
 * Admin + Trainer (Yustino) live in AdminUserSeeder.
 */
class DemoAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $safco = Organization::where('name', 'SAFCO FINTECH LTD')->first();
        $bot   = Organization::where('name', 'Bank of Tanzania')->first();

        // Student
        $student = User::firstOrCreate(
            ['email' => 'student@safcofintech.co.tz'],
            [
                'password' => Hash::make('Student@2026!'),
                'organization_id' => $safco?->id,
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );
        UserProfile::firstOrCreate(
            ['user_id' => $student->id],
            ['full_name' => 'John Mwanafunzi', 'first_name' => 'John', 'last_name' => 'Mwanafunzi', 'position' => 'Student', 'country' => 'Tanzania']
        );
        $student->syncRoles(['student']);

        // Corporate Client — Bank of Tanzania rep (per SRS)
        $corporate = User::updateOrCreate(
            ['email' => 'selemanindwata61@gmail.com'],
            [
                'password' => Hash::make('12345678'),
                'organization_id' => $bot?->id,
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );
        UserProfile::firstOrCreate(
            ['user_id' => $corporate->id],
            ['full_name' => 'BOT HR Admin', 'first_name' => 'BOT', 'last_name' => 'HR Admin', 'position' => 'HR Manager', 'country' => 'Tanzania']
        );
        $corporate->syncRoles(['corporate_client']);
    }
}
