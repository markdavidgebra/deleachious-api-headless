<?php

namespace App\Support;

use App\Models\Admin;
use App\Models\Order;
use App\Models\Transaction;
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

    public static function applyToOrders(Builder $query, $request = null): Builder
    {
        $branchId = self::requestedBranchId($request);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        return $query;
    }

    public static function applyToTransactions(Builder $query, $request = null): Builder
    {
        $branchId = self::requestedBranchId($request);

        if ($branchId) {
            $query->whereHas('order', fn (Builder $q) => $q->where('branch_id', $branchId));
        }

        return $query;
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
        return self::branchId() ?? $requested;
    }

    public static function assertOrder(Order $order): void
    {
        $branchId = self::branchId();

        if ($branchId && (int) $order->branch_id !== $branchId) {
            abort(404);
        }
    }

    public static function assertTransaction(Transaction $transaction): void
    {
        $order = $transaction->relationLoaded('order')
            ? $transaction->order
            : $transaction->order()->first();

        if ($order) {
            self::assertOrder($order);

            return;
        }

        if (self::isLocked()) {
            abort(404);
        }
    }

    public static function assertBranchId(?int $branchId): void
    {
        $locked = self::branchId();

        if ($locked && (int) $branchId !== $locked) {
            abort(404);
        }
    }
}
