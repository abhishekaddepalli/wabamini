<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\FlowTemplate;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminFlowTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;
    protected Tenant $tenant;
    protected User $tenantUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Create Admin Role
        $role = \App\Models\AdminRole::firstOrCreate(
            ['name' => 'Super Admin'],
            ['permissions' => ['*']]
        );

        // Create Super Admin
        $this->admin = Admin::firstOrCreate(
            ['email' => 'admin_flow_test@whatsomni.com'],
            [
                'role_id' => $role->id,
                'first_name' => 'Super',
                'last_name' => 'Admin',
                'password' => bcrypt('password123'),
                'status' => 'active',
            ]
        );

        // Create Tenant & User
        Plan::create([
            'id' => 1,
            'name' => 'Enterprise Plan',
            'description' => 'Enterprise',
            'is_active' => true,
            'trial_days' => 14,
            'max_team_members' => 5,
            'max_campaigns' => 5,
            'max_integrations' => 5,
            'own_crm_access' => true,
            'max_channels' => 5,
            'max_automations' => 5,
            'price_monthly' => 99,
            'price_yearly' => 990,
        ]);

        $this->tenant = Tenant::create([
            'company_name' => 'Acme Corp',
            'plan_id' => 1,
            'status' => 'active',
            'onboarding_step' => 'complete'
        ]);

        $this->tenantUser = User::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@acme.com',
            'password' => bcrypt('password123'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    public function test_superadmin_can_create_flow_template(): void
    {
        $payload = [
            'name' => 'VIP Onboarding Concierge',
            'category' => 'Customer Success',
            'description' => 'Automatically welcomes high-value accounts and initiates onboarding sequences.',
            'trigger_type' => 'contact_created',
            'trigger_keywords' => ['vip', 'onboard'],
            'is_published' => true,
            'sort_order' => 10,
            'definition' => [
                'nodes' => [
                    [
                        'id' => 'node_1',
                        'type' => 'inbound_message',
                        'position' => ['x' => 100, 'y' => 100],
                        'data' => ['title' => 'Trigger']
                    ],
                    [
                        'id' => 'node_2',
                        'type' => 'send_message',
                        'position' => ['x' => 100, 'y' => 250],
                        'data' => ['title' => 'Welcome message', 'body' => 'Welcome aboard!']
                    ]
                ],
                'edges' => [
                    ['id' => 'e1', 'source' => 'node_1', 'target' => 'node_2']
                ]
            ]
        ];

        $response = $this->actingAs($this->admin, 'admin')
            ->postJson('/api/admin/flow-templates', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('template.name', 'VIP Onboarding Concierge');
        $response->assertJsonPath('template.slug', 'vip-onboarding-concierge');
        $response->assertJsonPath('template.nodes_count', 2);

        $this->assertDatabaseHas('flow_templates', [
            'name' => 'VIP Onboarding Concierge',
            'slug' => 'vip-onboarding-concierge',
            'is_published' => true,
            'nodes_count' => 2,
        ]);
    }

    public function test_superadmin_can_update_and_toggle_publish_status(): void
    {
        $template = FlowTemplate::create([
            'slug' => 'lead-capture',
            'name' => 'Lead Capture Flow',
            'category' => 'Sales & Growth',
            'description' => 'Test description',
            'trigger_type' => 'inbound_message',
            'definition' => ['nodes' => [['id' => '1']], 'edges' => []],
            'nodes_count' => 1,
            'is_published' => true,
        ]);

        // Toggle publish to draft
        $toggleRes = $this->actingAs($this->admin, 'admin')
            ->postJson("/api/admin/flow-templates/{$template->id}/toggle-publish");

        $toggleRes->assertStatus(200);
        $this->assertFalse($toggleRes->json('is_published'));
        $this->assertDatabaseHas('flow_templates', [
            'id' => $template->id,
            'is_published' => false,
        ]);

        // Tenant does NOT see unpublished draft template
        $tenantTemplatesRes = $this->actingAs($this->tenantUser, 'sanctum')
            ->getJson('/api/flows/templates');
        $tenantTemplatesRes->assertStatus(200);
        $this->assertFalse(collect($tenantTemplatesRes->json())->contains('slug', 'lead-capture'));

        // Superadmin publishes it again
        $this->actingAs($this->admin, 'admin')
            ->postJson("/api/admin/flow-templates/{$template->id}/toggle-publish");

        // Tenant now sees published template
        $tenantTemplatesRes2 = $this->actingAs($this->tenantUser, 'sanctum')
            ->getJson('/api/flows/templates');
        $tenantTemplatesRes2->assertStatus(200);
        $this->assertTrue(collect($tenantTemplatesRes2->json())->contains('slug', 'lead-capture'));
    }

    public function test_tenant_can_instantiate_custom_dynamic_template(): void
    {
        $template = FlowTemplate::create([
            'slug' => 'black-friday-flash-sale',
            'name' => 'Black Friday Flash Sale',
            'category' => 'Marketing',
            'description' => 'Flash promo voucher broadcast and checkout tracker.',
            'trigger_type' => 'inbound_message',
            'trigger_keywords' => ['blackfriday', 'sale'],
            'definition' => [
                'nodes' => [
                    [
                        'id' => 'trig_1',
                        'type' => 'inbound_message',
                        'position' => ['x' => 200, 'y' => 50],
                        'data' => ['title' => 'Keyword Trigger', 'keyword' => 'blackfriday']
                    ],
                    [
                        'id' => 'msg_1',
                        'type' => 'send_message',
                        'position' => ['x' => 200, 'y' => 200],
                        'data' => ['title' => 'Promo code', 'body' => 'Use code BF50 for 50% discount!']
                    ]
                ],
                'edges' => [
                    ['id' => 'e1', 'source' => 'trig_1', 'target' => 'msg_1']
                ]
            ],
            'nodes_count' => 2,
            'is_published' => true,
        ]);

        $instantiateRes = $this->actingAs($this->tenantUser, 'sanctum')
            ->postJson('/api/flows/from-template', [
                'template_id' => $template->slug,
                'name' => 'My Store Flash Sale Campaign',
            ]);

        $instantiateRes->assertStatus(201);
        $instantiateRes->assertJsonPath('name', 'My Store Flash Sale Campaign');
        $instantiateRes->assertJsonPath('trigger_type', 'inbound_message');

        $this->assertDatabaseHas('flows', [
            'tenant_id' => $this->tenant->id,
            'name' => 'My Store Flash Sale Campaign',
        ]);
    }
}
