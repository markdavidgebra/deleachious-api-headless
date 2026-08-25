<?php

namespace App\Support;

use App\Models\Admin;

class AdminPermissions
{
    public const GUARD = 'admin';

    /**
     * Admin panel functions the super admin can assign to a role.
     * Each function has access keys (view, create, a tab name, …).
     * Stored permissions are `{function}` plus `{function}.{access}`.
     */
    public static function catalog(): array
    {
        return array_map(static function (array $item) {
            $item['access'] = array_map(static function (array $access) use ($item) {
                return [
                    'key'   => $access['key'],
                    'name'  => $item['name'].'.'.$access['key'],
                    'label' => $access['label'],
                    'kind'  => $access['kind'] ?? 'grant',
                ];
            }, $item['access']);

            return $item;
        }, self::definitions());
    }

    public static function names(): array
    {
        $names = [];

        foreach (self::definitions() as $item) {
            $names[] = $item['name'];
            foreach ($item['access'] as $access) {
                $names[] = $item['name'].'.'.$access['key'];
            }
        }

        return $names;
    }

    public static function functionNames(): array
    {
        return array_column(self::definitions(), 'name');
    }

    public static function grouped(): array
    {
        $groups = [];

        foreach (self::catalog() as $item) {
            $groups[$item['group']][] = $item;
        }

        return $groups;
    }

    public static function accessNamesFor(string $function): array
    {
        return self::accessNamesForKind($function);
    }

    public static function grantAccessNamesFor(string $function): array
    {
        return self::accessNamesForKind($function, 'grant');
    }

    /**
     * @param  'grant'|'restrict'|null  $kind
     * @return list<string>
     */
    private static function accessNamesForKind(string $function, ?string $kind = null): array
    {
        foreach (self::definitions() as $item) {
            if ($item['name'] !== $function) {
                continue;
            }

            $access = $kind === null
                ? $item['access']
                : array_values(array_filter(
                    $item['access'],
                    static fn (array $entry) => ($entry['kind'] ?? 'grant') === $kind
                ));

            return array_map(
                static fn (array $entry) => $item['name'].'.'.$entry['key'],
                $access
            );
        }

        return [];
    }

    public static function isRestrict(string $permission): bool
    {
        if (! str_contains($permission, '.')) {
            return false;
        }

        [$function, $key] = explode('.', $permission, 2);

        foreach (self::definitions() as $item) {
            if ($item['name'] !== $function) {
                continue;
            }

            foreach ($item['access'] as $access) {
                if ($access['key'] === $key) {
                    return ($access['kind'] ?? 'grant') === 'restrict';
                }
            }
        }

        return false;
    }

    /**
     * Always persist the parent function when any of its access keys are on,
     * and expand a parent-only (legacy) grant into every grant key.
     * Restrict keys (hide email, …) stay opt-in and are never implied.
     */
    public static function normalize(array $permissions): array
    {
        $allowed = array_flip(self::names());
        $held    = [];

        foreach ($permissions as $name) {
            if (isset($allowed[$name])) {
                $held[$name] = true;
            }
        }

        foreach (self::definitions() as $item) {
            $fn     = $item['name'];
            $grants = self::grantAccessNamesFor($fn);
            $access = self::accessNamesFor($fn);
            $hasAny = isset($held[$fn]);
            $hasGrantKeys = false;

            foreach ($access as $key) {
                if (isset($held[$key])) {
                    $hasAny = true;
                }
            }

            foreach ($grants as $key) {
                if (isset($held[$key])) {
                    $hasGrantKeys = true;
                }
            }

            if ($hasAny && ! $hasGrantKeys) {
                foreach ($grants as $key) {
                    $held[$key] = true;
                }
            }

            if ($hasAny) {
                $held[$fn] = true;
            } else {
                unset($held[$fn]);
            }
        }

        return array_keys($held);
    }

    public static function defaultsFor(string $role): array
    {
        $functions = match ($role) {
            'developer', 'super_admin', 'admin' => self::functionNames(),
            'staff' => ['dashboard', 'orders', 'products', 'qr', 'members', 'redemptions'],
            'cashier' => ['dashboard', 'orders', 'qr', 'transactions'],
            default => ['dashboard'],
        };

        return self::normalize($functions);
    }

    public static function allows(Admin $admin, string $permission): bool
    {
        if ($admin->hasFullAccess()) {
            return true;
        }

        return self::held(
            $admin->getAllPermissions()->pluck('name')->all(),
            $permission
        );
    }

    /**
     * Restrict keys (hide email, …) are opt-in. Super Admin / Developer never hide fields.
     */
    public static function restricts(Admin $admin, string $permission): bool
    {
        if ($admin->hasFullAccess() || ! self::isRestrict($permission)) {
            return false;
        }

        return in_array($permission, $admin->getAllPermissions()->pluck('name')->all(), true);
    }

