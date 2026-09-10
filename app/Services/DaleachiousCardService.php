<?php

namespace App\Services;

use App\Models\DaleachiousCard;
use App\Models\DaleachiousCardTransaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DaleachiousCardService
{
    public function getOrCreateCard(User $user): DaleachiousCard
    {
        try {
            return DaleachiousCard::query()->firstOrCreate(
                ['user_id' => $user->id],
                [
                    'balance' => '0.00',
                    'status' => DaleachiousCard::STATUS_ACTIVE,
                ]
            );
        } catch (UniqueConstraintViolationException) {
            return DaleachiousCard::query()
                ->where('user_id', $user->id)
                ->firstOrFail();
        } catch (QueryException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }

            return DaleachiousCard::query()
                ->where('user_id', $user->id)
                ->firstOrFail();
        }
    }

    public function createTopUp(User $user, float|int|string $amount, ?string $paymentMethod = null): DaleachiousCardTransaction
    {
        $amount = $this->normalizeAmount($amount);
        $this->assertTopUpAmount($amount);

        $card = $this->getOrCreateCard($user);

        return DaleachiousCardTransaction::query()->create([
            'daleachious_card_id' => $card->id,
            'user_id' => $user->id,
            'type' => DaleachiousCardTransaction::TYPE_TOPUP,
            'amount' => $amount,
            'balance_before' => null,
            'balance_after' => null,
            'status' => DaleachiousCardTransaction::STATUS_PENDING,
            'reference' => $this->newTopUpReference(),
            'payment_method' => $paymentMethod,
            'description' => 'Daleachious Card top-up',
        ]);
    }

    public function completeTopUp(string $reference): DaleachiousCardTransaction
    {
        return DB::transaction(function () use ($reference) {
            $txn = DaleachiousCardTransaction::query()
                ->where('reference', $reference)
                ->lockForUpdate()
                ->first();

            if (! $txn) {
                abort(404, 'Top-up not found.');
            }

            if ($txn->status === DaleachiousCardTransaction::STATUS_COMPLETED) {
                return $txn;
            }

            if (
                $txn->type !== DaleachiousCardTransaction::TYPE_TOPUP
                || $txn->status !== DaleachiousCardTransaction::STATUS_PENDING
            ) {
                throw ValidationException::withMessages([
                    'reference' => 'This top-up cannot be completed.',
                ]);
            }

            $card = DaleachiousCard::query()
                ->where('id', $txn->daleachious_card_id)
                ->lockForUpdate()
                ->firstOrFail();

            $before = $this->money($card->balance);
            $after = bcadd($before, $this->money($txn->amount), 2);

            $card->update(['balance' => $after]);

            $txn->update([
                'status' => DaleachiousCardTransaction::STATUS_COMPLETED,
                'balance_before' => $before,
                'balance_after' => $after,
            ]);

            return $txn->fresh();
        });
    }

    public function topUpInStore(User $user, float|int|string $amount, ?string $paymentMethod = 'in_store'): DaleachiousCardTransaction
    {
        $amount = $this->normalizeAmount($amount);
        $this->assertTopUpAmount($amount);

        return DB::transaction(function () use ($user, $amount, $paymentMethod) {
            $this->getOrCreateCard($user);

            $card = DaleachiousCard::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $before = $this->money($card->balance);
            $after = bcadd($before, $amount, 2);

            $card->update(['balance' => $after]);

            return DaleachiousCardTransaction::query()->create([
                'daleachious_card_id' => $card->id,
                'user_id' => $user->id,
                'type' => DaleachiousCardTransaction::TYPE_TOPUP,
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'status' => DaleachiousCardTransaction::STATUS_COMPLETED,
                'reference' => $this->newTopUpReference(),
                'payment_method' => $paymentMethod ?: 'in_store',
                'description' => 'In-store Daleachious Card top-up',
            ]);
        });
    }

    public function failTopUp(string $reference): DaleachiousCardTransaction
    {
        return DB::transaction(function () use ($reference) {
            $txn = DaleachiousCardTransaction::query()
                ->where('reference', $reference)
                ->lockForUpdate()
                ->first();

            if (! $txn) {
                abort(404, 'Top-up not found.');
            }

            if ($txn->status === DaleachiousCardTransaction::STATUS_COMPLETED) {
                throw ValidationException::withMessages([
                    'reference' => 'A completed top-up cannot be marked failed.',
                ]);
            }

            if ($txn->status === DaleachiousCardTransaction::STATUS_FAILED) {
                return $txn;
            }

            $txn->update(['status' => DaleachiousCardTransaction::STATUS_FAILED]);

            return $txn->fresh();
        });
    }

    public function debitForPurchase(User $user, float|int|string $amount, string $paymentMethod = 'daleachious_card'): DaleachiousCardTransaction
    {
        $amount = $this->normalizeAmount($amount);

        if (bccomp($amount, '0.00', 2) <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Enter the purchase amount.',
            ]);
        }

        return DB::transaction(function () use ($user, $amount, $paymentMethod) {
            $this->getOrCreateCard($user);

            $card = DaleachiousCard::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($card->status !== DaleachiousCard::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'card' => 'This Daleachious Card cannot be used.',
                ]);
            }

            $before = $this->money($card->balance);
            if (bccomp($before, $amount, 2) < 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Not enough Card balance. Available '.$this->formatPeso($before).'.',
                ]);
            }

            $after = bcsub($before, $amount, 2);
            $card->update(['balance' => $after]);

            return DaleachiousCardTransaction::query()->create([
                'daleachious_card_id' => $card->id,
                'user_id' => $user->id,
                'type' => DaleachiousCardTransaction::TYPE_PURCHASE,
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'status' => DaleachiousCardTransaction::STATUS_COMPLETED,
                'reference' => $this->newPurchaseReference(),
                'payment_method' => $paymentMethod ?: 'daleachious_card',
                'description' => 'In-store purchase',
            ]);
        });
    }

    public function formatPeso(float|int|string $amount): string
    {
        return '₱'.number_format((float) $this->money($amount), 2, '.', ',');
    }

    public function presentTransaction(DaleachiousCardTransaction $txn): array
    {
        $card = $txn->relationLoaded('card') ? $txn->card : $txn->card()->first();
        $user = $txn->relationLoaded('user') ? $txn->user : $txn->user()->first();

        return [
            'id' => $txn->id,
            'type' => $txn->type,
            'amount' => (float) $txn->amount,
            'formatted_amount' => $this->formatPeso($txn->amount),
            'status' => $txn->status,
            'reference' => $txn->reference,
            'description' => $txn->description,
            'payment_method' => $txn->payment_method,
            'balance_before' => $txn->balance_before !== null ? (float) $txn->balance_before : null,
            'balance_after' => $txn->balance_after !== null ? (float) $txn->balance_after : null,
            'created_at' => optional($txn->created_at)?->toIso8601String(),
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ] : null,
            'card_balance' => $card ? (float) $card->balance : null,
            'formatted_card_balance' => $card ? $this->formatPeso($card->balance) : null,
        ];
    }

    public function topUpLimits(): array
    {
        return [
            'minimum' => (float) config('daleachious.card.minimum_topup'),
            'maximum' => (float) config('daleachious.card.maximum_topup'),
            'presets' => array_map('floatval', config('daleachious.card.presets', [100, 200, 500, 1000])),
        ];
    }

    private function assertTopUpAmount(string $amount): void
    {
        $min = $this->money(config('daleachious.card.minimum_topup'));
        $max = $this->money(config('daleachious.card.maximum_topup'));

        if (bccomp($amount, $min, 2) < 0) {
            throw ValidationException::withMessages([
                'amount' => 'The minimum top-up is '.$this->formatPeso($min).'.',
            ]);
        }

        if (bccomp($amount, $max, 2) > 0) {
            throw ValidationException::withMessages([
                'amount' => 'The maximum top-up is '.$this->formatPeso($max).'.',
            ]);
        }
    }

    private function normalizeAmount(float|int|string $amount): string
    {
        if (! is_numeric($amount)) {
            throw ValidationException::withMessages([
                'amount' => 'Enter a valid amount.',
            ]);
        }

        return $this->money($amount);
    }

    private function money(float|int|string $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    private function newPurchaseReference(): string
    {
        do {
            $reference = 'PURCHASE-'.now()->format('Ymd').'-'.strtoupper(Str::random(8));
        } while (DaleachiousCardTransaction::query()->where('reference', $reference)->exists());

        return $reference;
    }

    private function newTopUpReference(): string
    {
        do {
            $reference = 'TOPUP-'.now()->format('Ymd').'-'.strtoupper(Str::random(8));
        } while (DaleachiousCardTransaction::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
