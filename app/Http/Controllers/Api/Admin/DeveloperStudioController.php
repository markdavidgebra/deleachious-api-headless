<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\PointItem;
use App\Models\User;
use App\Support\AdminPaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DeveloperStudioController extends Controller
{
    public function show()
    {
        $lastLogins = AuditLog::query()
            ->selectRaw('admin_id, max(created_at) as last_login_at')
            ->where('action', 'login')
            ->whereNotNull('admin_id')
            ->groupBy('admin_id')
            ->pluck('last_login_at', 'admin_id');

        $accounts = Admin::query()
            ->where('role', '!=', 'developer')
            ->orderByRaw("case when role = 'super_admin' then 0 else 1 end")
            ->orderBy('name')
            ->get()
            ->map(fn (Admin $admin) => [
                'id'            => $admin->id,
                'name'          => $admin->name,
                'email'         => $admin->email,
                'role'          => $admin->role,
                'role_label'    => $admin->role === 'super_admin' ? 'Super Admin' : str_replace('_', ' ', (string) $admin->role),
                'is_active'     => $admin->is_active,
                'last_login_at' => $lastLogins[$admin->id] ?? null,
            ]);

        return response()->json([
            'system' => [
                'app'       => config('app.name'),
                'env'       => app()->environment(),
                'debug'     => (bool) config('app.debug'),
                'php'       => PHP_VERSION,
                'laravel'   => app()->version(),
                'timezone'  => config('app.timezone'),
            ],
            'counts' => [
                'members'      => User::query()->count(),
                'points'       => PointItem::query()->count(),
                'rewards'      => PointItem::query()->count(),
                'staff'        => Admin::query()->notConcealed()->count(),
                'super_admins' => Admin::query()->where('role', 'super_admin')->count(),
            ],
            'queue' => $this->queueSnapshot(),
            'wiring' => [
                'app_url' => config('app.url'),
                'mail'    => config('mail.default'),
                'queue'   => config('queue.default'),
                'cache'   => config('cache.default'),
            ],
            'accounts' => $accounts,
        ]);
    }

    public function activity(Request $request)
    {
        $query = AuditLog::query()
            ->with(['admin:id,name,email,role'])
            ->latest();

        $paginator = $query->paginate(AdminPaginator::perPage($request))->withQueryString();
        $paginator->setCollection(
            $paginator->getCollection()->map(fn (AuditLog $log) => $this->serializeLog($log))
        );

        return response()->json($paginator);
    }

    private function serializeLog(AuditLog $log): array
    {
        $actor = $log->admin;

        return [
            'id'          => $log->id,
            'action'      => $log->action,
            'module'      => $log->module,
            'description' => $log->description,
            'ip_address'  => $log->ip_address,
            'created_at'  => $log->created_at?->toIso8601String(),
            'actor'       => $actor ? [
                'name'  => $actor->name,
                'email' => $actor->isDeveloper() ? null : $actor->email,
                'role'  => $actor->isDeveloper() ? 'studio' : $actor->role,
            ] : null,
        ];
    }

    private function queueSnapshot(): array
    {
        $recent = [];

        if (Schema::hasTable('failed_jobs')) {
            $recent = DB::table('failed_jobs')
                ->orderByDesc('failed_at')
                ->limit(8)
                ->get(['id', 'queue', 'failed_at', 'exception'])
                ->map(function ($row) {
                    $line = strtok((string) $row->exception, "\n") ?: 'Job failed';

                    return [
                        'id'        => $row->id,
                        'queue'     => $row->queue,
                        'failed_at' => $row->failed_at,
                        'error'     => mb_substr($line, 0, 180),
                    ];
                })
                ->all();
        }

        return [
            'failed' => Schema::hasTable('failed_jobs')
                ? (int) DB::table('failed_jobs')->count()
                : 0,
            'recent' => $recent,
        ];
    }
}
