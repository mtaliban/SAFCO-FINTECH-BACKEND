<?php

namespace Database\Seeders;

use App\Models\Organization;
use Illuminate\Database\Seeder;

class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $orgs = [
            ['name' => 'Bank of Tanzania', 'type' => 'bank'],
            ['name' => 'CRDB Bank', 'type' => 'bank'],
            ['name' => 'NMB Bank', 'type' => 'bank'],
            ['name' => 'NBC Bank', 'type' => 'bank'],
            ['name' => 'SACCOS Tanzania', 'type' => 'saccos'],
            ['name' => 'SAFCO FINTECH LTD', 'type' => 'corporate'],
        ];

        foreach ($orgs as $org) {
            Organization::firstOrCreate(
                ['name' => $org['name']],
                array_merge($org, ['is_active' => true, 'is_verified' => true, 'verified_at' => now()])
            );
        }
    }
}
