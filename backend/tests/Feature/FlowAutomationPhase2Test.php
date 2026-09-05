<?php

namespace Tests\Feature;

use App\Models\ChannelConnection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowExecution;
use App\Models\FlowVersion;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Jobs\ProcessInboundMessageJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FlowAutomationPhase2Test extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;
    protected ChannelConnection $channel;

    protected function setUp(): void
    {
        parent::setUp();

        \App\Models\Plan::create([
            'id' => 1,
            'name' => 'Free Plan',
            'description' => 'Free description',
            'is_active' => true,
            'trial_days' => 14,
            'max_team_members' => 5,
            'max_campaigns' => 5,
            'max_integrations' => 5,
            'own_crm_access' => true,
            'max_channels' => 5,
            'max_automations' => 5,
            'price_monthly' => 0,
            'price_yearly' => 0,
        ]);

        $this->tenant = Tenant::create([
            'company_name' => 'Phase 2 Test Tenant',
            'plan_id' => 1,
            'status' => 'active',
            'onboarding_step' => 'complete'
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Flow',
            'last_name' => 'Admin',
            'email' => 'flowadmin_' . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->channel = ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'whatsapp_baileys',
            'name' => 'Official Support WA',
            'is_connected' => true,
            'status' => 'connected',
            'account_id' => '1234567890',
            'credentials' => ['session_id' => 'test_session']
        ]);

        $this->seed(\Database\Seeders\FlowTemplateSeeder::class);
    }

    public function test_get_templates_returns_5_blueprints()
    {
        $response = $this->actingAs($this->user)->getJson('/api/flows/templates');
        $response->assertStatus(200);

        $templates = $response->json();
        $this->assertCount(5, $templates);
        $slugs = array_column($templates, 'slug');
        $this->assertContains('lead_qualification', $slugs);
        $this->assertContains('welcome_menu', $slugs);
        $this->assertContains('booking_scheduler', $slugs);
        $this->assertContains('cart_recovery', $slugs);
        $this->assertContains('support_triage', $slugs);
    }

    public function test_instantiate_flow_from_template()
    {
        $response = $this->actingAs($this->user)->postJson('/api/flows/from-template', [
            'template_id' => 'lead_qualification',
            'name' => 'VIP Lead Qualification Flow'
        ]);

        $response->assertStatus(201);
        $data = $response->json();

        $this->assertEquals('VIP Lead Qualification Flow', $data['name']);
        $this->assertEquals('inbound_message', $data['trigger_type']);
        $this->assertCount(1, $data['versions']);

        $version = $data['versions'][0];
        $this->assertGreaterThan(5, count($version['definition']['nodes']));
        $this->assertGreaterThan(5, count($version['definition']['edges']));
    }

    public function test_create_custom_flow_with_channels_and_keywords()
    {
        $response = $this->actingAs($this->user)->postJson('/api/flows', [
            'name' => 'Keyword Pricing Bot',
            'description' => 'Triggered on pricing or quote keywords',
            'trigger_type' => 'keyword',
            'trigger_keywords' => ['pricing', 'quote', 'cost'],
            'channel_ids' => [$this->channel->id],
            'channel_type' => 'omnichannel'
        ]);

        $response->assertStatus(201);
        $flow = $response->json();

        $this->assertEquals('Keyword Pricing Bot', $flow['name']);
        $this->assertEquals(['pricing', 'quote', 'cost'], $flow['trigger_keywords']);
        $this->assertCount(1, $flow['channels']);
        $this->assertEquals($this->channel->id, $flow['channels'][0]['id']);
    }

    public function test_duplicate_flow_copies_canvas_and_channels()
    {
        $original = Flow::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Original Customer Welcome',
            'trigger_type' => 'inbound_message',
            'trigger_keywords' => ['hello', 'hi'],
            'channel_type' => 'omnichannel',
            'is_active' => true
        ]);
        $original->channels()->attach($this->channel->id);

        $ver = FlowVersion::create([
            'flow_id' => $original->id,
            'version_number' => 1,
            'is_published' => true,
            'created_by' => $this->user->id,
            'definition' => [
                'nodes' => [
                    ['id' => 'trigger_1', 'type' => 'inbound_message', 'position' => ['x' => 100, 'y' => 100], 'data' => ['title' => 'Start']],
                    ['id' => 'msg_1', 'type' => 'send_message', 'position' => ['x' => 100, 'y' => 250], 'data' => ['body' => 'Welcome to our store!']]
                ],
                'edges' => [
                    ['id' => 'e1', 'source' => 'trigger_1', 'target' => 'msg_1', 'sourceHandle' => 'out', 'targetHandle' => 'in']
                ]
            ]
        ]);
        $original->update(['current_published_version_id' => $ver->id]);

        $response = $this->actingAs($this->user)->postJson("/api/flows/{$original->id}/duplicate");
        $response->assertStatus(201);

        $duplicated = $response->json();
        $this->assertEquals('Original Customer Welcome (Copy)', $duplicated['name']);
        $this->assertFalse($duplicated['is_active']);
        $this->assertCount(1, $duplicated['channels']);
        $this->assertEquals($this->channel->id, $duplicated['channels'][0]['id']);
        $this->assertCount(1, $duplicated['versions']);
        $this->assertCount(2, $duplicated['versions'][0]['definition']['nodes']);
    }

    public function test_toggle_active_flow()
    {
        $flow = Flow::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Status Toggle Test Flow',
            'trigger_type' => 'inbound_message',
            'channel_type' => 'omnichannel',
            'is_active' => false
        ]);

        // Attempting to activate with no published version should fail
        $response = $this->actingAs($this->user)->postJson("/api/flows/{$flow->id}/toggle-active");
        $response->assertStatus(400);

        // Publish a version
        $ver = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'is_published' => true,
            'created_by' => $this->user->id,
            'definition' => ['nodes' => [], 'edges' => []]
        ]);
        $flow->update(['current_published_version_id' => $ver->id]);

        // Activate flow
        $response = $this->actingAs($this->user)->postJson("/api/flows/{$flow->id}/toggle-active");
        $response->assertStatus(200);
        $this->assertTrue($response->json()['is_active']);

        // Deactivate flow
        $response = $this->actingAs($this->user)->postJson("/api/flows/{$flow->id}/toggle-active");
        $response->assertStatus(200);
        $this->assertFalse($response->json()['is_active']);
    }

    public function test_5_tier_inbound_message_routing_priority_2_keyword_match()
    {
        // Setup contact & conversation
        $contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'phone' => '+1555123456',
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'contact_id' => $contact->id,
            'channel_connection_id' => $this->channel->id,
            'external_chat_id' => '1555123456@s.whatsapp.net',
            'status' => 'active'
        ]);

        // Create active flow with keyword
        $flow = Flow::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Pricing Keyword Flow',
            'trigger_type' => 'keyword',
            'trigger_keywords' => ['pricing', 'quote'],
            'channel_type' => 'omnichannel',
            'is_active' => true
        ]);
        $flow->channels()->attach($this->channel->id);

        $ver = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'is_published' => true,
            'created_by' => $this->user->id,
            'definition' => [
                'nodes' => [
                    ['id' => 'trigger_1', 'type' => 'keyword', 'position' => ['x' => 0, 'y' => 0], 'data' => ['title' => 'Keyword']],
                    ['id' => 'msg_1', 'type' => 'send_message', 'position' => ['x' => 0, 'y' => 100], 'data' => ['body' => 'Here is our pricing list!']]
                ],
                'edges' => [
                    ['id' => 'e1', 'source' => 'trigger_1', 'target' => 'msg_1', 'sourceHandle' => 'out', 'targetHandle' => 'in']
                ]
            ]
        ]);
        $flow->update(['current_published_version_id' => $ver->id]);

        $job = new ProcessInboundMessageJob(
            $this->channel->id,
            [
                'external_chat_id' => '1555123456@s.whatsapp.net',
                'sender_identifier' => '+1555123456',
                'external_message_id' => 'msg_' . uniqid(),
                'message_type' => 'text',
                'body' => 'Hello, can I get a pricing quote please?'
            ]
        );

        app()->call([$job, 'handle']);

        // Verify Flow Execution was triggered
        $this->assertDatabaseHas('flow_executions', [
            'tenant_id' => $this->tenant->id,
            'flow_version_id' => $ver->id,
            'contact_id' => $contact->id
        ]);
    }

    public function test_create_flow_without_trigger_type_and_auto_sync_canvas_trigger()
    {
        // 1. Create flow with only name and description (no trigger_type passed)
        $response = $this->actingAs($this->user)->postJson('/api/flows', [
            'name' => 'Studio Canvas Flow',
            'description' => 'Created directly without dialog trigger selection'
        ]);

        $response->assertStatus(201);
        $flow = $response->json();

        $this->assertEquals('Studio Canvas Flow', $flow['name']);
        $this->assertEquals('inbound_message', $flow['trigger_type']);
        $this->assertCount(1, $flow['versions']);

        $flowId = $flow['id'];

        // 2. In designer canvas, change trigger node to webhook_trigger and save version
        $saveResponse = $this->actingAs($this->user)->postJson("/api/flows/{$flowId}/versions", [
            'definition' => [
                'nodes' => [
                    [
                        'id' => 'trigger_1',
                        'type' => 'webhook_trigger',
                        'position' => ['x' => 100, 'y' => 100],
                        'data' => [
                            'title' => 'API Webhook Inflow',
                            'url' => '/api/v1/webhooks/flows/webhook_custom',
                            'is_root_trigger' => true
                        ]
                    ],
                    [
                        'id' => 'msg_1',
                        'type' => 'send_message',
                        'position' => ['x' => 100, 'y' => 250],
                        'data' => ['body' => 'Webhook received!']
                    ]
                ],
                'edges' => [
                    ['id' => 'e1', 'source' => 'trigger_1', 'target' => 'msg_1', 'sourceHandle' => 'out', 'targetHandle' => 'in']
                ]
            ]
        ]);

        $saveResponse->assertStatus(200);

        // 3. Verify that the parent flow container automatically updated its trigger_type
        $updatedFlow = Flow::findOrFail($flowId);
        $this->assertEquals('webhook_trigger', $updatedFlow->trigger_type);
    }
}

