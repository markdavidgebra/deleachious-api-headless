<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Branch;
use App\Models\DaleachiousCard;
use App\Models\DaleachiousCardTransaction;
use App\Models\QrCode;
use App\Models\User;
use App\Services\DaleachiousCardService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DaleachiousCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_new_customer_gets_a_daleachious_card_with_zero_balance(): void
    {
        $user = User::factory()->create();

        app(DaleachiousCardService::class)->getOrCreateCard($user);

        $this->assertDatabaseHas('daleachious_cards', [
            'user_id' => $user->id,
            'status' => DaleachiousCard::STATUS_ACTIVE,
        ]);
        $this->assertSame('0.00', $this->money($user->fresh()->daleachiousCard->balance));
        $this->assertSame(1, DaleachiousCard::query()->where('user_id', $user->id)->count());
    }

    public function test_existing_customer_without_a_card_gets_one_on_get_card(): void
    {
        $user = User::factory()->create();
        $this->assertDatabaseMissing('daleachious_cards', ['user_id' => $user->id]);

        Sanctum::actingAs($user);

        $this->getJson('/api/card')
            ->assertOk()
            ->assertJsonPath('balance', 0)
            ->assertJsonPath('formatted_balance', '₱0.00')
            ->assertJsonPath('status', 'active');

        $this->assertDatabaseHas('daleachious_cards', ['user_id' => $user->id]);
        $this->assertSame('0.00', $this->money($user->fresh()->daleachiousCard->balance));
    }

    public function test_get_or_create_card_does_not_duplicate(): void
    {
        $user = User::factory()->create();
        $service = app(DaleachiousCardService::class);

        $first = $service->getOrCreateCard($user);
        $second = $service->getOrCreateCard($user);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DaleachiousCard::query()->where('user_id', $user->id)->count());
    }

    public function test_customer_top_up_is_pending_and_does_not_change_balance(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/card/topups', [
            'amount' => 500,
            'balance_before' => 999,
            'balance_after' => 999,
            'status' => 'completed',
        ])
            ->assertCreated()
            ->assertJsonPath('amount', 500)
            ->assertJsonPath('status', 'pending')
            ->assertJsonStructure(['reference', 'amount', 'status']);

        $this->getJson('/api/card')
            ->assertOk()
            ->assertJsonPath('balance', 0);

        $this->assertSame('0.00', $this->money($user->fresh()->daleachiousCard->balance));
        $this->assertDatabaseHas('daleachious_card_transactions', [
            'user_id' => $user->id,
            'type' => 'topup',
            'status' => 'pending',
            'amount' => '500.00',
        ]);
    }

    public function test_authorized_admin_completes_top_up_and_credits_balance_once(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $reference = $this->postJson('/api/card/topups', ['amount' => 500])
            ->assertCreated()
            ->json('reference');

        $admin = $this->superAdmin();
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/card-topups/{$reference}/complete")
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('amount', 500)
            ->assertJsonPath('balance_before', 0)
            ->assertJsonPath('balance_after', 500);

        $this->assertSame('500.00', $this->money($user->fresh()->daleachiousCard->balance));

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/card-topups/{$reference}/complete")
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $this->assertSame('500.00', $this->money($user->fresh()->daleachiousCard->balance));
        $this->assertSame(1, DaleachiousCardTransaction::query()->where('reference', $reference)->count());
    }

    public function test_customer_cannot_complete_their_own_top_up(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $reference = $this->postJson('/api/card/topups', ['amount' => 500])
            ->assertCreated()
            ->json('reference');

        Sanctum::actingAs($user);
        $this->postJson("/api/admin/card-topups/{$reference}/complete")
            ->assertForbidden();

        $this->assertSame('0.00', $this->money($user->fresh()->daleachiousCard->balance));
        $this->assertDatabaseHas('daleachious_card_transactions', [
            'reference' => $reference,
            'status' => 'pending',
        ]);
    }

    public function test_customer_cannot_access_another_customers_card_or_transactions(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $bobTxn = app(DaleachiousCardService::class)->createTopUp($bob, 500);
        app(DaleachiousCardService::class)->completeTopUp($bobTxn->reference);

        Sanctum::actingAs($alice);
        $this->getJson('/api/card?user_id='.$bob->id)
            ->assertOk()
            ->assertJsonPath('balance', 0);

        $this->assertSame('0.00', $this->money($alice->fresh()->daleachiousCard->balance));
        $this->assertSame('500.00', $this->money($bob->fresh()->daleachiousCard->balance));

        $rows = $this->getJson('/api/card/transactions')->assertOk()->json();
        $this->assertIsArray($rows);
        $this->assertCount(0, $rows);
        $this->assertFalse(collect($rows)->contains('reference', $bobTxn->reference));
    }

    public function test_top_up_below_minimum_is_rejected(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/card/topups', ['amount' => 99])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);

        $this->assertSame(0, DaleachiousCardTransaction::query()->where('user_id', $user->id)->count());
        $this->getJson('/api/card')->assertJsonPath('balance', 0);
    }

    public function test_top_up_above_maximum_is_rejected(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/card/topups', ['amount' => 10001])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);

        $this->assertSame(0, DaleachiousCardTransaction::query()->where('user_id', $user->id)->count());
        $this->getJson('/api/card')->assertJsonPath('balance', 0);
    }

    public function test_failed_and_pending_top_ups_do_not_affect_card_balance(): void
    {
        $user = User::factory()->create();
        $service = app(DaleachiousCardService::class);

        $pending = $service->createTopUp($user, 500);
        $failed = $service->createTopUp($user, 1000);
        $service->failTopUp($failed->reference);

        $this->assertSame('0.00', $this->money($user->fresh()->daleachiousCard->balance));
        $this->assertDatabaseHas('daleachious_card_transactions', [
            'reference' => $pending->reference,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('daleachious_card_transactions', [
            'reference' => $failed->reference,
            'status' => 'failed',
        ]);

        Sanctum::actingAs($user);
        $this->getJson('/api/card')->assertJsonPath('balance', 0);
        $this->getJson('/api/user/card')->assertJsonPath('balance', 0);
    }

    public function test_customer_cannot_set_balance_directly(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/card/set-balance', ['balance' => 700])->assertNotFound();
        $this->postJson('/api/card', ['balance' => 700])->assertStatus(405);

        $this->getJson('/api/card')->assertJsonPath('balance', 0);
    }

    public function test_user_card_aliases_match_spec_routes(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/user/card')->assertOk()->assertJsonPath('balance', 0);
        $this->postJson('/api/user/card/topups', ['amount' => 200])
            ->assertCreated()
            ->assertJsonPath('status', 'pending');
        $this->getJson('/api/user/card/transactions')
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_admin_can_list_and_fail_pending_top_ups(): void
    {
        $user = User::factory()->create(['name' => 'Ada Lovelace']);
        $txn = app(DaleachiousCardService::class)->createTopUp($user, 500);

        $admin = $this->superAdmin();
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/card-topups?status=pending')
            ->assertOk()
            ->assertJsonPath('stats.pending', 1)
            ->assertJsonPath('data.0.reference', $txn->reference)
            ->assertJsonPath('data.0.user.name', 'Ada Lovelace');

        Sanctum::actingAs($user);
        $this->getJson('/api/admin/card-topups')->assertForbidden();
        $this->postJson("/api/admin/card-topups/{$txn->reference}/fail")->assertForbidden();

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/card-topups/{$txn->reference}/fail")
            ->assertOk()
            ->assertJsonPath('status', 'failed');

        $this->assertSame('0.00', $this->money($user->fresh()->daleachiousCard->balance));
    }

    public function test_member_show_includes_card_balance(): void
    {
        $user = User::factory()->create();
        $admin = $this->superAdmin();
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/members/'.$user->id)
            ->assertOk()
            ->assertJsonPath('card_balance', 0)
            ->assertJsonPath('formatted_card_balance', '₱0.00');
    }

    public function test_member_qr_scan_tops_up_card_in_store(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $code = $this->getJson('/api/user/loyalty-qr')
            ->assertOk()
            ->json('qr_code.code');

        $this->assertNotEmpty($code);
        $this->assertSame(1, QrCode::query()->where('qrable_id', $user->id)->where('type', 'user')->count());

        $this->postJson('/api/admin/qr/scan', [
            'code' => $code,
            'action' => 'topup_card',
            'amount' => 500,
        ])->assertForbidden();

        $admin = $this->superAdmin();
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/qr/scan', [
            'code' => $code,
            'action' => 'topup_card',
        ])->assertOk()->assertJsonPath('result', 'failed');

        $this->assertSame('0.00', $this->money($user->fresh()->daleachiousCard?->balance ?? 0));

        $this->postJson('/api/admin/qr/scan', [
            'code' => $code,
            'action' => 'topup_card',
            'amount' => 99,
        ])->assertOk()->assertJsonPath('result', 'failed');

        $this->postJson('/api/admin/qr/scan', [
            'code' => $code,
            'action' => 'topup_card',
            'amount' => 500,
        ])
            ->assertOk()
            ->assertJsonPath('result', 'success')
            ->assertJsonPath('amount', 500)
            ->assertJsonPath('card_balance', 500);

        $this->assertSame('500.00', $this->money($user->fresh()->daleachiousCard->balance));
        $this->assertDatabaseHas('daleachious_card_transactions', [
            'user_id' => $user->id,
            'status' => 'completed',
            'payment_method' => 'in_store',
            'amount' => '500.00',
        ]);
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
