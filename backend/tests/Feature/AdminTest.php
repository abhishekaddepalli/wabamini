<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\Currency;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;
    protected AdminRole $role;
    protected Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Create a default currency
        $this->currency = Currency::create([
            'code' => 'USD',
            'symbol' => '$',
            'name' => 'US Dollar',
            'is_active' => true,
            'is_default' => true,
        ]);

        // 2. Create admin role
        $this->role = AdminRole::create([
            'name' => 'Super Admin',
            'permissions' => ['*'],
        ]);

        // 3. Create active admin
        $this->admin = Admin::create([
            'role_id' => $this->role->id,
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'admin_test@whatsomni.com',
            'password' => Hash::make('Secret123!'),
            'status' => 'active',
        ]);
    }

    /**
     * Test admin login rate limiting and lockout behavior.
     */
    public function test_admin_login_lockout_after_five_failed_attempts(): void
    {
        $throttleKey = 'admin_login:admin_test@whatsomni.com|127.0.0.1';
        RateLimiter::clear($throttleKey);

        // 5 consecutive failures
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/admin/login', [
                'email' => 'admin_test@whatsomni.com',
                'password' => 'WrongPassword',
            ]);

            $response->assertStatus(401)
                ->assertJsonStructure(['message', 'attempts_left']);
        }

        // 6th attempt lock out check
        $response = $this->postJson('/api/admin/login', [
            'email' => 'admin_test@whatsomni.com',
            'password' => 'Secret123!',
        ]);

        $response->assertStatus(429)
            ->assertJsonStructure(['message', 'lockout_seconds']);
        
        RateLimiter::clear($throttleKey);
    }

    /**
     * Test successful login.
     */
    public function test_admin_successful_login(): void
    {
        $response = $this->postJson('/api/admin/login', [
            'email' => 'admin_test@whatsomni.com',
            'password' => 'Secret123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['admin' => ['id', 'first_name', 'last_name', 'email'], 'message'])
            ->assertCookie('whatsomni_admin_logged_in', '1');

        $this->assertAuthenticatedAs($this->admin, 'admin');
    }

    /**
     * Test tenant management endpoints.
     */
    public function test_admin_can_manage_tenants(): void
    {
        // Create a test tenant and user
        $tenant = Tenant::create([
            'company_name' => 'ACME Inc',
            'status' => 'trial',
            'currency_id' => $this->currency->id,
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@acme.com',
            'password' => Hash::make('Secret123!'),
            'status' => 'active',
        ]);

        $this->actingAs($this->admin, 'admin');

        // 1. List tenants
        $response = $this->getJson('/api/admin/tenants');
        $response->assertStatus(200)
            ->assertJsonCount(1, 'tenants');

        // 2. Suspend tenant
        $response = $this->postJson("/api/admin/tenants/{$tenant->id}/status", [
            'status' => 'suspended',
        ]);
        $response->assertStatus(200);
        $this->assertEquals('suspended', $tenant->fresh()->status);

        // Verify audit log entry was created
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $this->admin->id,
            'action' => 'update_tenant_status',
            'subject_id' => $tenant->id,
        ]);
    }

    /**
     * Test tenant impersonation.
     */
    public function test_admin_can_impersonate_tenant(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Impersonation Co',
            'status' => 'active',
            'currency_id' => $this->currency->id,
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@impersonate.com',
            'password' => Hash::make('Secret123!'),
            'status' => 'active',
        ]);

        $this->actingAs($this->admin, 'admin');

        // Start impersonating
        $response = $this->postJson("/api/admin/tenants/{$tenant->id}/impersonate");
        $response->assertStatus(200)
            ->assertJsonStructure(['message', 'redirect_url', 'user'])
            ->assertCookie('whatsomni_logged_in', '1');

        // Authenticated as user under web guard
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertEquals($this->admin->id, session('impersonator_admin_id'));

        // Stop impersonating
        $response = $this->postJson('/api/admin/impersonate/stop');
        $response->assertStatus(200)
            ->assertJsonStructure(['message', 'redirect_url'])
            ->assertCookieExpired('whatsomni_logged_in');

        // Authenticated back as admin
        $this->assertAuthenticatedAs($this->admin, 'admin');
        $this->assertNull(session('impersonator_admin_id'));
    }

    /**
     * Test plan creation.
     */
    public function test_admin_can_create_plans(): void
    {
        $this->actingAs($this->admin, 'admin');

        $response = $this->postJson('/api/admin/plans', [
            'name' => 'Premium Plan',
            'description' => 'A heavy-duty plan',
            'trial_days' => 14,
            'max_team_members' => 10,
            'max_campaigns' => 50,
            'max_integrations' => 5,
            'own_crm_access' => true,
            'max_channels' => 3,
            'max_automations' => 10,
            'sort_order' => 1,
            'is_active' => true,
            'prices' => [
                [
                    'currency_id' => $this->currency->id,
                    'amount' => 9900, // $99.00
                    'billing_interval' => 'month',
                ]
            ]
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['message', 'plan' => ['id', 'stripe_product_id']]);

        $this->assertDatabaseHas('plans', [
            'name' => 'Premium Plan',
            'stripe_product_id' => 'prod_mock_123',
        ]);

        $plan = Plan::where('name', 'Premium Plan')->first();

        $this->assertDatabaseHas('plan_prices', [
            'plan_id' => $plan->id,
            'currency_id' => $this->currency->id,
            'amount' => 9900,
            'billing_interval' => 'month',
        ]);
    }

    /**
     * Test plan update.
     */
    public function test_admin_can_update_plans(): void
    {
        $this->actingAs($this->admin, 'admin');

        $plan = Plan::create([
            'name' => 'Old Plan Name',
            'description' => 'Old description',
            'stripe_product_id' => 'prod_mock_123',
            'trial_days' => 7,
            'max_team_members' => 3,
            'max_campaigns' => 5,
            'max_integrations' => 1,
            'own_crm_access' => false,
            'max_channels' => 1,
            'max_automations' => 2,
            'sort_order' => 0,
            'is_active' => false,
        ]);

        PlanPrice::create([
            'plan_id' => $plan->id,
            'currency_id' => $this->currency->id,
            'amount' => 1900,
            'stripe_price_id' => 'price_mock_123',
            'billing_interval' => 'month',
        ]);

        $response = $this->putJson("/api/admin/plans/{$plan->id}", [
            'name' => 'Updated Plan Name',
            'description' => 'Updated description',
            'trial_days' => 14,
            'max_team_members' => 5,
            'max_campaigns' => 10,
            'max_integrations' => 3,
            'own_crm_access' => true,
            'max_channels' => 2,
            'max_automations' => 5,
            'sort_order' => 2,
            'is_active' => true,
            'prices' => [
                [
                    'currency_id' => $this->currency->id,
                    'amount' => 2900, // Updated $29.00
                    'billing_interval' => 'month',
                ]
            ]
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('plans', [
            'id' => $plan->id,
            'name' => 'Updated Plan Name',
            'own_crm_access' => true,
        ]);

        $this->assertDatabaseHas('plan_prices', [
            'plan_id' => $plan->id,
            'currency_id' => $this->currency->id,
            'amount' => 2900,
        ]);
    }

    /**
     * Test plan archive (soft delete).
     */
    public function test_admin_can_archive_plans(): void
    {
        $this->actingAs($this->admin, 'admin');

        $plan = Plan::create([
            'name' => 'Plan to Archive',
            'trial_days' => 14,
            'max_team_members' => 10,
            'max_campaigns' => 50,
            'max_integrations' => 5,
            'own_crm_access' => true,
            'max_channels' => 3,
            'max_automations' => 10,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/admin/plans/{$plan->id}/archive");

        $response->assertStatus(200);

        $this->assertSoftDeleted('plans', [
            'id' => $plan->id
        ]);
    }

    /**
     * Test plan unarchive (restore).
     */
    public function test_admin_can_unarchive_plans(): void
    {
        $this->actingAs($this->admin, 'admin');

        $plan = Plan::create([
            'name' => 'Plan to Unarchive',
            'trial_days' => 14,
            'max_team_members' => 10,
            'max_campaigns' => 50,
            'max_integrations' => 5,
            'own_crm_access' => true,
            'max_channels' => 3,
            'max_automations' => 10,
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $plan->delete(); // soft delete

        $this->assertSoftDeleted('plans', [
            'id' => $plan->id
        ]);

        $response = $this->postJson("/api/admin/plans/{$plan->id}/unarchive");

        $response->assertStatus(200);

        $this->assertDatabaseHas('plans', [
            'id' => $plan->id,
            'deleted_at' => null,
            'is_active' => false // unarchived as draft
        ]);
    }

    /**
     * Test setting mailer test connection.
     */
    public function test_admin_settings_mailer_test(): void
    {
        $this->actingAs($this->admin, 'admin');

        // Mock mail sending
        \Illuminate\Support\Facades\Mail::fake();

        $response = $this->postJson('/api/admin/settings/mailer/test', [
            'type' => 'smtp',
            'recipient' => 'test@example.com',
            'config' => [
                'host' => 'smtp.mailpit.io',
                'port' => 1025,
                'username' => 'test',
                'password' => 'secret',
                'from_address' => 'system@whatsomni.com',
                'from_name' => 'WhatsOmni System',
            ]
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        \Illuminate\Support\Facades\Mail::mailer('temp_smtp')->assertSent(\App\Mail\TestPlatformMail::class);
    }

    /** @test */
    public function can_retrieve_public_platform_settings(): void
    {
        $response = $this->getJson('/api/platform/settings');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'branding_name',
                'supported_languages'
            ]);
    }

    /** @test */
    public function can_update_profile_language(): void
    {
        $tenant = Tenant::create(['company_name' => 'Demo Workspace']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane.profile@example.com',
            'password' => bcrypt('password123'),
        ]);

        $this->actingAs($user);

        $response = $this->patchJson('/api/auth/profile', [
            'language' => 'es'
        ]);

        $response->assertStatus(200);
        $this->assertEquals('es', $user->fresh()->language);
    }

    /**
     * Test admin can create a tenant and its owner.
     */
    public function test_admin_can_create_tenant_and_owner_account(): void
    {
        $this->actingAs($this->admin, 'admin');

        // Create default system owner role
        DB::table('roles')->insert([
            'tenant_id' => null,
            'name' => 'owner',
            'display_name' => 'Owner',
            'permissions' => json_encode(['*']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson('/api/admin/tenants', [
            'company_name' => 'Acme Corporation',
            'team_size' => '10-50',
            'industry_category' => 'Technology',
            'status' => 'active',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.owner@acme.com',
            'password' => 'SecurePass123!',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['message', 'tenant' => ['id', 'company_name'], 'user' => ['id', 'email']]);

        $this->assertDatabaseHas('tenants', [
            'company_name' => 'Acme Corporation',
            'team_size' => '10-50',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('tenant_users', [
            'email' => 'john.owner@acme.com',
            'first_name' => 'John',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $this->admin->id,
            'action' => 'create_tenant',
        ]);
    }

    /**
     * Test admin can update tenant details.
     */
    public function test_admin_can_update_tenant_details(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Old Corporation Name',
            'status' => 'trial',
            'currency_id' => $this->currency->id,
        ]);

        $this->actingAs($this->admin, 'admin');

        $response = $this->putJson("/api/admin/tenants/{$tenant->id}", [
            'company_name' => 'Updated Corporation Name',
            'status' => 'active',
            'currency_id' => $this->currency->id,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'company_name' => 'Updated Corporation Name',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $this->admin->id,
            'action' => 'update_tenant',
            'subject_id' => $tenant->id,
        ]);
    }

    /**
     * Test admin can delete tenant.
     */
    public function test_admin_can_delete_tenant_workspace_and_related_records(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Temporary Corp',
            'status' => 'trial',
            'currency_id' => $this->currency->id,
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane.temp@corp.com',
            'password' => bcrypt('password123'),
        ]);

        $this->actingAs($this->admin, 'admin');

        $response = $this->deleteJson("/api/admin/tenants/{$tenant->id}");

        $response->assertStatus(200);

        $this->assertSoftDeleted('tenants', [
            'id' => $tenant->id,
        ]);

        $this->assertSoftDeleted('tenant_users', [
            'id' => $user->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $this->admin->id,
            'action' => 'delete_tenant',
            'subject_id' => $tenant->id,
        ]);
    }

    /**
     * Test auth/me returns is_impersonated flag.
     */
    public function test_auth_me_returns_is_impersonated(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Impersonation Sandbox',
            'status' => 'active',
            'currency_id' => $this->currency->id,
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'first_name' => 'Bob',
            'last_name' => 'Smith',
            'email' => 'bob@sandbox.com',
            'password' => bcrypt('password123'),
        ]);

        // Access route as standard user -> is_impersonated is false
        $response = $this->actingAs($user, 'web')->getJson('/api/auth/me');
        $response->assertStatus(200)
            ->assertJson(['is_impersonated' => false]);

        // Simulate impersonation state by setting session variable
        session(['impersonator_admin_id' => $this->admin->id]);

        $response = $this->actingAs($user, 'web')->getJson('/api/auth/me');
        $response->assertStatus(200)
            ->assertJson(['is_impersonated' => true]);
    }
}
