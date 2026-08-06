<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $safco = Organization::where('name', 'SAFCO FINTECH LTD')->first();

        $admin = User::firstOrCreate(
            ['email' => 'admin@safcofintech.co.tz'],
            [
                'password' => Hash::make('Admin@2026!'),
                'organization_id' => $safco?->id,
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        UserProfile::firstOrCreate(
            ['user_id' => $admin->id],
            [
                'full_name' => 'System Administrator',
                'first_name' => 'System',
                'last_name' => 'Admin',
                'position' => 'System Administrator',
                'country' => 'Tanzania',
            ]
        );

        $admin->assignRole('system_admin');

        // Yustino Nyendeza (Trainer)
        $yustino = User::firstOrCreate(
            ['email' => 'yustino.nyendeza@safcofintech.co.tz'],
            [
                'password' => Hash::make('Trainer@2026!'),
                'organization_id' => $safco?->id,
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        UserProfile::firstOrCreate(
            ['user_id' => $yustino->id],
            [
                'full_name' => 'Yustino Nyendeza',
                'first_name' => 'Yustino',
                'last_name' => 'Nyendeza',
                'position' => 'Lead Trainer',
                'country' => 'Tanzania',
            ]
        );

        $yustino->assignRole('trainer');
    }
}
