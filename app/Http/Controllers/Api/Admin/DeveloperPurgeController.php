<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Redemption;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\DeveloperPurgeService;
use Illuminate\Http\JsonResponse;

class DeveloperPurgeController extends Controller
{
    public function __construct(
        protected DeveloperPurgeService $purge,
    ) {}

    public function orders(): JsonResponse
    {
        return $this->run('orders', fn () => $this->purge->orders());
    }

    public function transactions(): JsonResponse
    {
        return $this->run('transactions', fn () => $this->purge->transactions());
    }

    public function members(): JsonResponse
    {
        return $this->run('members', fn () => $this->purge->members());
    }

    public function redemptions(): JsonResponse
    {
        return $this->run('redemptions', fn () => $this->purge->redemptions());
    }

    public function rewards(): JsonResponse
    {
        return $this->run('rewards', fn () => $this->purge->rewards());
    }

    public function destroyOrder(Order $order): JsonResponse
    {
        $label = $order->order_number;
        $this->purge->order($order);

        return $this->one('orders', 'order '.$label);
    }

    public function destroyTransaction(Transaction $transaction): JsonResponse
    {
        $label = $transaction->reference_number;
        $this->purge->transaction($transaction);

        return $this->one('transactions', 'transaction '.$label);
    }

    public function destroyMember(User $user): JsonResponse
    {
        $label = $user->name ?: $user->email;
        $this->purge->member($user);

        return $this->one('members', 'member '.$label);
    }

    public function destroyRedemption(Redemption $redemption): JsonResponse
    {
        $this->purge->redemption($redemption);

        return $this->one('redemptions', 'redemption #'.$redemption->id);
    }

    private function one(string $resource, string $label): JsonResponse
    {
        AuditLogService::log('deleted', $resource, 'Developer deleted '.$label);

        return response()->json([
            'message' => 'Deleted.',
        ]);
    }

    private function run(string $resource, callable $action): JsonResponse
    {
        $deleted = $action();

        AuditLogService::log(
            'deleted',
            $resource,
            'Developer deleted all '.$resource.' ('.$deleted.' records)',
        );

        return response()->json([
            'message' => $deleted === 0
                ? 'Nothing to delete.'
                : 'Deleted '.$deleted.' '.$resource.'.',
            'deleted' => $deleted,
        ]);
    }
}
