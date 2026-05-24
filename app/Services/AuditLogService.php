<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogService
{
    // Log any action
    public static function log(
        string $action,
        string $module,
        string $description,
        mixed  $model     = null,
        array  $oldValues = [],
        array  $newValues = []
    ): void {
        try {
            $admin   = auth()->user();
            $request = request();

            AuditLog::create([
                'admin_id'       => $admin?->id,
                'branch_id'      => $admin?->branch_id,
                'action'         => $action,
                'module'         => $module,
                'description'    => $description,
                'auditable_type' => $model ? get_class($model) : null,
                'auditable_id'   => $model?->id,
                'old_values'     => ! empty($oldValues) ? $oldValues : null,
                'new_values'     => ! empty($newValues) ? $newValues : null,
                'ip_address'     => $request?->ip(),
                'user_agent'     => $request?->userAgent(),
            ]);
        } catch (\Exception $e) {
            // Never let audit log crash the app
            \Log::error('AuditLog error: ' . $e->getMessage());
        }
    }

    // Shortcuts for common actions
    public static function created(string $module, mixed $model, string $description = ''): void
    {
        self::log('created', $module, $description ?: ucfirst($module) . ' created', $model, [], $model->toArray());
    }

    public static function updated(string $module, mixed $model, array $oldValues, string $description = ''): void
    {
        self::log('updated', $module, $description ?: ucfirst($module) . ' updated', $model, $oldValues, $model->toArray());
    }

    public static function deleted(string $module, mixed $model, string $description = ''): void
    {
        self::log('deleted', $module, $description ?: ucfirst($module) . ' deleted', $model, $model->toArray(), []);
    }

    public static function login(mixed $admin): void
    {
        self::log('login', 'auth', 'Admin logged in: ' . $admin->name, $admin);
    }

    public static function logout(mixed $admin): void
    {
        self::log('logout', 'auth', 'Admin logged out: ' . $admin->name, $admin);
    }
}