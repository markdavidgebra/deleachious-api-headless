<?php

namespace App\Support;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Builder;

class AdminBranchScope
{
    public static function actor(): ?Admin
    {
        $user = auth()->user();

        return $user instanceof Admin ? $user : null;
    }

    public static function isLocked(): bool
    {
        return (bool) self::actor()?->isBranchScoped();
    }

    public static function branchId(): ?int
    {
        $admin = self::actor();

        if (! $admin?->isBranchScoped()) {
            return null;
        }

        return (int) $admin->branch_id;
    }

    public static function requestedBranchId($request): ?int
    {
        if (self::isLocked()) {
            return self::branchId();
        }

        if (! $request?->filled('branch_id')) {
            return null;
        }

        return (int) $request->branch_id;
    }

    public static function applyColumn(Builder $query, string $column = 'branch_id', $request = null): Builder
    {
        $branchId = self::requestedBranchId($request);

        if ($branchId) {
            $query->where($column, $branchId);
        }

        return $query;
    }

    public static function resolveWriteBranchId(?int $requested): ?int
    {
        $locked = self::branchId();
        if ($locked) {
            return $locked;
        }

        return $requested ?? (self::actor()?->branch_id ? (int) self::actor()->branch_id : null);
    }

    public static function assertBranchId(?int $branchId): void
    {
        $locked = self::branchId();

        if ($locked && (int) $branchId !== $locked) {
            abort(404);
        }
    }
}
