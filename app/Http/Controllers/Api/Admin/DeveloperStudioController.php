<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\PaymentException;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Services\OrderPaymentSettlementService;
use App\Support\AdminPaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DeveloperStudioController extends Controller
{
    private const STUCK_AFTER_MINUTES = 10;

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
                'orders'       => Order::query()->count(),
                'products'     => Product::query()->count(),
                'staff'        => Admin::query()->notConcealed()->count(),
                'super_admins' => Admin::query()->where('role', 'super_admin')->count(),
            ],
            'payments' => [
                'pending'      => Transaction::query()->where('status', 'pending')->count(),
                'stuck'        => $this->stuckQuery()->count(),
                'paid'         => Transaction::query()->where('status', 'paid')->count(),
                'failed'       => $this->failedQuery()->count(),
                'last_paid_at' => Transaction::query()
                    ->where('status', 'paid')
                    ->latest('paid_at')
                    ->value('paid_at'),
            ],
            'queue' => $this->queueSnapshot(),
            'wiring' => [
                'app_url'           => config('app.url'),
                'mail'              => config('mail.default'),
                'queue'             => config('queue.default'),
                'cache'             => config('cache.default'),
                'paymongo_public'   => filled(config('services.paymongo.public_key')),
                'paymongo_secret'   => filled(config('services.paymongo.secret_key')),
                'paymongo_webhook'  => filled(config('services.paymongo.webhook_secret')),
            ],
            'accounts' => $accounts,
        ]);
    }

    public function payments(Request $request)
    {
        $tab = $request->string('tab')->toString() === 'failed' ? 'failed' : 'stuck';
        $query = $tab === 'failed' ? $this->failedQuery() : $this->stuckQuery();

        $paginator = $query
            ->with(['order:id,order_number,status', 'user:id,name,email'])
            ->latest()
            ->paginate(AdminPaginator::perPage($request))
            ->withQueryString();

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (Transaction $tx) => $this->serializePayment($tx))
        );

        $payload = $paginator->toArray();
        $payload['stats'] = [
            'stuck'  => $this->stuckQuery()->count(),
            'failed' => $this->failedQuery()->count(),
        ];

        return response()->json($payload);
    }

    public function recheck(Transaction $transaction, OrderPaymentSettlementService $settlement)
    {
        if (! $transaction->gateway_checkout_id) {
            return response()->json([
                'message' => 'This payment did not go through PayMongo.',
            ], 422);
        }

        $transaction->load(['order:id,order_number,status', 'user:id,name,email']);

        if ($transaction->status === 'paid') {
            return response()->json([
                'message'     => 'Already paid.',
                'paid'        => true,
                'transaction' => $this->serializePayment($transaction),
            ]);
        }

        try {
            $result = $settlement->settleFromGateway($transaction);
        } catch (PaymentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->statusCode);
        }

        $fresh = $result['transaction']->load(['order:id,order_number,status', 'user:id,name,email']);

        $message = $result['paid']
            ? 'PayMongo confirms this is paid. The order is confirmed.'
            : 'PayMongo still shows unpaid. The member may have closed checkout.';

        return response()->json([
            'message'     => $message,
            'paid'        => (bool) $result['paid'],
            'transaction' => $this->serializePayment($fresh),
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

    private function stuckQuery()
    {
        return Transaction::query()
            ->where('status', 'pending')
            ->whereNotNull('gateway_checkout_id')
            ->where('created_at', '<=', now()->subMinutes(self::STUCK_AFTER_MINUTES));
    }

    private function failedQuery()
    {
        return Transaction::query()
            ->where('status', 'failed')
            ->whereNotNull('gateway_checkout_id');
    }

    private function serializePayment(Transaction $tx): array
    {
        $stuck = $tx->status === 'pending';

        return [
            'id'                   => $tx->id,
            'reference_number'     => $tx->reference_number,
            'amount'               => $tx->amount,
            'payment_method'       => $tx->payment_method,
            'status'               => $tx->status,
            'gateway'              => $tx->gateway,
            'gateway_checkout_id'  => $tx->gateway_checkout_id,
            'created_at'           => $tx->created_at?->toIso8601String(),
            'age_minutes'          => (int) ($tx->created_at?->diffInMinutes(now()) ?? 0),
            'issue'                => $stuck ? 'stuck' : 'failed',
            'why'                  => $stuck
                ? 'Still pending after '.self::STUCK_AFTER_MINUTES.' minutes. The webhook may have missed, or the member closed PayMongo.'
                : 'PayMongo reported a failed or expired payment, or the amount did not match.',
            'order' => $tx->order ? [
                'id'           => $tx->order->id,
                'order_number' => $tx->order->order_number,
                'status'       => $tx->order->status,
            ] : null,
            'user' => $tx->user ? [
                'name'  => $tx->user->name,
                'email' => $tx->user->email,
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
            'failed'  => Schema::hasTable('failed_jobs')
                ? (int) DB::table('failed_jobs')->count()
                : 0,
            'recent'  => $recent,
        ];
    }
}
