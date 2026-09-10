<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_must_provide_the_correct_current_password(): void
    {
        $admin = Admin::factory()->create(['password' => 'old-secret']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/change-password', [
            'current_password' => 'wrong-secret',
            'password' => 'new-secret',
            'password_confirmation' => 'new-secret',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);

        $this->assertTrue(Hash::check('old-secret', $admin->fresh()->password));
    }

    public function test_admin_can_change_password_with_confirmation(): void
    {
        $admin = Admin::factory()->create(['password' => 'old-secret']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/change-password', [
            'current_password' => 'old-secret',
            'password' => 'new-secret',
            'password_confirmation' => 'new-secret',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-secret', $admin->fresh()->password));
    }
}
