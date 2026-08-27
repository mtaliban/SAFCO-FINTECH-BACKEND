<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS Module 1 — Authentication API tests.
 *
 * Covers: register, login, logout, /me, rate limiting, invalid credentials.
 */
class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        foreach (['system_admin', 'trainer', 'student', 'corporate_client', 'facilitator'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function makeUser(string $role = 'student', array $extra = []): User
    {
        $u = User::create(array_merge([
            'uuid'               => (string) Str::uuid(),
            'email'              => 'u' . Str::random(6) . '@test.io',
            'password'           => Hash::make('Password123!'),
            'email_verified_at'  => now(),
            'status'             => 'active',
        ], $extra));
        $u->assignRole($role);

        UserProfile::firstOrCreate(
            ['user_id' => $u->id],
            ['full_name' => 'Test User', 'country' => 'Tanzania'],
        );

        return $u;
    }

    // ── Register ──────────────────────────────────────────────────────────────

    public function test_register_creates_user_and_returns_token(): void
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'full_name'             => 'John Doe',
            'email'                 => 'newuser@test.io',
            'password'              => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role'                  => 'student',
            'accept_terms'          => true,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['user', 'token', 'token_type']]);

        $this->assertDatabaseHas('users', ['email' => 'newuser@test.io']);
    }

    public function test_register_requires_valid_email(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'full_name'             => 'Test',
            'email'                 => 'not-an-email',
            'password'              => 'Password123!',
            'password_confirmation' => 'Password123!',
            'accept_terms'          => true,
        ])->assertStatus(422);
    }

    public function test_register_rejects_duplicate_email(): void
    {
        $this->makeUser('student', ['email' => 'dup@test.io']);

        $this->postJson('/api/v1/auth/register', [
            'full_name'             => 'Someone',
            'email'                 => 'dup@test.io',
            'password'              => 'Password123!',
            'password_confirmation' => 'Password123!',
            'accept_terms'          => true,
        ])->assertStatus(422);
    }

    public function test_register_rejects_mismatched_password_confirmation(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'full_name'             => 'Test',
            'email'                 => 'new@test.io',
            'password'              => 'Password123!',
            'password_confirmation' => 'Different123!',
            'accept_terms'          => true,
        ])->assertStatus(422);
    }

    // ── Login ─────────────────────────────────────────────────────────────────

    public function test_login_returns_token_for_valid_credentials(): void
    {
        $user = $this->makeUser();

        $res = $this->postJson('/api/v1/auth/login', [
            'identifier' => $user->email,
            'password'   => 'Password123!',
        ]);

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['user', 'token', 'token_type', 'expires_at']]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = $this->makeUser();

        // AuthService throws ValidationException on bad credentials → 422
        $this->postJson('/api/v1/auth/login', [
            'identifier' => $user->email,
            'password'   => 'WrongPassword!',
        ])->assertStatus(422);
    }

    public function test_login_fails_for_inactive_user(): void
    {
        $user = $this->makeUser('student', ['status' => 'suspended']);

        // Suspended account check is surfaced as a validation error → 422
        $this->postJson('/api/v1/auth/login', [
            'identifier' => $user->email,
            'password'   => 'Password123!',
        ])->assertStatus(422);
    }

    public function test_login_fails_for_unknown_email(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'nobody@test.io',
            'password'   => 'Password123!',
        ])->assertStatus(422);
    }

    // ── Authenticated routes ───────────────────────────────────────────────────

    public function test_me_returns_authenticated_user(): void
    {
        $user = $this->makeUser('trainer');
        Sanctum::actingAs($user);

        $res = $this->getJson('/api/v1/auth/me');

        // UserResource maps uuid → 'id' in the JSON payload
        $res->assertOk()
            ->assertJsonPath('data.id', $user->uuid)
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_logout_revokes_current_token(): void
    {
        $user   = $this->makeUser();
        $result = $user->createToken('web');
        $plain  = $result->plainTextToken;

        // Logout using the bearer token
        $this->withToken($plain)->postJson('/api/v1/auth/logout')->assertOk();

        // The token record should be gone from the database
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $result->accessToken->id,
        ]);
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/v1/auth/logout')->assertStatus(401);
    }

    // ── User profile ───────────────────────────────────────────────────────────

    public function test_profile_show_returns_user_data(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        // Returns UserProfileResource (not UserResource), so check profile fields
        $this->getJson('/api/v1/users/profile')->assertOk()
            ->assertJsonPath('data.full_name', 'Test User');
    }

    public function test_profile_update_persists_changes(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $res = $this->patchJson('/api/v1/users/profile', [
            'bio'  => 'Excel enthusiast',
            'city' => 'Dar es Salaam',
        ]);

        $res->assertOk();

        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $user->id,
            'city'    => 'Dar es Salaam',
        ]);
    }

    public function test_profile_requires_authentication(): void
    {
        $this->getJson('/api/v1/users/profile')->assertStatus(401);
    }
}
