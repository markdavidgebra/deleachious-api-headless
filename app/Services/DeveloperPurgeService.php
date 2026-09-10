<?php

namespace App\Services;

use App\Models\QrCode;
use App\Models\QrScan;
use App\Models\Redemption;
use App\Models\PointItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DeveloperPurgeService
{
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

    public function points(): int
    {
        return DB::transaction(function () {
            $this->nullMorphReferences(PointItem::class);
            $this->nullMorphReferences(\App\Models\Reward::class);

            $count = PointItem::query()->count();
            PointItem::query()->delete();

            return $count;
        });
    }

    public function rewards(): int
    {
        return $this->points();
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
}
