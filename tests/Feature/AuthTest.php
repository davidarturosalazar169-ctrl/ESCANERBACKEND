<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_and_read_session(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk()->assertJsonPath('user.email', $user->email)->assertJsonStructure(['token']);
        $response->assertCookie(config('session.cookie'));
        $this->getJson('/api/me')->assertOk()->assertJsonPath('user.id', $user->id);
        $this->withHeader('Authorization', 'Bearer '.$response->json('token'))
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $this->actingAs($user)->postJson('/api/logout')->assertOk();
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_user_can_register(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'New Scanner',
            'email' => 'new@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated()->assertJsonPath('user.email', 'new@example.com');
        $this->assertDatabaseHas('users', ['email' => 'new@example.com']);
        $this->getJson('/api/me')->assertOk();
    }

    public function test_api_allows_vite_port_5174_preflight(): void
    {
        $this->withHeaders([
            'Origin' => 'http://localhost:5174',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type',
        ])->options('/api/login')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5174')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

}
