<?php

namespace App\Services;

use App\Models\Order;
use App\Models\QrCode;
use App\Models\QrScan;
use App\Models\Redemption;
use App\Models\Reward;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DeveloperPurgeService
{
    public function order(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $this->deleteMorphQr(Order::class, $order->id);
            $this->nullMorphReferences(Order::class, $order->id);
            $order->delete();
        });
    }

    public function transaction(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction) {
            $this->nullMorphReferences(Transaction::class, $transaction->id);
            $transaction->delete();
        });
    }

    public function redemption(Redemption $redemption): void
    {
        DB::transaction(function () use ($redemption) {
            $this->nullMorphReferences(Redemption::class, $redemption->id);
            $redemption->delete();
        });
    }

    public function member(User $user): void
    {
        DB::transaction(function () use ($user) {
            $this->deleteMorphQr(User::class, $user->id);
            $this->wipeMemberSideRecords($user->id);
            $user->delete();
        });
    }

    public function orders(): int
    {
        return DB::transaction(function () {
            $this->deleteMorphQr(Order::class);
            $this->nullMorphReferences(Order::class);

            $count = Order::query()->count();
            Order::query()->delete();

            return $count;
        });
    }

    public function transactions(): int
    {
        return DB::transaction(function () {
            $this->nullMorphReferences(Transaction::class);

            $count = Transaction::query()->count();
            Transaction::query()->delete();

            return $count;
        });
    }

    public function redemptions(): int
    {
        return DB::transaction(function () {
            $this->nullMorphReferences(Redemption::class);

            $count = Redemption::query()->count();
            Redemption::query()->delete();

            return $count;
        });
    }

    public function members(): int
    {
        return DB::transaction(function () {
            $this->deleteMorphQr(User::class);
            $this->wipeTables([
                'refunds',
                'purchases',
                'topups',
                'wallet_transactions',
                'wallet_idempotency_keys',
                'wallets',
            ]);

            if (Schema::hasTable('personal_access_tokens')) {
                DB::table('personal_access_tokens')
                    ->where('tokenable_type', User::class)
                    ->delete();
            }

            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->whereNotNull('user_id')->delete();
            }

            $count = User::query()->count();
            User::query()->delete();

            return $count;
        });
    }

    public function rewards(): int
    {
        return DB::transaction(function () {
            $this->nullMorphReferences(Reward::class);

            $count = Reward::query()->count();
            Reward::query()->delete();

            return $count;
        });
    }

    private function deleteMorphQr(string $type, ?int $id = null): void
    {
        $query = QrCode::query()->where('qrable_type', $type);
        if ($id !== null) {
            $query->where('qrable_id', $id);
        }

        $ids = $query->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        QrScan::query()->whereIn('qr_code_id', $ids)->delete();
        QrCode::query()->whereIn('id', $ids)->delete();
    }

    private function nullMorphReferences(string $type, ?int $id = null): void
    {
        if (! Schema::hasTable('loyalty_points')) {
            return;
        }

        $query = DB::table('loyalty_points')->where('reference_type', $type);
        if ($id !== null) {
            $query->where('reference_id', $id);
        }

        $query->update([
            'reference_type' => null,
            'reference_id'   => null,
        ]);
    }

    private function wipeMemberSideRecords(int $userId): void
    {
        $walletIds = Schema::hasTable('wallets')
            ? DB::table('wallets')->where('user_id', $userId)->pluck('id')
            : collect();

        foreach (['refunds', 'purchases', 'topups'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->where('user_id', $userId)->delete();
            }
        }

        if (Schema::hasTable('wallet_transactions') && $walletIds->isNotEmpty()) {
            DB::table('wallet_transactions')->whereIn('wallet_id', $walletIds)->delete();
        }

        if (Schema::hasTable('wallet_idempotency_keys')) {
            DB::table('wallet_idempotency_keys')->where('user_id', $userId)->delete();
        }

        if (Schema::hasTable('wallets')) {
            DB::table('wallets')->where('user_id', $userId)->delete();
        }

        if (Schema::hasTable('personal_access_tokens')) {
            DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->where('tokenable_id', $userId)
                ->delete();
        }

        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $userId)->delete();
        }
    }

    /**
     * @param  list<string>  $tables
     */
    private function wipeTables(array $tables): void
    {
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
    }
}