    /**
     * @param  list<string>  $held
     */
    public static function held(array $held, string $permission): bool
    {
        if (in_array($permission, $held, true)) {
            return true;
        }

        if (self::isRestrict($permission)) {
            return false;
        }

        if (! str_contains($permission, '.')) {
            foreach ($held as $name) {
                if ($name === $permission || str_starts_with($name, $permission.'.')) {
                    return true;
                }
            }

            return false;
        }

        [$function] = explode('.', $permission, 2);

        if (! in_array($function, $held, true)) {
            return false;
        }

        foreach ($held as $name) {
            if (str_starts_with($name, $function.'.')) {
                return false;
            }
        }

        // Parent-only grant (roles saved before access keys existed).
        return true;
    }

    /**
     * @return list<array{name: string, label: string, group: string, access: list<array{key: string, label: string, kind?: string}>}>
     */
    private static function definitions(): array
    {
        return [
            [
                'name'   => 'dashboard',
                'label'  => 'Dashboard',
                'group'  => 'Overview',
                'access' => [
                    ['key' => 'view', 'label' => 'View'],
                ],
            ],
            [
                'name'   => 'orders',
                'label'  => 'Orders',
                'group'  => 'Commerce',
                'access' => [
                    ['key' => 'view',   'label' => 'View'],
                    ['key' => 'update', 'label' => 'Update status'],
                    ['key' => 'pay',    'label' => 'Record payment'],
                    ['key' => 'cancel', 'label' => 'Cancel'],
                ],
            ],
            [
                'name'   => 'transactions',
                'label'  => 'Transactions',
                'group'  => 'Commerce',
                'access' => [
                    ['key' => 'view',   'label' => 'View'],
                    ['key' => 'create', 'label' => 'Record payment'],
                    ['key' => 'refund', 'label' => 'Refund'],
                ],
            ],
            [
                'name'   => 'products',
                'label'  => 'Menu & Products',
                'group'  => 'Commerce',
                'access' => [
                    ['key' => 'view',   'label' => 'View'],
                    ['key' => 'create', 'label' => 'Create'],
                    ['key' => 'update', 'label' => 'Edit'],
                    ['key' => 'delete', 'label' => 'Delete'],
                ],
            ],
            [
                'name'   => 'qr',
                'label'  => 'QR / Scan',
                'group'  => 'Commerce',
                'access' => [
                    ['key' => 'generate', 'label' => 'Generate'],
                    ['key' => 'scan',     'label' => 'Scan'],
                    ['key' => 'history',  'label' => 'History'],
                ],
            ],
            [
                'name'   => 'members',
                'label'  => 'Members',
                'group'  => 'Customers',
                'access' => [
                    ['key' => 'view',        'label' => 'View'],
                    ['key' => 'adjust',      'label' => 'Adjust points'],
                    ['key' => 'hide_email',  'label' => 'Hide email',  'kind' => 'restrict'],
                    ['key' => 'hide_phone',  'label' => 'Hide phone',  'kind' => 'restrict'],
                    ['key' => 'hide_points', 'label' => 'Hide points', 'kind' => 'restrict'],
                ],
            ],
            [
                'name'   => 'loyalty',
                'label'  => 'Loyalty Program',
                'group'  => 'Customers',
                'access' => [
                    ['key' => 'rewards',  'label' => 'Rewards'],
                    ['key' => 'manage',   'label' => 'Add / edit rewards'],
                    ['key' => 'settings', 'label' => 'Point settings'],
                ],
            ],
            [
                'name'   => 'redemptions',
                'label'  => 'Redemptions',
                'group'  => 'Customers',
                'access' => [
                    ['key' => 'view',   'label' => 'View'],
                    ['key' => 'review', 'label' => 'Approve / reject'],
                ],
            ],
            [
                'name'   => 'branches',
                'label'  => 'Branches',
                'group'  => 'Administration',
                'access' => [
                    ['key' => 'view',   'label' => 'View'],
                    ['key' => 'create', 'label' => 'Create'],
                    ['key' => 'update', 'label' => 'Edit'],
                    ['key' => 'delete', 'label' => 'Delete'],
                ],
            ],
            [
                'name'   => 'staff',
                'label'  => 'Staff',
                'group'  => 'Administration',
                'access' => [
                    ['key' => 'view',   'label' => 'View'],
                    ['key' => 'create', 'label' => 'Create'],
                    ['key' => 'update', 'label' => 'Edit'],
                    ['key' => 'delete', 'label' => 'Delete'],
                ],
            ],
            [
                'name'   => 'notifications',
                'label'  => 'Notifications',
                'group'  => 'Administration',
                'access' => [
                    ['key' => 'view', 'label' => 'View'],
                    ['key' => 'send', 'label' => 'Send'],
                    ['key' => 'delete', 'label' => 'Delete'],
                ],
            ],
            [
                'name'   => 'settings',
                'label'  => 'Settings',
                'group'  => 'Administration',
                'access' => [
                    ['key' => 'general',  'label' => 'General'],
                    ['key' => 'security', 'label' => 'Security'],
                    ['key' => 'style',    'label' => 'Style'],
                ],
            ],
        ];
    }
}
