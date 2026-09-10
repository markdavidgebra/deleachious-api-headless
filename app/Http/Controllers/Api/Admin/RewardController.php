<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PointItem;
use App\Support\AdminPaginator;
use Illuminate\Http\Request;

class RewardController extends Controller
{
    public function index(Request $request)
    {
        $query = PointItem::query()->orderBy('points_required');

        if (AdminPaginator::requested($request)) {
            return response()->json(
                $query->paginate(AdminPaginator::perPage($request))->withQueryString()
            );
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'             => 'required|string',
            'description'      => 'nullable|string',
            'points_required'  => 'required|integer|min:1',
            'type'             => 'required|in:free_item,discount,voucher',
            'discount_value'   => 'nullable|numeric|min:0',
            'is_active'        => 'boolean',
            'expires_at'       => 'nullable|date',
        ]);

        $item = PointItem::create($request->all());

        return response()->json($item, 201);
    }

    public function show(PointItem $pointItem)
    {
        return response()->json($pointItem->load('redemptions'));
    }

    public function update(Request $request, PointItem $pointItem)
    {
        $request->validate([
            'name'            => 'sometimes|string',
            'points_required' => 'sometimes|integer|min:1',
            'type'            => 'sometimes|in:free_item,discount,voucher',
            'discount_value'  => 'nullable|numeric|min:0',
            'is_active'       => 'boolean',
            'expires_at'      => 'nullable|date',
        ]);

        $pointItem->update($request->all());

        return response()->json($pointItem);
    }

    public function destroy(PointItem $pointItem)
    {
        $pointItem->delete();

        return response()->json(['message' => 'Point item deleted']);
    }
}
