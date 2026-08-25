<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

class Role extends SpatieRole
{
    protected $fillable = [
        'name',
        'guard_name',
        'display_name',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    /**
     * Spatie's users() relation resolves the model from the auth guard.
     * Our admin guard uses Sanctum, which does not expose a provider model
     * during withCount(), so we bind Admins explicitly.
     */
    public function users(): BelongsToMany
    {
        return $this->morphedByMany(
            Admin::class,
            'model',
            config('permission.table_names.model_has_roles'),
            app(PermissionRegistrar::class)->pivotRole,
            config('permission.column_names.model_morph_key')
        );
    }
}
