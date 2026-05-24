<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        Branch::create([
            'name'         => 'Daleachious Main Branch',
            'code'         => 'DAL-001',
            'address'      => '123 Coffee Street, Poblacion',
            'city'         => 'Davao City',
            'phone'        => '082-123-4567',
            'email'        => 'main@daleachious.com',
            'opening_time' => '07:00',
            'closing_time' => '22:00',
            'is_active'    => true,
        ]);
    }
}