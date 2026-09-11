<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Branch;
use App\Models\PointItem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QrRedemptionPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_qr_scanner_fulfills_reward_without_separate_review_permission(): void
    {
        $user = User::factory()->create(['points' => 100]);
        Sanctum::actingAs($user);
        $memberCode = $this->getJson('/api/user/loyalty-qr')
            ->assertOk()
            ->json('qr_code.code');

        $item = PointItem::query()->create([
            'name' => 'Permission test item',
            'description' => 'A QR scanner may fulfill this item.',
            'points_required' => 50,
            'type' => 'free_item',
            'is_active' => true,
        ]);

        $redemptionResponse = $this->postJson('/api/user/redemptions', [
            'point_item_id' => $item->id,
        ])->assertCreated();
        $redemptionCode = $redemptionResponse->json('qr_code.code');
        $redemptionId = $redemptionResponse->json('redemption.id');

        // Requesting the item must not touch the balance — only the scan spends points.
        $redemptionResponse->assertJsonPath('points_left', 100);
        $this->assertSame(100, $user->fresh()->points);
        $this->assertDatabaseMissing('loyalty_points', [
            'user_id' => $user->id,
            'type' => 'redeemed',
        ]);

        $branch = Branch::query()->create([
            'name' => 'Redemption Branch',
            'code' => 'REDEEM-TEST',
            'address' => 'Test address',
            'city' => 'Test city',
        ]);
        $scanner = Admin::factory()->create([
            'role' => 'custom_scanner',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);
        $scanner->givePermissionTo(['qr', 'qr.scan']);
        Sanctum::actingAs($scanner);

        $this->postJson('/api/admin/qr/scan', [
            'code' => $redemptionCode,
            'action' => 'fulfill_redemption',
        ])->assertOk()
            ->assertJsonPath('result', 'success')
            ->assertJsonPath('action', 'fulfill_redemption')
            ->assertJsonPath('member_code', $memberCode);

        // The scan is what charges the member.
        $this->assertSame(50, $user->fresh()->points);
        $this->assertDatabaseHas('loyalty_points', [
            'user_id' => $user->id,
            'type' => 'redeemed',
            'points' => -50,
        ]);
        $this->assertDatabaseHas('redemptions', [
            'id' => $redemptionId,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('qr_codes', [
            'code' => $redemptionCode,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('qr_scans', [
            'branch_id' => $branch->id,
            'action' => 'fulfill_redemption',
            'result' => 'success',
        ]);
    }
}
