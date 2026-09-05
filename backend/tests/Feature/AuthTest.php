<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test registration creates a tenant and user, and dispatches verification mail.
     */
    public function test_user_can_register()
    {
        Notification::fake();

        $response = $this->postJson('/api/auth/register', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'company_name' => 'Acme Test Corp',
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!',
            'team_size' => '2-10',
            'industry_category' => 'Technology',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['message', 'email']);

        // Assert Tenant was created
        $this->assertDatabaseHas('tenants', [
            'company_name' => 'Acme Test Corp',
            'team_size' => '2-10',
            'industry_category' => 'Technology',
        ]);

        // Assert User was created
        $this->assertDatabaseHas('tenant_users', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'status' => 'pending',
        ]);

        $user = User::where('email', 'john@example.com')->first();
        $this->assertNotNull($user);

        // Assert Notification was sent
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    /**
     * Test login fails for unverified users.
     */
    public function test_login_fails_for_unverified_email()
    {
        $tenant = Tenant::create(['company_name' => 'Unverified Inc', 'status' => 'trial']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'password' => Hash::make('password'),
            'status' => 'pending',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'verified' => false,
        ]);
    }

    /**
     * Test rate limit lockout triggers after 5 failed login attempts.
     */
    public function test_login_lockout_after_multiple_failures()
    {
        $tenant = Tenant::create(['company_name' => 'Lockout Inc', 'status' => 'trial']);
        User::create([
            'tenant_id' => $tenant->id,
            'first_name' => 'Lock',
            'last_name' => 'Out',
            'email' => 'lock@example.com',
            'password' => Hash::make('password'),
            'status' => 'pending',
            'email_verified_at' => now(),
        ]);

        // Attempt login with wrong password 5 times
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'lock@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        // The 6th attempt should return a 429 lockout status
        $this->postJson('/api/auth/login', [
            'email' => 'lock@example.com',
            'password' => 'password',
        ])->assertStatus(429);
    }

    /**
     * Test successful login updates status and timestamp.
     */
    public function test_login_success()
    {
        $tenant = Tenant::create(['company_name' => 'Success Inc', 'status' => 'trial']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'first_name' => 'Success',
            'last_name' => 'User',
            'email' => 'success@example.com',
            'password' => Hash::make('password'),
            'status' => 'pending',
            'email_verified_at' => now(),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'success@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['user', 'message']);

        $user->refresh();
        $this->assertEquals('active', $user->status);
        $this->assertNotNull($user->last_login_at);
    }

    /**
     * Test active sessions browser and session revoking.
     */
    public function test_user_can_manage_sessions()
    {
        $tenant = Tenant::create(['company_name' => 'Session Inc', 'status' => 'trial']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'first_name' => 'Session',
            'last_name' => 'User',
            'email' => 'session@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        // Start session first to establish a session ID
        $this->startSession();
        $currentSessionId = session()->getId();

        // Mock two active sessions
        DB::table('user_sessions')->insert([
            [
                'id' => $currentSessionId,
                'user_id' => $user->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Chrome on macOS',
                'payload' => '',
                'last_activity' => time(),
            ],
            [
                'id' => 'session_other_id',
                'user_id' => $user->id,
                'ip_address' => '192.168.1.1',
                'user_agent' => 'Safari on iOS',
                'payload' => '',
                'last_activity' => time() - 3600,
            ],
        ]);

        // Authenticate user
        $this->actingAs($user);

        // Test listing sessions
        $response = $this->withHeaders(['X-Session-ID' => $currentSessionId])
            ->getJson('/api/settings/sessions');
        $response->assertStatus(200);
        $response->assertJsonCount(2, 'sessions');

        // Dynamically locate current session ID
        $currentSessionId = collect($response->json('sessions'))->firstWhere('is_current', true)['id'];

        // Revoke the other session
        $this->deleteJson('/api/settings/sessions/session_other_id')->assertStatus(200);

        // Confirm session was deleted
        $this->assertDatabaseMissing('user_sessions', ['id' => 'session_other_id']);

        // Assert revoking current session fails
        $this->withHeaders(['X-Session-ID' => $currentSessionId])
            ->deleteJson('/api/settings/sessions/' . $currentSessionId)->assertStatus(400);
    }

    /**
     * Test that logging in from the same device cleans up duplicate sessions.
     */
    public function test_duplicate_sessions_are_cleaned_up_on_login()
    {
        $tenant = Tenant::create(['company_name' => 'Session Inc', 'status' => 'trial']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'first_name' => 'Session',
            'last_name' => 'User',
            'email' => 'session@example.com',
            'password' => Hash::make('password123!'),
            'email_verified_at' => now(),
            'status' => 'active',
        ]);

        // Insert a duplicate stale session record matching login footprint
        DB::table('user_sessions')->insert([
            'id' => 'stale_session_same_footprint',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Symfony', // PHPUnit uses Symfony as default User-Agent
            'payload' => '',
            'last_activity' => time() - 3600,
        ]);

        $this->assertDatabaseHas('user_sessions', ['id' => 'stale_session_same_footprint']);

        // Perform login
        $response = $this->postJson('/api/auth/login', [
            'email' => 'session@example.com',
            'password' => 'password123!',
        ]);

        $response->assertStatus(200);

        // Assert that the stale session with same footprint was deleted
        $this->assertDatabaseMissing('user_sessions', ['id' => 'stale_session_same_footprint']);
    }
}
