<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    // GET all audit logs
    public function index(Request $request)
    {
        $logs = AuditLog::with(['admin', 'branch'])
            ->when($request->module,   fn($q) => $q->where('module',   $request->module))
            ->when($request->action,   fn($q) => $q->where('action',   $request->action))
            ->when($request->admin_id, fn($q) => $q->where('admin_id', $request->admin_id))
            ->when($request->branch_id,fn($q) => $q->where('branch_id',$request->branch_id))
            ->when($request->from,     fn($q) => $q->whereDate('created_at', '>=', $request->from))
            ->when($request->to,       fn($q) => $q->whereDate('created_at', '<=', $request->to))
            ->orderByDesc('created_at')
            ->paginate($request->per_page ?? 50);

        return response()->json($logs);
    }

    // GET single audit log
    public function show(AuditLog $auditLog)
    {
        return response()->json(
            $auditLog->load(['admin', 'branch'])
        );
    }

    // GET summary — what modules are most active
    public function summary(Request $request)
    {
        $request->validate([
            'from' => 'nullable|date',
            'to'   => 'nullable|date',
        ]);

        $query = AuditLog::query()
            ->when($request->from, fn($q) => $q->whereDate('created_at', '>=', $request->from))
            ->when($request->to,   fn($q) => $q->whereDate('created_at', '<=', $request->to));

        return response()->json([
            'total_logs'     => $query->count(),
            'by_module'      => (clone $query)
                ->selectRaw('module, COUNT(*) as count')
                ->groupBy('module')
                ->orderByDesc('count')
                ->get(),
            'by_action'      => (clone $query)
                ->selectRaw('action, COUNT(*) as count')
                ->groupBy('action')
                ->orderByDesc('count')
                ->get(),
            'by_admin'       => (clone $query)
                ->selectRaw('admin_id, COUNT(*) as count')
                ->groupBy('admin_id')
                ->with('admin:id,name')
                ->orderByDesc('count')
                ->get(),
            'recent'         => (clone $query)
                ->with(['admin', 'branch'])
                ->orderByDesc('created_at')
                ->limit(10)
                ->get(),
        ]);
    }

    // GET logs for a specific admin
    public function byAdmin(Request $request, $adminId)
    {
        $logs = AuditLog::with(['admin', 'branch'])
            ->where('admin_id', $adminId)
            ->orderByDesc('created_at')
            ->paginate($request->per_page ?? 50);

        return response()->json($logs);
    }

    // DELETE old logs (cleanup)
    public function cleanup(Request $request)
    {
        $request->validate([
            'older_than_days' => 'required|integer|min:30',
        ]);

        $cutoff  = now()->subDays($request->older_than_days);
        $deleted = AuditLog::where('created_at', '<', $cutoff)->delete();

        return response()->json([
            'message'       => 'Old audit logs cleaned up',
            'deleted_count' => $deleted,
            'cutoff_date'   => $cutoff->toDateString(),
        ]);
    }
}