<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Contact;
use App\Models\ChannelConnection;
use App\Models\MessageTemplate;
use App\Models\AiAgent;
use App\Models\AIProviderConfig;
use App\Models\Campaign;
use App\Models\CampaignDispatch;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use App\Jobs\ProcessCampaignBroadcastJob;
use Tests\TestCase;

class CampaignBroadcastTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Tenant $tenant;
    protected ChannelConnection $whatsappChannel;
    protected ChannelConnection $emailChannel;
    protected Contact $contact1;
    protected Contact $contact2;
    protected Contact $contactOptedOut;

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
            'sort_order' => 1
        ]);

        $this->tenant = Tenant::create([
            'company_name' => 'Campaigns Inc',
            'status' => 'trial',
            'onboarding_step' => 'complete',
            'plan_id' => 1
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'email' => 'grace@campaigns.io',
            'password' => Hash::make('Hopper123!'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->whatsappChannel = ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'whatsapp',
            'name' => 'Campaign WhatsApp',
            'status' => 'connected',
            'credentials' => [
                'phone_number_id' => '1234567890',
                'system_user_access_token' => 'mock_token',
                'whatsapp_business_account_id' => '0987654321',
            ],
        ]);

        $this->emailChannel = ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'email',
            'name' => 'Campaign Email',
            'status' => 'connected',
            'credentials' => [
                'provider' => 'sandbox',
                'email_address' => 'noreply@whatsomni.io',
            ],
        ]);

        $this->contact1 = Contact::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Alice',
            'last_name' => 'Smith',
            'email' => 'alice@smith.com',
            'phone' => '+15550101',
            'lifecycle_stage' => 'lead',
            'tags' => ['vip', 'promo']
        ]);

        $this->contact2 = Contact::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Bob',
            'last_name' => 'Jones',
            'email' => 'bob@jones.com',
            'phone' => '+15550102',
            'lifecycle_stage' => 'customer',
            'tags' => ['promo']
        ]);

        $this->contactOptedOut = Contact::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Charlie',
            'last_name' => 'Brown',
            'email' => 'charlie@brown.com',
            'phone' => '+15550103',
            'lifecycle_stage' => 'lead',
            'opted_out_channels' => ['whatsapp'],
            'tags' => ['promo']
        ]);
    }

    /**
     * Test Campaign API basic actions (store, index, show, delete)
     */
    public function test_campaign_crud_actions()
    {
        $this->actingAs($this->user);

        $template = MessageTemplate::create([
            'tenant_id' => $this->tenant->id,
            'channel_connection_id' => $this->emailChannel->id,
            'name' => 'newsletter_template',
            'type' => 'email',
            'category' => 'marketing',
            'status' => 'ready',
            'content' => [
                'blocks' => [
                    ['type' => 'paragraph', 'content' => 'Hello {{ contact.first_name }}!']
                ]
            ]
        ]);

        // 1. Create campaign
        $response = $this->postJson('/api/campaigns', [
            'name' => 'Promo Campaign',
            'channel_connection_id' => $this->emailChannel->id,
            'audience_filter' => [
                'type' => 'tags',
                'value' => 'promo',
            ],
            'source_type' => 'template',
            'message_template_id' => $template->id,
            'schedule_type' => 'immediate',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('name', 'Promo Campaign');
        $response->assertJsonPath('status', 'sending');

        $campaignId = $response->json('id');

        // 2. Index campaigns
        $indexRes = $this->getJson('/api/campaigns');
        $indexRes->assertStatus(200);
        $this->assertCount(1, $indexRes->json());

        // 3. Show campaign
        $showRes = $this->getJson("/api/campaigns/{$campaignId}");
        $showRes->assertStatus(200);
        $showRes->assertJsonStructure(['campaign', 'dispatches']);

        // 4. Delete campaign
        $deleteRes = $this->deleteJson("/api/campaigns/{$campaignId}");
        $deleteRes->assertStatus(200);
        $this->assertDatabaseMissing('campaigns', ['id' => $campaignId]);
    }

    /**
     * Test immediate template campaign execution
     */
    public function test_immediate_template_campaign_execution()
    {
        $template = MessageTemplate::create([
            'tenant_id' => $this->tenant->id,
            'channel_connection_id' => $this->whatsappChannel->id,
            'name' => 'whatsapp_promo',
            'type' => 'whatsapp',
            'category' => 'utility',
            'status' => 'approved',
            'content' => [
                'body' => ['text' => 'Hi {{1}}, welcome!']
            ]
        ]);

        $campaign = Campaign::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'WhatsApp Broadcast',
            'channel_connection_id' => $this->whatsappChannel->id,
            'audience_filter' => [
                'type' => 'all',
                'value' => null
            ],
            'source_type' => 'template',
            'message_template_id' => $template->id,
            'schedule_type' => 'immediate',
            'status' => 'sending',
        ]);

        Http::fake([
            'https://graph.facebook.com/v19.0/1234567890/messages' => Http::response([
                'messages' => [
                    ['id' => 'wamid.HBgLMjM0Nzg5MzQ1MTEVAgASGBIxMjM0NTY3ODkwMTIzNDU3OAA=']
                ]
            ], 200)
        ]);

        // Run the job synchronously
        ProcessCampaignBroadcastJob::dispatchSync($campaign->id);

        $campaign->refresh();
        $this->assertEquals('completed', $campaign->status);
        $this->assertEquals(3, $campaign->total_contacts); // Alice, Bob, Charlie (opted-out)
        $this->assertEquals(2, $campaign->sent_count); // Alice, Bob sent successfully
        $this->assertEquals(1, $campaign->failed_count); // Charlie (opted-out)

        // Verify opted out is marked failed
        $charlieDispatch = CampaignDispatch::where('campaign_id', $campaign->id)
            ->where('contact_id', $this->contactOptedOut->id)
            ->first();
        $this->assertNotNull($charlieDispatch);
        $this->assertEquals('failed', $charlieDispatch->status);
        $this->assertEquals('Contact has opted out of this channel.', $charlieDispatch->error_message);
    }

    /**
     * Test immediate AI agent campaign execution
     */
    public function test_immediate_agent_campaign_execution()
    {
        $providerConfig = AIProviderConfig::create([
            'tenant_id' => $this->tenant->id,
            'provider_name' => 'openai',
            'api_key' => 'mock_api_key',
            'is_active' => true,
            'enabled_models' => ['gpt-4o', 'gpt-4o-mini'],
        ]);

        $flow = \App\Models\Flow::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Agent Automation Flow',
            'description' => 'Attached Flow',
            'trigger_type' => 'outbound_campaign',
            'status' => 'published',
            'created_by' => $this->user->id,
        ]);

        \App\Models\FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => 'published',
            'created_by' => $this->user->id,
            'definition' => [
                'nodes' => [
                    [
                        'id' => 'node_1',
                        'type' => 'outbound_campaign',
                        'data' => ['title' => 'Start Campaign']
                    ],
                    [
                        'id' => 'node_2',
                        'type' => 'send_message',
                        'data' => ['title' => 'Greeting', 'body' => 'Welcome {{ contact.first_name }} to our AI agent campaign!']
                    ]
                ],
                'edges' => [
                    [
                        'id' => 'edge_1',
                        'source' => 'node_1',
                        'target' => 'node_2',
                        'sourceHandle' => 'out'
                    ]
                ]
            ]
        ]);

        $agent = AiAgent::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Outbound Chatbot',
            'type' => 'outbound',
            'ai_provider_config_id' => $providerConfig->id,
            'flow_id' => $flow->id,
            'model' => 'gpt-4o-mini',
            'system_prompt' => 'You are a warm customer success agent.',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $campaign = Campaign::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'AI Outbound Agent Run',
            'channel_connection_id' => $this->emailChannel->id,
            'audience_filter' => [
                'type' => 'lifecycle_stage',
                'value' => 'lead' // Alice, Charlie
            ],
            'source_type' => 'agent',
            'ai_agent_id' => $agent->id,
            'schedule_type' => 'immediate',
            'status' => 'sending',
        ]);

        // Run the job synchronously
        ProcessCampaignBroadcastJob::dispatchSync($campaign->id);

        $campaign->refresh();
        $this->assertEquals('completed', $campaign->status);
        $this->assertEquals(2, $campaign->total_contacts); // Alice, Charlie
        $this->assertEquals(2, $campaign->sent_count); // Alice, Charlie sent (opted out only applies to WhatsApp)
    }

    /**
     * Test immediate flow campaign execution
     */
    public function test_immediate_flow_campaign_execution()
    {
        $flow = \App\Models\Flow::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Outbound Campaign Flow',
            'description' => 'Test Flow',
            'trigger_type' => 'outbound_campaign',
            'status' => 'published',
            'created_by' => $this->user->id,
        ]);

        \App\Models\FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => 'published',
            'created_by' => $this->user->id,
            'definition' => [
                'nodes' => [
                    [
                        'id' => 'node_1',
                        'type' => 'outbound_campaign',
                        'data' => ['title' => 'Start Campaign']
                    ],
                    [
                        'id' => 'node_2',
                        'type' => 'send_message',
                        'data' => [
                            'title' => 'Greeting',
                            'body' => 'Welcome {{ contact.first_name }} to our special campaign!'
                        ]
                    ]
                ],
                'edges' => [
                    [
                        'id' => 'edge_1',
                        'source' => 'node_1',
                        'target' => 'node_2',
                        'sourceHandle' => 'out'
                    ]
                ]
            ]
        ]);

        $campaign = Campaign::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Flow Campaign Run',
            'channel_connection_id' => $this->emailChannel->id,
            'audience_filter' => [
                'type' => 'lifecycle_stage',
                'value' => 'lead'
            ],
            'source_type' => 'flow',
            'flow_id' => $flow->id,
            'schedule_type' => 'immediate',
            'status' => 'sending',
        ]);

        ProcessCampaignBroadcastJob::dispatchSync($campaign->id);

        $campaign->refresh();
        $this->assertEquals('completed', $campaign->status);
        $this->assertEquals(2, $campaign->total_contacts);
        $this->assertEquals(2, $campaign->sent_count);

        $this->assertDatabaseHas('flow_executions', [
            'tenant_id' => $this->tenant->id,
            'status' => 'completed',
        ]);
    }

    /**
     * Test scheduled command poller triggers execution
     */
    public function test_scheduled_campaign_poller_trigger()
    {
        $template = MessageTemplate::create([
            'tenant_id' => $this->tenant->id,
            'channel_connection_id' => $this->emailChannel->id,
            'name' => 'scheduled_email',
            'type' => 'email',
            'status' => 'ready',
            'content' => [
                'blocks' => [
                    ['type' => 'paragraph', 'content' => 'Hello.']
                ]
            ]
        ]);

        $campaign = Campaign::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Delayed Broadcast',
            'channel_connection_id' => $this->emailChannel->id,
            'audience_filter' => [
                'type' => 'all',
                'value' => null
            ],
            'source_type' => 'template',
            'message_template_id' => $template->id,
            'schedule_type' => 'scheduled',
            'scheduled_at' => now()->subMinute(),
            'status' => 'scheduled',
        ]);

        Queue::fake();

        $this->artisan('campaigns:dispatch')
             ->expectsOutput("Dispatched broadcast campaign ID {$campaign->id} ({$campaign->name}).")
             ->assertExitCode(0);

        Queue::assertPushed(ProcessCampaignBroadcastJob::class, function ($job) use ($campaign) {
            return $job->campaignId === $campaign->id;
        });

        $campaign->refresh();
        $this->assertEquals('sending', $campaign->status);
    }

    /**
     * Test message delivery webhook and replied callbacks updates campaign dispatches
     */
    public function test_webhook_and_replies_update_analytics()
    {
        $campaign = Campaign::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Webhook Sync Test',
            'channel_connection_id' => $this->whatsappChannel->id,
            'audience_filter' => ['type' => 'all', 'value' => null],
            'source_type' => 'template',
            'schedule_type' => 'immediate',
            'status' => 'sending',
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'channel_connection_id' => $this->whatsappChannel->id,
            'contact_id' => $this->contact1->id,
            'external_chat_id' => '+15550101',
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'body' => 'Hello Alice!',
            'external_message_id' => 'mock_wamid_123',
            'delivery_status' => 'sent',
        ]);

        $dispatch = CampaignDispatch::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $this->contact1->id,
            'message_id' => $message->id,
            'status' => 'sent',
        ]);

        // 1. Simulate delivery webhook update status to read
        $message->update(['delivery_status' => 'read']);

        $dispatch->refresh();
        $campaign->refresh();
        $this->assertEquals('read', $dispatch->status);
        $this->assertEquals(1, $campaign->read_count);

        // 2. Simulate inbound user message (replied)
        $inboundMsg = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'body' => 'Yes, I am interested!',
            'external_message_id' => 'mock_reply_inbound',
            'delivery_status' => 'sent',
        ]);

        $dispatch->refresh();
        $campaign->refresh();
        $this->assertEquals('replied', $dispatch->status);
        $this->assertEquals(1, $campaign->replied_count);
    }

    /**
     * Test immediate composed message campaign execution with merge tags
     */
    public function test_immediate_compose_campaign_execution()
    {
        $campaign = Campaign::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Composed Message Campaign',
            'channel_connection_id' => $this->whatsappChannel->id,
            'audience_filter' => [
                'type' => 'all',
                'value' => null
            ],
            'source_type' => 'compose',
            'custom_message' => 'Hello {{ contact.first_name }}, your special promo is ready!',
            'schedule_type' => 'immediate',
            'status' => 'sending',
        ]);

        Http::fake([
            'https://graph.facebook.com/v19.0/1234567890/messages' => Http::response([
                'messages' => [
                    ['id' => 'wamid.HBgLMjM0Nzg5MzQ1MTEVAgASGBIxMjM0NTY3ODkwMTIzNDU3OAA=']
                ]
            ], 200)
        ]);

        // Run the job synchronously
        ProcessCampaignBroadcastJob::dispatchSync($campaign->id);

        $campaign->refresh();
        $this->assertEquals('completed', $campaign->status);
        $this->assertEquals(3, $campaign->total_contacts);
        $this->assertEquals(2, $campaign->sent_count);

        // Verify sent message body contains personalized name
        $aliceDispatch = CampaignDispatch::where('campaign_id', $campaign->id)
            ->where('contact_id', $this->contact1->id)
            ->with('message')
            ->first();

        $this->assertNotNull($aliceDispatch);
        $this->assertEquals('sent', $aliceDispatch->status);
        $this->assertStringContainsString('Hello Alice', $aliceDispatch->message->body);
    }
}

