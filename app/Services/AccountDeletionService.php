<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\QrCode;
use App\Models\User;
use App\Models\WalletIdempotencyKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AccountDeletionService
{
    /**
     * Permanently delete or anonymize a member account.
     *
     * Personal data is removed. Order history, wallet ledger entries, and
     * financial transaction records are retained without PII for legal and
     * accounting compliance (up to 7 years).
     */
    public function delete(User $user, string $password): void
    {
        if (str_ends_with((string) $user->email, '@deleted.invalid')) {
            throw ValidationException::withMessages([
                'account' => ['This account has already been deleted.'],
            ]);
        }

        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Password is incorrect.'],
            ]);
        }

        DB::transaction(function () use ($user): void {
            $this->deleteAvatar($user);
            $this->deletePersonalRecords($user);
            $this->closeWallet($user);
            $this->revokeAccess($user);
            $this->anonymizeUser($user);
        });
    }

    private function deleteAvatar(User $user): void
    {
        if (! $user->avatar_path) {
            return;
        }

        if (Storage::disk('public')->exists($user->avatar_path)) {
            Storage::disk('public')->delete($user->avatar_path);
        }
    }

    private function deletePersonalRecords(User $user): void
    {
        Notification::query()
            ->where('user_id', $user->id)
            ->delete();

        $user->loyaltyPoints()->delete();
        $user->redemptions()->delete();

        WalletIdempotencyKey::query()
            ->where('user_id', $user->id)
            ->delete();

        QrCode::query()
            ->where('qrable_type', User::class)
            ->where('qrable_id', $user->id)
            ->update([
                'is_active' => false,
                'expires_at' => now(),
            ]);
    }

    private function closeWallet(User $user): void
    {
        $wallet = $user->wallet;

        if (! $wallet) {
            return;
        }

        $wallet->update([
            'current_balance'  => 0,
            'status'           => 'closed',
            'last_activity_at' => now(),
        ]);
    }

    private function revokeAccess(User $user): void
    {
        $user->tokens()->delete();

        DB::table('sessions')
            ->where('user_id', $user->id)
            ->delete();
    }

    private function anonymizeUser(User $user): void
    {
        $user->forceFill([
            'name'              => 'Deleted User',
            'email'             => 'deleted_'.$user->id.'_'.time().'@deleted.invalid',
            'phone'             => null,
            'avatar_path'       => null,
            'fcm_token'         => null,
            'points'            => 0,
            'loyalty_tier_id'   => null,
            'remember_token'    => null,
            'email_verified_at' => null,
            'password'          => Hash::make(bin2hex(random_bytes(32))),
        ])->save();
    }
}
