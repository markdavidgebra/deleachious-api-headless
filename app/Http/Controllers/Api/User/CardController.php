<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\DaleachiousCardTransaction;
use App\Models\User;
use App\Services\DaleachiousCardService;
use Illuminate\Http\Request;

class CardController extends Controller
{
    public function __construct(private readonly DaleachiousCardService $cards)
    {
    }

    public function show(Request $request)
    {
        $user = $this->member($request);
        $card = $this->cards->getOrCreateCard($user);
        $limits = $this->cards->topUpLimits();

        return response()->json([
            'balance' => (float) $card->balance,
            'formatted_balance' => $this->cards->formatPeso($card->balance),
            'status' => $card->status,
            'topup' => $limits,
        ]);
    }

    public function storeTopUp(Request $request)
    {
        $user = $this->member($request);

        $limits = $this->cards->topUpLimits();
        $request->validate([
            'amount' => ['required', 'numeric', 'min:'.$limits['minimum'], 'max:'.$limits['maximum']],
            'payment_method' => ['nullable', 'string', 'max:40'],
        ]);

        $txn = $this->cards->createTopUp(
            $user,
            $request->input('amount'),
            $request->input('payment_method')
        );

        return response()->json([
            'reference' => $txn->reference,
            'amount' => (float) $txn->amount,
            'status' => $txn->status,
        ], 201);
    }

    public function transactions(Request $request)
    {
        $user = $this->member($request);
        $this->cards->getOrCreateCard($user);

        $rows = DaleachiousCardTransaction::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (DaleachiousCardTransaction $txn) => [
                'type' => $txn->type,
                'amount' => (float) $txn->amount,
                'formatted_amount' => $this->cards->formatPeso($txn->amount),
                'status' => $txn->status,
                'reference' => $txn->reference,
                'description' => $txn->description,
                'created_at' => optional($txn->created_at)?->toIso8601String(),
            ])
            ->values();

        return response()->json($rows);
    }

    private function member(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403, 'Unauthorized');
        }

        return $user;
    }
}
