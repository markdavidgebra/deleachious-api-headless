<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\Branch;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::where('code', 'DAL-001')->first();

        Admin::create([
            'name'      => 'Super Admin',
            'email'     => 'admin@daleachious.com',
            'password'  => bcrypt('Admin@123'),
            'role'      => 'super_admin',
            'phone'     => '09123456789',
            'branch_id' => $branch?->id,
            'is_active' => true,
        ]);
    }
}