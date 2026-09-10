<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DaleachiousCardTransaction;
use App\Services\DaleachiousCardService;
use App\Support\AdminPaginator;
use Illuminate\Http\Request;

class CardTopUpController extends Controller
{
    public function __construct(private readonly DaleachiousCardService $cards)
    {
    }

    public function index(Request $request)
    {
        $query = DaleachiousCardTransaction::query()
            ->with(['user', 'card'])
            ->where('type', DaleachiousCardTransaction::TYPE_TOPUP)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->search.'%';
                $q->where(function ($inner) use ($term) {
                    $inner->where('reference', 'like', $term)
                        ->orWhereHas('user', function ($user) use ($term) {
                            $user->where('name', 'like', $term)
                                ->orWhere('email', 'like', $term);
                        });
                });
            })
            ->orderByDesc('id');

        $stats = [
            'total' => DaleachiousCardTransaction::query()
                ->where('type', DaleachiousCardTransaction::TYPE_TOPUP)
                ->count(),
            'pending' => DaleachiousCardTransaction::query()
                ->where('type', DaleachiousCardTransaction::TYPE_TOPUP)
                ->where('status', DaleachiousCardTransaction::STATUS_PENDING)
                ->count(),
            'completed' => DaleachiousCardTransaction::query()
                ->where('type', DaleachiousCardTransaction::TYPE_TOPUP)
                ->where('status', DaleachiousCardTransaction::STATUS_COMPLETED)
                ->count(),
            'failed' => DaleachiousCardTransaction::query()
                ->where('type', DaleachiousCardTransaction::TYPE_TOPUP)
                ->where('status', DaleachiousCardTransaction::STATUS_FAILED)
                ->count(),
        ];

        if (AdminPaginator::requested($request)) {
            $paginator = $query->paginate(AdminPaginator::perPage($request))->withQueryString();
            $paginator->setCollection(
                $paginator->getCollection()->map(
                    fn (DaleachiousCardTransaction $txn) => $this->cards->presentTransaction($txn)
                )
            );
            $payload = $paginator->toArray();
            $payload['stats'] = $stats;

            return response()->json($payload);
        }

        return response()->json([
            'data' => $query->get()->map(
                fn (DaleachiousCardTransaction $txn) => $this->cards->presentTransaction($txn)
            )->values(),
            'stats' => $stats,
        ]);
    }

    public function show(string $reference)
    {
        $txn = DaleachiousCardTransaction::query()
            ->with(['user', 'card'])
            ->where('reference', $reference)
            ->first();

        if (! $txn) {
            abort(404, 'Top-up not found.');
        }

        return response()->json($this->cards->presentTransaction($txn));
    }

    public function complete(string $reference)
    {
        $txn = $this->cards->completeTopUp($reference)->load(['user', 'card']);

        return response()->json($this->cards->presentTransaction($txn));
    }

    public function fail(string $reference)
    {
        $txn = $this->cards->failTopUp($reference)->load(['user', 'card']);

        return response()->json($this->cards->presentTransaction($txn));
    }
}
