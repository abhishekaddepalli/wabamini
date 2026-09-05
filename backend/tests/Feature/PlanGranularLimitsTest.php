<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Plan;
use App\Models\Currency;
use App\Models\ChannelConnection;
use App\Models\FlowTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PlanGranularLimitsTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;
    protected Currency $currency;
    protected Plan $standardPlan;
    protected Plan $restrictedPlan;
    protected Tenant $tenant;
    protected User $tenantUser;

    protected function setUp(): void
    {
        parent::setUp();

        $role = \App\Models\AdminRole::firstOrCreate(
            ['name' => 'Super Admin'],
            ['permissions' => ['*']]
        );

        $this->admin = Admin::create([
            'role_id' => $role->id,
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'email' => 'admin@whatsomni.test',
            'password' => bcrypt('password123'),
            'status' => 'active',
        ]);

        $this->currency = Currency::create([
            'code' => 'USD',
            'name' => 'US Dollar',
            'symbol' => '$',
            'is_active' => true,
        ]);

        $this->standardPlan = Plan::create([
            'name' => 'Growth Plan',
            'description' => 'Growth tier with full access',
            'trial_days' => 14,
            'max_team_members' => 10,
            'max_campaigns' => 20,
            'max_integrations' => 10,
            'allowed_integrations' => ['google_sheets', 'ecommerce', 'meetings', 'crm', 'webhooks'],
            'own_crm_access' => true,
            'max_channels' => 10,
            'allowed_channels' => [
                'whatsapp' => 2,
                'whatsapp_baileys' => 2,
                'telegram' => 5,
                'instagram' => 2,
                'messenger' => 2,
                'sms' => 2,
                'email' => 2,
                'live_chat' => 2,
            ],
            'max_automations' => 20,
            'flow_credits' => 100,
            'has_flow_templates' => true,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->restrictedPlan = Plan::create([
            'name' => 'Starter WhatsApp Only Plan',
            'description' => 'Starter plan with WhatsApp Cloud API capped at 1, no integrations, no flow templates',
            'trial_days' => 7,
            'max_team_members' => 2,
            'max_campaigns' => 5,
            'max_integrations' => 0,
            'allowed_integrations' => [],
            'own_crm_access' => false,
            'max_channels' => 1,
            'allowed_channels' => [
                'whatsapp' => 1,
            ],
            'max_automations' => 2,
            'flow_credits' => 10,
            'has_flow_templates' => false,
            'sort_order' => 2,
            'is_active' => true,
        ]);

        $this->tenant = Tenant::create([
            'company_name' => 'Acme Test Corp',
            'plan_id' => $this->restrictedPlan->id,
            'status' => 'active',
            'onboarding_step' => 'complete',
        ]);

        $this->tenantUser = User::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Alice',
            'last_name' => 'Smith',
            'email' => 'alice@acme.test',
            'password' => bcrypt('password123'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    public function test_superadmin_can_create_plan_with_granular_limits(): void
    {
        $payload = [
            'name' => 'Pro Enterprise Plan',
            'description' => 'Dedicated channels and custom integration whitelist',
            'trial_days' => 30,
            'max_team_members' => 25,
            'max_campaigns' => 50,
            'max_integrations' => 10,
            'allowed_integrations' => ['google_sheets', 'ecommerce', 'meetings'],
            'own_crm_access' => true,
            'max_channels' => 8,
            'allowed_channels' => [
                'whatsapp' => 3,
                'telegram' => 3,
                'email' => 2,
            ],
            'max_automations' => 50,
            'flow_credits' => 500,
            'has_flow_templates' => true,
            'sort_order' => 5,
            'is_active' => true,
            'prices' => [
                [
                    'currency_id' => $this->currency->id,
                    'amount' => 9900,
                    'billing_interval' => 'month',
                ],
            ],
        ];

        $res = $this->actingAs($this->admin, 'admin')
            ->postJson('/api/admin/plans', $payload);

        $res->assertStatus(201);
        $this->assertDatabaseHas('plans', [
            'name' => 'Pro Enterprise Plan',
            'has_flow_templates' => true,
        ]);

        $createdPlan = Plan::where('name', 'Pro Enterprise Plan')->first();
        $this->assertEquals(['whatsapp' => 3, 'telegram' => 3, 'email' => 2], $createdPlan->allowed_channels);
        $this->assertEquals(['google_sheets', 'ecommerce', 'meetings'], $createdPlan->allowed_integrations);
    }

    public function test_tenant_cannot_connect_disallowed_channel_type(): void
    {
        // Tenant is on Starter WhatsApp Only plan (telegram is NOT in allowed_channels)
        $res = $this->actingAs($this->tenantUser, 'sanctum')
            ->postJson('/api/integrations/telegram/connect', [
                'name' => 'My Telegram Bot',
                'token' => '123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ',
            ]);

        $res->assertStatus(403);
        $res->assertJsonPath('error_code', 'CHANNEL_NOT_ALLOWED_IN_PLAN');
    }

    public function test_tenant_cannot_exceed_per_channel_cap(): void
    {
        // Connect 1st WhatsApp Cloud API (allowed cap is 1)
        ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'whatsapp',
            'name' => 'WhatsApp Primary Line',
            'status' => 'connected',
            'credentials' => [
                'phone_number_id' => '111222333',
                'whatsapp_business_account_id' => '444555666',
                'system_user_access_token' => 'EAAG...',
            ],
        ]);

        // Attempt to connect 2nd WhatsApp Cloud API connection -> Blocked with 403 CHANNEL_CAP_REACHED
        $res = $this->actingAs($this->tenantUser, 'sanctum')
            ->postJson('/api/integrations/whatsapp/connect', [
                'name' => 'WhatsApp Secondary Line',
                'phone_number_id' => '999888777',
                'whatsapp_business_account_id' => '444555666',
                'system_user_access_token' => 'EAAG_SECOND...',
            ]);

        $res->assertStatus(403);
        $res->assertJsonPath('error_code', 'CHANNEL_CAP_REACHED');
    }

    public function test_tenant_cannot_connect_disallowed_integration(): void
    {
        // Tenant on restricted plan with allowed_integrations = []
        $res = $this->actingAs($this->tenantUser, 'sanctum')
            ->postJson('/api/integrations/ecommerce/connect', [
                'platform' => 'shopify',
                'store_url' => 'myshop.myshopify.com',
            ]);

        $res->assertStatus(403);
        $res->assertJsonPath('error_code', 'INTEGRATION_NOT_ALLOWED_IN_PLAN');
    }

    public function test_tenant_with_selective_individual_integrations(): void
    {
        // Create plan that allows only shopify and zoom, but not woocommerce or hubspot
        $customPlan = Plan::create([
            'name' => 'Shopify & Zoom Only Plan',
            'trial_days' => 14,
            'max_team_members' => 5,
            'max_campaigns' => 10,
            'max_integrations' => 5,
            'allowed_integrations' => ['shopify', 'zoom'],
            'own_crm_access' => true,
            'max_channels' => 5,
            'allowed_channels' => ['whatsapp' => 2],
            'max_automations' => 10,
            'flow_credits' => 50,
            'has_flow_templates' => true,
            'sort_order' => 3,
            'is_active' => true,
        ]);

        $customTenant = Tenant::create([
            'company_name' => 'Custom Shop Corp',
            'plan_id' => $customPlan->id,
            'status' => 'active',
        ]);

        $customUser = User::create([
            'tenant_id' => $customTenant->id,
            'first_name' => 'Bob',
            'last_name' => 'Builder',
            'email' => 'bob@customshop.test',
            'password' => bcrypt('password123'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        // Shopify is allowed
        $shopifyRes = $this->actingAs($customUser, 'sanctum')
            ->postJson('/api/integrations/ecommerce/connect', [
                'platform' => 'shopify',
                'store_url' => 'myshop.myshopify.com',
            ]);
        $shopifyRes->assertStatus(200);

        // WooCommerce is blocked
        $wooRes = $this->actingAs($customUser, 'sanctum')
            ->postJson('/api/integrations/ecommerce/connect', [
                'platform' => 'woocommerce',
                'store_url' => 'https://mywooshop.com',
            ]);
        $wooRes->assertStatus(403);
        $wooRes->assertJsonPath('error_code', 'INTEGRATION_NOT_ALLOWED_IN_PLAN');

        // Zoom auth url is allowed
        $zoomRes = $this->actingAs($customUser, 'sanctum')
            ->getJson('/api/integrations/meetings/auth-url?provider=zoom');
        $zoomRes->assertStatus(200);

        // Teams auth url is blocked
        $teamsRes = $this->actingAs($customUser, 'sanctum')
            ->getJson('/api/integrations/meetings/auth-url?provider=teams');
        $teamsRes->assertStatus(403);
        $teamsRes->assertJsonPath('error_code', 'INTEGRATION_NOT_ALLOWED_IN_PLAN');

        // HubSpot is blocked
        $hsRes = $this->actingAs($customUser, 'sanctum')
            ->getJson('/api/integrations/crm/connect/hubspot');
        $hsRes->assertStatus(403);
        $hsRes->assertJsonPath('error_code', 'INTEGRATION_NOT_ALLOWED_IN_PLAN');
    }

    public function test_tenant_cannot_access_templates_when_disabled_in_plan(): void
    {
        FlowTemplate::create([
            'slug' => 'support_triage',
            'name' => 'Support Triage Blueprint',
            'category' => 'Customer Support',
            'description' => 'Automated support triage flow',
            'trigger_type' => 'inbound_message',
            'definition' => ['nodes' => [], 'edges' => []],
            'nodes_count' => 5,
            'is_published' => true,
        ]);

        // Tenant on restricted plan has has_flow_templates = false
        $res = $this->actingAs($this->tenantUser, 'sanctum')
            ->getJson('/api/flows/templates');

        $res->assertStatus(403);
        $res->assertJsonPath('error_code', 'TEMPLATES_NOT_INCLUDED_IN_PLAN');

        // Instantiate endpoint also blocked
        $instantiateRes = $this->actingAs($this->tenantUser, 'sanctum')
            ->postJson('/api/flows/from-template', [
                'template_id' => 'support_triage',
            ]);

        $instantiateRes->assertStatus(403);
        $instantiateRes->assertJsonPath('error_code', 'TEMPLATES_NOT_INCLUDED_IN_PLAN');
    }

    public function test_tenant_cannot_exceed_max_team_members(): void
    {
        // Restricted plan allows max 2 team members. Tenant already has 1 user ($this->tenantUser).
        // Invite 1 more (fills to 2)
        $role = \App\Models\Role::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'name' => 'agent'],
            [
                'display_name' => 'Agent',
                'description' => 'Standard Support Agent',
                'permissions' => ['contacts:read'],
                'is_system' => false
            ]
        );

        $res1 = $this->actingAs($this->tenantUser, 'sanctum')
            ->postJson('/api/invitations', [
                'email' => 'teammate1@acme.test',
                'role_id' => $role->id,
            ]);
        $res1->assertStatus(200);

        // Attempt to invite 3rd member -> Blocked with 422 (seat limit reached)
        $res2 = $this->actingAs($this->tenantUser, 'sanctum')
            ->postJson('/api/invitations', [
                'email' => 'teammate2@acme.test',
                'role_id' => $role->id,
            ]);
        $res2->assertStatus(422);
        $res2->assertJsonPath('message', 'Seat limit reached. Upgrade your subscription plan to invite more team members.');
    }

    public function test_tenant_cannot_exceed_max_campaigns(): void
    {
        $channel = ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'whatsapp',
            'name' => 'WhatsApp Primary',
            'status' => 'connected',
            'credentials' => [
                'phone_number_id' => '111222',
                'whatsapp_business_account_id' => '333444',
                'system_user_access_token' => 'EAAG...',
            ],
        ]);

        // Restricted plan allows max 5 campaigns.
        for ($i = 1; $i <= 5; $i++) {
            \App\Models\Campaign::create([
                'tenant_id' => $this->tenant->id,
                'channel_connection_id' => $channel->id,
                'name' => "Campaign {$i}",
                'source_type' => 'custom',
                'audience_filter' => ['type' => 'all'],
                'schedule_type' => 'scheduled',
                'status' => 'scheduled',
            ]);
        }

        // Attempting to create 6th campaign -> Blocked with 403 MAX_CAMPAIGNS_LIMIT_REACHED
        $res = $this->actingAs($this->tenantUser, 'sanctum')
            ->postJson('/api/campaigns', [
                'name' => 'Campaign 6 Beyond Limit',
                'channel_connection_id' => $channel->id,
                'source_type' => 'custom',
                'audience_filter' => ['type' => 'all'],
                'schedule_type' => 'scheduled',
            ]);

        $res->assertStatus(403);
        $res->assertJsonPath('code', 'MAX_CAMPAIGNS_LIMIT_REACHED');
    }

    public function test_tenant_cannot_exceed_max_automations(): void
    {
        // Restricted plan allows max 2 automations.
        for ($i = 1; $i <= 2; $i++) {
            \App\Models\Flow::create([
                'tenant_id' => $this->tenant->id,
                'name' => "Flow {$i}",
                'trigger_type' => 'inbound_message',
                'channel_type' => 'omnichannel',
                'is_active' => false,
            ]);
        }

        // Attempting to create 3rd flow -> Blocked with 403 MAX_AUTOMATIONS_LIMIT_REACHED
        $res = $this->actingAs($this->tenantUser, 'sanctum')
            ->postJson('/api/flows', [
                'name' => 'Flow 3 Beyond Limit',
                'trigger_type' => 'inbound_message',
            ]);

        $res->assertStatus(403);
        $res->assertJsonPath('code', 'MAX_AUTOMATIONS_LIMIT_REACHED');
    }

    public function test_tenant_cannot_access_crm_deals_without_own_crm_access(): void
    {
        // Restricted plan has own_crm_access = false
        $res = $this->actingAs($this->tenantUser, 'sanctum')
            ->getJson('/api/deals');

        $res->assertStatus(403);
    }

    public function test_suspended_tenant_is_blocked_from_all_plan_actions(): void
    {
        $this->tenant->update(['status' => 'suspended']);

        // Channel connection blocked
        $channelRes = $this->actingAs($this->tenantUser, 'sanctum')
            ->postJson('/api/integrations/whatsapp/connect', [
                'name' => 'Blocked WhatsApp',
                'phone_number_id' => '12345',
                'whatsapp_business_account_id' => '67890',
                'system_user_access_token' => 'TOKEN',
            ]);
        $channelRes->assertStatus(403);
        $channelRes->assertJsonPath('error_code', 'TENANT_SUSPENDED');

        // Integration blocked
        $itgRes = $this->actingAs($this->tenantUser, 'sanctum')
            ->postJson('/api/integrations/ecommerce/connect', [
                'platform' => 'shopify',
                'store_url' => 'shop.myshopify.com',
            ]);
        $itgRes->assertStatus(403);
        $itgRes->assertJsonPath('error_code', 'TENANT_SUSPENDED');
    }
}

