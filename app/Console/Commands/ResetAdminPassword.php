<?php

namespace App\Console\Commands;

use App\Models\Admin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Reset (or create) an admin's password.
 *
 * Use this when a hashed-cast / double-hash mishap, an unknown legacy
 * password, or a forgotten credential locks you out of the admin panel.
 *
 * Examples
 *  php artisan admin:reset-password
 *  php artisan admin:reset-password admin@daleachious.com
 *  php artisan admin:reset-password admin@daleachious.com Admin@123 --force
 */
class ResetAdminPassword extends Command
{
    protected $signature = 'admin:reset-password
        {email? : The admin email (defaults to admin@daleachious.com)}
        {password? : The new plain-text password (defaults to Admin@123)}
        {--force : Skip the confirmation prompt}
        {--activate : Also flip is_active back to true}';

    protected $description = 'Reset an admin account password to a known value (uses the model\'s hashed cast).';

    public function handle(): int
    {
        $email    = $this->argument('email')    ?: 'admin@daleachious.com';
        $password = $this->argument('password') ?: 'Admin@123';
        $activate = (bool) $this->option('activate');

        $admin = Admin::where('email', $email)->first();

        if (! $admin) {
            $this->warn("No admin found with email [{$email}].");

            if (! $this->option('force') && ! $this->confirm("Create a new super_admin with email [{$email}]?", true)) {
                return self::FAILURE;
            }

            $admin = new Admin();
            $admin->email     = $email;
            $admin->name      = 'Super Admin';
            $admin->role      = 'super_admin';
            $admin->is_active = true;
        }

        if (! $this->option('force')
            && ! $this->confirm("Reset password for [{$admin->email}] (id={$admin->id}) now?", true)) {
            $this->info('Aborted.');
            return self::FAILURE;
        }

        // Assign the PLAIN password; the Admin model's `'password' => 'hashed'`
        // cast handles hashing exactly once. Do NOT pass bcrypt(...) here or
        // the cast may double-hash on some Laravel versions, which is what
        // caused the original "Invalid credentials" lockout.
        $admin->password = $password;

        if ($activate) {
            $admin->is_active = true;
        }

        $admin->save();

        $admin->refresh();

        $verified = Hash::check($password, $admin->password);

        $this->newLine();
        $this->info("Admin updated.");
        $this->line("  id     : {$admin->id}");
        $this->line("  email  : {$admin->email}");
        $this->line("  role   : {$admin->role}");
        $this->line("  active : " . ($admin->is_active ? 'yes' : 'no'));
        $this->line("  verify : " . ($verified ? 'PASS' : 'FAIL'));

        return $verified ? self::SUCCESS : self::FAILURE;
    }
}
