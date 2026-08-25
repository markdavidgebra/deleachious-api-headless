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

        // updateOrCreate so re-seeding is idempotent and always restores the
        // known-good super-admin credentials. We pass the PLAIN password and
        // let the Admin model's `'password' => 'hashed'` cast hash it — this
        // avoids the double-hash trap that older code (bcrypt(...) on a
        // hashed-cast attribute) used to fall into on certain Laravel
        // versions, which is why `Hash::check` would later fail.
        $admin = Admin::updateOrCreate(
            ['email' => 'admin@daleachious.com'],
            [
                'name'      => 'Super Admin',
                'password'  => 'Admin@123',
                'role'      => 'super_admin',
                'phone'     => '09123456789',
                'branch_id' => $branch?->id,
                'is_active' => true,
            ]
        );

        $admin->syncNamedRole('super_admin');

        $developer = Admin::updateOrCreate(
            ['email' => 'developer@daleachious.com'],
            [
                'name'      => 'Developer',
                'password'  => 'Developer@123',
                'role'      => 'developer',
                'phone'     => null,
                'branch_id' => $branch?->id,
                'is_active' => true,
            ]
        );

        $developer->syncNamedRole('developer');
    }
}