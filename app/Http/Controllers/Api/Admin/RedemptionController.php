<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Redemption;
use App\Services\RewardRedemptionService;
use App\Support\AdminPaginator;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RedemptionController extends Controller
{
    public function __construct(
        protected RewardRedemptionService $redemptions,
    ) {}

    // GET all redemptions
    public function index(Request $request)
    {
        $query = Redemption::with(['user', 'reward'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('created_at');

        $stats = [
            'total'    => Redemption::count(),
            'pending'  => Redemption::where('status', 'pending')->count(),
            'approved' => Redemption::where('status', 'approved')->count(),
            'rejected' => Redemption::where('status', 'rejected')->count(),
        ];

        if (AdminPaginator::requested($request)) {
            $payload = $query->paginate(AdminPaginator::perPage($request))->withQueryString()->toArray();
            $payload['stats'] = $stats;

            return response()->json($payload);
        }

        return response()->json($query->get());
    }

    // APPROVE or REJECT a redemption
    public function updateStatus(Request $request, Redemption $redemption)
    {
        $request->validate([
            'status' => 'required|in:approved,rejected',
        ]);

        try {
            $updated = $request->status === 'approved'
                ? $this->redemptions->approve($redemption)
                : $this->redemptions->reject($redemption);
        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first() ?? 'Unable to update redemption.';

            return response()->json([
                'message' => $first,
                'errors'  => $e->errors(),
            ], 422);
        }

        return response()->json([
            'message'    => 'Redemption '.$request->status,
            'redemption' => $updated,
        ]);
    }
}
