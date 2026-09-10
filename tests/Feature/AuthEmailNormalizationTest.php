<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthEmailNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_normalizes_email_case(): void
    {
        User::factory()->create([
            'email' => 'member@example.com',
            'password' => 'secret123',
        ]);

        $this->postJson('/api/user/login', [
            'email' => 'MEMBER@EXAMPLE.COM',
            'password' => 'secret123',
        ])->assertOk()->assertJsonStructure(['user', 'token']);
    }

    public function test_profile_email_is_stored_in_canonical_lowercase(): void
    {
        $user = User::factory()->create(['email' => 'member@example.com']);
        Sanctum::actingAs($user);

        $this->patchJson('/api/user/profile', [
            'email' => 'Updated.Member@Example.COM',
        ])->assertOk()->assertJsonPath('user.email', 'updated.member@example.com');

        $this->assertSame('updated.member@example.com', $user->fresh()->email);
    }

    public function test_registration_rejects_case_variant_of_existing_email(): void
    {
        User::factory()->create(['email' => 'member@example.com']);

        $this->postJson('/api/user/register', [
            'name' => 'Duplicate Member',
            'email' => 'MEMBER@EXAMPLE.COM',
            'password' => 'secret123',
            'registration_token' => 'unused-token',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email']);
    }
}
