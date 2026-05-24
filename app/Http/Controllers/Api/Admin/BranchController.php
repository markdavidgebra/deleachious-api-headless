<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    // GET all branches
    public function index()
    {
        $branches = Branch::withCount(['staff', 'orders'])
            ->orderBy('name')
            ->get();

        return response()->json($branches);
    }

    // CREATE branch
    public function store(Request $request)
    {
        $request->validate([
            'name'         => 'required|string|unique:branches',
            'code'         => 'required|string|unique:branches',
            'address'      => 'required|string',
            'city'         => 'required|string',
            'phone'        => 'nullable|string',
            'email'        => 'nullable|email',
            'opening_time' => 'nullable|date_format:H:i',
            'closing_time' => 'nullable|date_format:H:i',
            'is_active'    => 'boolean',
            'latitude'     => 'nullable|numeric',
            'longitude'    => 'nullable|numeric',
        ]);

        $branch = Branch::create($request->all());

        return response()->json($branch, 201);
    }

    // GET single branch
    public function show(Branch $branch)
    {
        return response()->json(
            $branch->load(['staff', 'orders'])
        );
    }

    // UPDATE branch
    public function update(Request $request, Branch $branch)
    {
        $request->validate([
            'name'         => 'sometimes|string|unique:branches,name,' . $branch->id,
            'code'         => 'sometimes|string|unique:branches,code,' . $branch->id,
            'address'      => 'sometimes|string',
            'city'         => 'sometimes|string',
            'phone'        => 'nullable|string',
            'email'        => 'nullable|email',
            'opening_time' => 'nullable|date_format:H:i',
            'closing_time' => 'nullable|date_format:H:i',
            'is_active'    => 'boolean',
            'latitude'     => 'nullable|numeric',
            'longitude'    => 'nullable|numeric',
        ]);

        $branch->update($request->all());

        return response()->json($branch);
    }

    // DELETE branch
    public function destroy(Branch $branch)
    {
        // Check if branch has staff
        if ($branch->staff()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete branch — it still has staff assigned to it.',
            ], 422);
        }

        $branch->delete();

        return response()->json(['message' => 'Branch deleted successfully']);
    }

    // GET branch stats
    public function stats(Branch $branch)
    {
        return response()->json([
            'branch'         => $branch->only(['id', 'name', 'code', 'city']),
            'total_staff'    => $branch->staff()->count(),
            'total_orders'   => $branch->orders()->count(),
            'completed_orders' => $branch->orders()->where('status', 'completed')->count(),
            'total_sales'    => $branch->orders()
                ->whereHas('transaction', fn($q) => $q->where('status', 'paid'))
                ->with('transaction')
                ->get()
                ->sum(fn($o) => $o->transaction?->amount ?? 0),
        ]);
    }
}