<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Contact;
use App\Models\ChannelConnection;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowVersion;
use App\Models\Campaign;
use App\Models\CampaignDispatch;
use App\Models\Deal;
use App\Jobs\ProcessCampaignBroadcastJob;
use App\Services\Channels\ChannelManager;
use App\Services\AIProviderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FlowOutboundCampaignChannelTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        \App\Models\Plan::create([
            'id' => 1,
            'name' => 'Enterprise Plan',
            'description' => 'Full enterprise automation',
            'is_active' => true,
            'trial_days' => 30,
            'max_team_members' => 20,
            'max_campaigns' => 50,
            'max_integrations' => 50,
            'own_crm_access' => true,
            'max_channels' => 20,
            'max_automations' => 50,
            'sort_order' => 1
        ]);

        $this->tenant = Tenant::create([
            'company_name' => 'Outbound Flow Test Corp',
            'status' => 'active',
            'onboarding_step' => 'complete',
            'plan_id' => 1
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'John',
            'last_name' => 'Von Neumann',
            'email' => 'john@neumann.org',
            'password' => Hash::make('NeumannPassword123!'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messaging_product' => 'whatsapp',
                'messages' => [['id' => 'wamid.HBgLMTU1NT' . uniqid()]],
                'message_id' => 'mid_' . uniqid(),
            ], 200),
        ]);
    }

    /**
     * TEST 1: Outbound Campaign running an Automation Flow on WhatsApp Channel
     */
    public function test_outbound_campaign_flow_executes_on_whatsapp_channel(): void
    {
        $this->actingAs($this->user);

        // 1. WhatsApp Channel Connection
        $waConnection = ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'WhatsApp Cloud Primary',
            'channel_type' => 'whatsapp',
            'credentials' => [
                'phone_number_id' => '1092837465',
                'waba_id' => '9988776655',
                'system_user_access_token' => 'mock_token',
            ],
            'status' => 'connected',
        ]);

        // 2. Target Contact
        $contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Sarah',
            'last_name' => 'Connor',
            'phone' => '+15551234567',
            'tags' => ['vip_lead'],
        ]);

        // 3. Multi-Node Flow: Trigger -> Send Message -> Tag Contact -> Create Deal
        $flow = Flow::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'WhatsApp VIP Onboarding Flow',
            'trigger_type' => 'outbound_campaign',
            'is_active' => true,
        ]);

        $flowVersion = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'is_published' => true,
            'created_by' => $this->user->id,
            'definition' => [
                'nodes' => [
                    [
                        'id' => 'node_trigger',
                        'type' => 'outbound_campaign',
                        'data' => ['title' => 'Campaign Trigger']
                    ],
                    [
                        'id' => 'node_send_msg',
                        'type' => 'send_message',
                        'data' => [
                            'title' => 'Personalized Welcome',
                            'body' => 'Hi {{ contact.first_name }}, welcome to our exclusive WhatsApp VIP club!'
                        ]
                    ],
                    [
                        'id' => 'node_tag',
                        'type' => 'tag_contact',
                        'data' => [
                            'title' => 'Add Tag',
                            'tag_action' => 'add_tag',
                            'target_tag' => 'campaign_contacted'
                        ]
                    ],
                    [
                        'id' => 'node_deal',
                        'type' => 'create_deal',
                        'data' => [
                            'title' => 'Create High Value Deal',
                            'deal_name' => 'WhatsApp Lead - {{ contact.first_name }}',
                            'deal_value' => 2500,
                            'stage_id' => 'lead'
                        ]
                    ]
                ],
                'edges' => [
                    ['source' => 'node_trigger', 'sourceHandle' => 'out', 'target' => 'node_send_msg', 'targetHandle' => 'in'],
                    ['source' => 'node_send_msg', 'sourceHandle' => 'out', 'target' => 'node_tag', 'targetHandle' => 'in'],
                    ['source' => 'node_tag', 'sourceHandle' => 'out', 'target' => 'node_deal', 'targetHandle' => 'in'],
                ]
            ]
        ]);

        // 4. Create Campaign with Flow source
        $campaign = Campaign::create([
            'tenant_id' => $this->tenant->id,
            'channel_connection_id' => $waConnection->id,
            'name' => 'Q4 WhatsApp VIP Outreach',
            'source_type' => 'flow',
            'flow_id' => $flow->id,
            'schedule_type' => 'immediate',
            'status' => 'draft',
            'audience_filter' => [
                'type' => 'contacts',
                'value' => [$contact->id]
            ]
        ]);

        // 5. Execute Broadcast Job
        $job = new ProcessCampaignBroadcastJob($campaign->id);
        $job->handle(app(ChannelManager::class), app(AIProviderService::class));

        // 6. Assertions
        $campaign->refresh();
        $this->assertEquals('completed', $campaign->status);
        $this->assertEquals(1, $campaign->sent_count);

        // Verify Contact was updated by the flow node
        $contact->refresh();
        $this->assertContains('campaign_contacted', $contact->tags);

        // Verify Deal was created by the flow node
        $deal = Deal::where('tenant_id', $this->tenant->id)->where('contact_id', $contact->id)->first();
        $this->assertNotNull($deal);
        $this->assertEquals('WhatsApp Lead - Sarah', $deal->title);
        $this->assertEquals(2500, $deal->amount);

        // Verify Campaign dispatch record
        $dispatch = CampaignDispatch::where('campaign_id', $campaign->id)->first();
        $this->assertNotNull($dispatch);
        $this->assertEquals('sent', $dispatch->status);
    }

    /**
     * TEST 2: Outbound Campaign running an Automation Flow on Instagram Channel
     */
    public function test_outbound_campaign_flow_executes_on_instagram_channel(): void
    {
        $this->actingAs($this->user);

        // 1. Instagram Channel Connection
        $igConnection = ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Instagram Business Primary',
            'channel_type' => 'instagram',
            'credentials' => [
                'page_access_token' => 'mock_token',
                'instagram_business_account_id' => 'ig_biz_123456',
            ],
            'status' => 'connected',
        ]);

        // 2. Target Contact with existing Instagram conversation (having IGSID)
        $contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Elena',
            'last_name' => 'Rostova',
            'phone' => '+1555998877',
            'tags' => ['ig_influencer'],
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'contact_id' => $contact->id,
            'channel_connection_id' => $igConnection->id,
            'external_chat_id' => 'igsid_9876543210',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        // 3. Multi-Node Flow: Trigger -> Send Message -> Tag Contact
        $flow = Flow::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Instagram Brand Ambassador Outreach',
            'trigger_type' => 'outbound_campaign',
            'is_active' => true,
        ]);

        $flowVersion = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'is_published' => true,
            'created_by' => $this->user->id,
            'definition' => [
                'nodes' => [
                    [
                        'id' => 'node_trigger',
                        'type' => 'outbound_campaign',
                        'data' => ['title' => 'Campaign Trigger']
                    ],
                    [
                        'id' => 'node_send_ig_msg',
                        'type' => 'send_message',
                        'data' => [
                            'title' => 'Send IG DM',
                            'body' => 'Hey {{ contact.first_name }}! We loved your recent post and want to collaborate!'
                        ]
                    ],
                    [
                        'id' => 'node_tag_ig',
                        'type' => 'tag_contact',
                        'data' => [
                            'title' => 'Tag Influencer',
                            'tag_action' => 'add_tag',
                            'target_tag' => 'collab_invited'
                        ]
                    ]
                ],
                'edges' => [
                    ['source' => 'node_trigger', 'sourceHandle' => 'out', 'target' => 'node_send_ig_msg', 'targetHandle' => 'in'],
                    ['source' => 'node_send_ig_msg', 'sourceHandle' => 'out', 'target' => 'node_tag_ig', 'targetHandle' => 'in'],
                ]
            ]
        ]);

        // 4. Create Campaign with Flow source for Instagram
        $campaign = Campaign::create([
            'tenant_id' => $this->tenant->id,
            'channel_connection_id' => $igConnection->id,
            'name' => 'Instagram Influencer Collab Wave 1',
            'source_type' => 'flow',
            'flow_id' => $flow->id,
            'schedule_type' => 'immediate',
            'status' => 'draft',
            'audience_filter' => [
                'type' => 'contacts',
                'value' => [$contact->id]
            ]
        ]);

        // 5. Execute Broadcast Job
        $job = new ProcessCampaignBroadcastJob($campaign->id);
        $job->handle(app(ChannelManager::class), app(AIProviderService::class));

        // 6. Assertions
        $campaign->refresh();
        $this->assertEquals('completed', $campaign->status);
        $this->assertEquals(1, $campaign->sent_count);

        // Verify Contact was tagged by the flow
        $contact->refresh();
        $this->assertContains('collab_invited', $contact->tags);

        // Verify Campaign dispatch record
        $dispatch = CampaignDispatch::where('campaign_id', $campaign->id)->first();
        $this->assertNotNull($dispatch);
        $this->assertEquals('sent', $dispatch->status);
    }
}
