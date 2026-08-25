<?php

namespace App\Models;

use App\Support\AdminPermissions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class Admin extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $guard = 'admin';

    protected $guard_name = 'admin';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'phone',
        'branch_id',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'password'  => 'hashed',
        'is_active' => 'boolean',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin' || $this->hasRole('super_admin');
    }

    public function isDeveloper(): bool
    {
        return $this->role === 'developer' || $this->hasRole('developer');
    }

    public function hasFullAccess(): bool
    {
        return $this->isDeveloper() || $this->isSuperAdmin();
    }

    public function isBranchScoped(): bool
    {
        return ! $this->hasFullAccess() && filled($this->branch_id);
    }

    public function requiresBranch(): bool
    {
        return in_array($this->role, ['staff', 'cashier'], true);
    }

    public static function concealedRoleNames(): array
    {
        return ['super_admin', 'developer'];
    }

    public function scopeNotConcealed($query)
    {
        return $query->whereNotIn('role', self::concealedRoleNames());
    }

    public function syncNamedRole(string $roleName): void
    {
        $this->syncRoles([$roleName]);
        $this->forceFill(['role' => $roleName])->save();
    }

    public function toAuthArray(): array
    {
        $assigned = $this->roles()->first();
        $isDev    = $this->isDeveloper();
        $isSuper  = $this->isSuperAdmin();
        $full     = $isDev || $isSuper;
        $branch   = $this->relationLoaded('branch') ? $this->branch : $this->branch()->first();

        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'email'            => $this->email,
            'phone'            => $this->phone,
            'branch_id'        => $this->branch_id,
            'is_branch_scoped' => $this->isBranchScoped(),
            'branch'           => $branch ? [
                'id'   => $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
                'city' => $branch->city,
            ] : null,
            'role'             => $this->role,
            'role_label'       => $assigned?->display_name
                ?? str_replace('_', ' ', (string) $this->role),
            'is_active'        => $this->is_active,
            'is_super_admin'   => $isSuper,
            'is_developer'     => $isDev,
            'permissions'      => $full
                ? AdminPermissions::names()
                : $this->getAllPermissions()->pluck('name')->values()->all(),
        ];
    }
}
