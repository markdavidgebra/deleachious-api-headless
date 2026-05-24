<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\Order;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    // GET all transactions
    public function index(Request $request)
    {
        $transactions = Transaction::with(['order', 'user'])
            ->when($request->status,         fn($q) => $q->where('status',         $request->status))
            ->when($request->payment_method, fn($q) => $q->where('payment_method', $request->payment_method))
            ->orderByDesc('created_at')
            ->get();

        return response()->json($transactions);
    }

    // GET single transaction
    public function show(Transaction $transaction)
    {
        return response()->json(
            $transaction->load(['order.items.addons', 'user'])
        );
    }

    // CREATE payment for an order
    public function store(Request $request)
    {
        $request->validate([
            'order_id'       => 'required|exists:orders,id',
            'payment_method' => 'required|in:cash,gcash,maya,card,points',
            'amount'         => 'required|numeric|min:0',
            'change'         => 'nullable|numeric|min:0',
            'proof'          => 'nullable|string',
        ]);

        $order = Order::findOrFail($request->order_id);

        // Check if already paid
        if ($order->transaction && $order->transaction->status === 'paid') {
            return response()->json([
                'message' => 'This order is already paid.',
            ], 422);
        }

        $transaction = Transaction::create([
            'reference_number' => Transaction::generateReferenceNumber(),
            'order_id'         => $order->id,
            'user_id'          => $order->user_id,
            'payment_method'   => $request->payment_method,
            'status'           => 'paid',
            'amount'           => $request->amount,
            'change'           => $request->change ?? 0,
            'proof'            => $request->proof,
            'paid_at'          => now(),
        ]);

        // Auto confirm order after payment
        $order->update(['status' => 'confirmed']);

        return response()->json([
            'message'     => 'Payment recorded successfully',
            'transaction' => $transaction->load(['order', 'user']),
        ], 201);
    }

    // REFUND a transaction
    public function refund(Transaction $transaction)
    {
        if ($transaction->status === 'refunded') {
            return response()->json([
                'message' => 'Transaction already refunded.',
            ], 422);
        }

        $transaction->update(['status' => 'refunded']);
        $transaction->order->update(['status' => 'cancelled']);

        return response()->json([
            'message'     => 'Transaction refunded successfully',
            'transaction' => $transaction->load(['order', 'user']),
        ]);
    }

    // GET summary totals
    public function summary(Request $request)
    {
        $request->validate([
            'from' => 'nullable|date',
            'to'   => 'nullable|date',
        ]);

        $query = Transaction::where('status', 'paid')
            ->when($request->from, fn($q) => $q->whereDate('paid_at', '>=', $request->from))
            ->when($request->to,   fn($q) => $q->whereDate('paid_at', '<=', $request->to));

        return response()->json([
            'total_sales'        => $query->sum('amount'),
            'total_transactions' => $query->count(),
            'by_payment_method'  => $query->groupBy('payment_method')
                ->selectRaw('payment_method, SUM(amount) as total, COUNT(*) as count')
                ->get(),
        ]);
    }
}