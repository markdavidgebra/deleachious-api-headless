<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Branch;
use App\Models\DaleachiousCardTransaction;
use App\Models\LoyaltyPoint;
use App\Models\QrCode;
use App\Models\User;
use App\Services\DaleachiousCardService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PurchaseEarnPointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_cash_purchase_earns_one_point_per_fifty_and_does_not_touch_card(): void
    {
        [$user, $code] = $this->memberWithQr();
        $admin = $this->superAdmin();
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/qr/scan', [
            'code' => $code,
            'action' => 'earn_points',
            'payment_method' => 'cash',
            'amount' => 100,
        ])
            ->assertOk()
            ->assertJsonPath('result', 'success')
            ->assertJsonPath('points_earned', 2)
            ->assertJsonPath('total_points', 2)
            ->assertJsonPath('card_charged', false)
            ->assertJsonPath('card_balance', 0)
            ->assertJsonPath('branch.id', $admin->branch_id)
            ->assertJsonPath('branch.name', 'Test Branch')
            ->assertJsonPath('scanned_by.id', $admin->id)
            ->assertJsonPath('scanned_by.role', 'super_admin');

        $this->assertSame(2, $user->fresh()->points);
        $this->assertSame('0.00', $this->money($user->fresh()->daleachiousCard->balance));
        $this->assertSame(0, DaleachiousCardTransaction::query()->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('loyalty_points', [
            'user_id' => $user->id,
            'points' => 2,
            'type' => 'earned',
        ]);
        $this->assertDatabaseHas('qr_scans', [
            'scanned_by' => $admin->id,
            'branch_id' => $admin->branch_id,
            'action' => 'earn_points',
            'result' => 'success',
        ]);
    }

    public function test_card_purchase_debits_balance_and_earns_one_point_per_twenty_five(): void
    {
        [$user, $code] = $this->memberWithQr();
        app(DaleachiousCardService::class)->topUpInStore($user, 500);

        $admin = $this->superAdmin();
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/qr/scan', [
            'code' => $code,
            'action' => 'earn_points',
            'payment_method' => 'daleachious_card',
            'amount' => 100,
        ])
            ->assertOk()
            ->assertJsonPath('result', 'success')
            ->assertJsonPath('points_earned', 4)
            ->assertJsonPath('total_points', 4)
            ->assertJsonPath('card_charged', true)
            ->assertJsonPath('card_balance', 400);

        $this->assertSame(4, $user->fresh()->points);
        $this->assertSame('400.00', $this->money($user->fresh()->daleachiousCard->balance));
        $this->assertDatabaseHas('daleachious_card_transactions', [
            'user_id' => $user->id,
            'type' => DaleachiousCardTransaction::TYPE_PURCHASE,
            'status' => 'completed',
            'amount' => '100.00',
        ]);
    }

    public function test_card_purchase_fails_when_balance_is_short_and_awards_no_points(): void
    {
        [$user, $code] = $this->memberWithQr();
        app(DaleachiousCardService::class)->topUpInStore($user, 100);

        $admin = $this->superAdmin();
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/qr/scan', [
            'code' => $code,
            'action' => 'earn_points',
            'payment_method' => 'daleachious_card',
            'amount' => 200,
        ])
            ->assertOk()
            ->assertJsonPath('result', 'failed');

        $this->assertSame(0, $user->fresh()->points);
        $this->assertSame('100.00', $this->money($user->fresh()->daleachiousCard->balance));
        $this->assertSame(0, LoyaltyPoint::query()->where('user_id', $user->id)->count());
        $this->assertSame(
            0,
            DaleachiousCardTransaction::query()
                ->where('user_id', $user->id)
                ->where('type', DaleachiousCardTransaction::TYPE_PURCHASE)
                ->count()
        );
    }

    public function test_card_reload_does_not_earn_points(): void
    {
        [$user, $code] = $this->memberWithQr();
        $admin = $this->superAdmin();
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/qr/scan', [
            'code' => $code,
            'action' => 'topup_card',
            'amount' => 500,
        ])
            ->assertOk()
            ->assertJsonPath('result', 'success')
            ->assertJsonPath('card_balance', 500);

        $this->assertSame(0, $user->fresh()->points);
        $this->assertSame(0, LoyaltyPoint::query()->where('user_id', $user->id)->count());
    }

    public function test_earn_requires_payment_method_and_amount(): void
    {
        [$user, $code] = $this->memberWithQr();
        $admin = $this->superAdmin();
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/qr/scan', [
            'code' => $code,
            'action' => 'earn_points',
            'amount' => 100,
        ])->assertOk()->assertJsonPath('result', 'failed');

        $this->postJson('/api/admin/qr/scan', [
            'code' => $code,
            'action' => 'earn_points',
            'payment_method' => 'gcash',
        ])->assertOk()->assertJsonPath('result', 'failed');

        $this->assertSame(0, $user->fresh()->points);
    }

    public function test_qr_lookup_returns_member_without_earning_points(): void
    {
        [$user, $code] = $this->memberWithQr();
        $admin = $this->superAdmin();
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/qr/lookup?code='.$code)
            ->assertOk()
            ->assertJsonPath('result', 'success')
            ->assertJsonPath('qr_type', 'member')
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('card_balance', 0);

        $this->assertSame(0, $user->fresh()->points);
        $this->assertSame(0, LoyaltyPoint::query()->where('user_id', $user->id)->count());
    }

    public function test_qr_lookup_rejects_invalid_code(): void
    {
        $admin = $this->superAdmin();
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/qr/lookup?code=NOTVALID1234')
            ->assertNotFound()
            ->assertJsonPath('result', 'failed');
    }

    public function test_scan_requires_a_branch_before_any_transaction_can_succeed(): void
    {
        [, $code] = $this->memberWithQr();
        $otherBranch = Branch::query()->create([
            'name' => 'Unrelated Branch',
            'code' => 'UNRELATED',
            'address' => 'Test address',
            'city' => 'Test city',
        ]);
        $admin = Admin::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
            'branch_id' => null,
        ]);
        $admin->syncNamedRole('super_admin');
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/qr/scan', [
            'code' => $code,
            'action' => 'earn_points',
            'payment_method' => 'cash',
            'amount' => 100,
            'branch_id' => $otherBranch->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');
    }

    public function test_staff_override_cannot_change_earn_rate(): void
    {
        [$user, $code] = $this->memberWithQr();
        $admin = $this->superAdmin();
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/qr/scan', [
            'code' => $code,
            'action' => 'earn_points',
            'payment_method' => 'maya',
            'amount' => 100,
            'points' => 99,
        ])
            ->assertOk()
            ->assertJsonPath('result', 'success')
            ->assertJsonPath('points_earned', 2);

        $this->assertSame(2, $user->fresh()->points);
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function memberWithQr(): array
    {
        $user = User::factory()->create(['points' => 0]);
        Sanctum::actingAs($user);
        $code = $this->getJson('/api/user/loyalty-qr')->assertOk()->json('qr_code.code');
        $this->assertNotEmpty($code);
        $this->assertSame(1, QrCode::query()->where('qrable_id', $user->id)->where('type', 'user')->count());

        return [$user, $code];
    }

    private function superAdmin(): Admin
    {
        $branch = Branch::query()->create([
            'name' => 'Test Branch',
            'code' => 'TEST-'.uniqid(),
            'address' => 'Test address',
            'city' => 'Test city',
        ]);
        $admin = Admin::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);
        $admin->syncNamedRole('super_admin');

        return $admin;
    }

    private function money(float|int|string $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
