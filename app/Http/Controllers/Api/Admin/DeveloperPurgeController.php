<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Redemption;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\DeveloperPurgeService;
use Illuminate\Http\JsonResponse;

class DeveloperPurgeController extends Controller
{
    public function __construct(
        protected DeveloperPurgeService $purge,
    ) {}

    public function members(): JsonResponse
    {
        return $this->run('members', fn () => $this->purge->members());
    }

    public function redemptions(): JsonResponse
    {
        return $this->run('redemptions', fn () => $this->purge->redemptions());
    }

    public function points(): JsonResponse
    {
        return $this->run('points', fn () => $this->purge->points());
    }

    public function rewards(): JsonResponse
    {
        return $this->points();
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
