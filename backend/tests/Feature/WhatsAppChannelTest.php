<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Tenant;
use App\Models\ChannelConnection;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Channels\ChannelManager;
use App\Jobs\SendOutboundMessageJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class WhatsAppChannelTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['company_name' => 'WhatsApp Test Org']);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'John',
            'last_name' => 'Agent',
            'email' => 'agent@test.com',
            'password' => bcrypt('secret123'),
        ]);
    }

    /**
     * Test connection credentials storage and masking.
     */
    public function test_whatsapp_connection_management_endpoints(): void
    {
        // Authenticate user
        $this->actingAs($this->user);

        // 1. Get status - should be disconnected initially
        $response = $this->getJson('/api/integrations/whatsapp/status');
        $response->assertStatus(200);
        $response->assertJson(['connected' => false]);

        // 2. Connect integration
        $payload = [
            'name' => 'Meta Main Line',
            'phone_number_id' => '123456789',
            'whatsapp_business_account_id' => '987654321',
            'system_user_access_token' => 'meta_access_token_xyz',
            'webhook_secret' => 'meta_secret_key',
        ];

        $responseConnect = $this->postJson('/api/integrations/whatsapp/connect', $payload);
        $responseConnect->assertStatus(200);
        $responseConnect->assertJson([
            'connected' => true,
            'name' => 'Meta Main Line',
            'phone_number_id' => '123456789',
        ]);

        $this->assertDatabaseHas('channel_connections', [
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'whatsapp',
            'name' => 'Meta Main Line',
        ]);

        // 3. Status checks details
        $responseStatus = $this->getJson('/api/integrations/whatsapp/status');
        $responseStatus->assertStatus(200);
        $responseStatus->assertJson([
            'connected' => true,
            'phone_number_id' => '123456789',
        ]);

        // 4. Disconnect connection
        $responseDisconnect = $this->deleteJson('/api/integrations/whatsapp/disconnect');
        $responseDisconnect->assertStatus(200);
        $responseDisconnect->assertJson(['connected' => false]);

        $this->assertDatabaseMissing('channel_connections', [
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'whatsapp',
        ]);
    }

    /**
     * Test Webhook Normalization for text messages.
     */
    public function test_whatsapp_inbound_normalization(): void
    {
        $manager = app(ChannelManager::class);
        $driver = $manager->driver('whatsapp');

        // Meta WhatsApp Webhook Payload structure for text messages
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => 'waba_id_123',
                    'changes' => [
                        [
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'metadata' => [
                                    'display_phone_number' => '15550000000',
                                    'phone_number_id' => '123456789',
                                ],
                                'contacts' => [
                                    [
                                        'profile' => [
                                            'name' => 'Alice Cooper'
                                        ],
                                        'wa_id' => '15558888888'
                                    ]
                                ],
                                'messages' => [
                                    [
                                        'from' => '15558888888',
                                        'id' => 'wamid.HBgLMTU1NTg4ODg4ODgVAgIGGAIA',
                                        'timestamp' => '1603222222',
                                        'text' => [
                                            'body' => 'I would like to inquire about pricing.'
                                        ],
                                        'type' => 'text'
                                    ]
                                ]
                            ],
                            'field' => 'messages'
                        ]
                    ]
                ]
            ]
        ];

        $inbound = $driver->normalizeInboundPayload($payload);

        $this->assertEquals('15558888888', $inbound['external_chat_id']);
        $this->assertEquals('Alice Cooper', $inbound['sender_identifier']);
        $this->assertEquals('wamid.HBgLMTU1NTg4ODg4ODgVAgIGGAIA', $inbound['external_message_id']);
        $this->assertEquals('text', $inbound['message_type']);
        $this->assertEquals('I would like to inquire about pricing.', $inbound['body']);
    }

    /**
     * Test signature comparison.
     */
    public function test_whatsapp_signature_verification(): void
    {
        $manager = app(ChannelManager::class);
        $driver = $manager->driver('whatsapp');

        $credentials = ['webhook_secret' => 'waba_secret_token'];
        $rawPayload = '{"object":"whatsapp_business_account"}';

        // Correct signature header
        $validHash = hash_hmac('sha256', $rawPayload, 'waba_secret_token');
        $validHeaders = ['X-Hub-Signature-256' => "sha256={$validHash}"];

        $this->assertTrue($driver->verifyWebhookSignature($validHeaders, $rawPayload, $credentials));

        // Incorrect signature
        $invalidHeaders = ['X-Hub-Signature-256' => 'sha256=wrong_hash_value'];
        $this->assertFalse($driver->verifyWebhookSignature($invalidHeaders, $rawPayload, $credentials));
    }

    /**
     * Test session window enforcement block on Outbound sends.
     */
    public function test_session_window_enforcement_blocks_freeform_text(): void
    {
        Event::fake([\App\Events\MessageStatusUpdated::class]);

        $connection = ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'whatsapp',
            'name' => 'WhatsApp Line',
            'credentials' => [
                'phone_number_id' => '123',
                'system_user_access_token' => 'abc'
            ]
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'channel_connection_id' => $connection->id,
            'external_chat_id' => '15559999999',
        ]);

        // Scenario A: No inbound messages ever -> Send should block
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'body' => 'Should block due to no inbound activity',
            'delivery_status' => 'sent',
        ]);

        $job = new SendOutboundMessageJob($message->id);
        $job->handle(app(ChannelManager::class));

        $message->refresh();
        $this->assertEquals('failed', $message->delivery_status);
        $this->assertStringContainsString('Outside 24-hour customer support window', $message->error_message);

        // Scenario B: Last inbound message was 25 hours ago -> Send should block
        $conversation->update(['last_message_at' => now()->subHours(25)]);
        $inboundOld = new Message([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'body' => 'Hello',
        ]);
        $inboundOld->created_at = now()->subHours(25);
        $inboundOld->save();

        $message2 = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'body' => 'Should block due to aged session',
            'delivery_status' => 'sent',
        ]);

        $job2 = new SendOutboundMessageJob($message2->id);
        $job2->handle(app(ChannelManager::class));

        $message2->refresh();
        $this->assertEquals('failed', $message2->delivery_status);
        $this->assertStringContainsString('Outside 24-hour customer support window', $message2->error_message);

        // Scenario C: Last inbound message was 5 hours ago -> Send allowed (we mock Http call to Graph API)
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['input' => '15559999999', 'wa_id' => '15559999999']],
                'messages' => [['id' => 'wamid.HBgLMTU1NTk5OTk5OTkVAgIGGAIA']]
            ], 200)
        ]);

        $inboundNew = new Message([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'body' => 'I am active now',
        ]);
        $inboundNew->created_at = now()->subHours(5);
        $inboundNew->save();

        $message3 = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'body' => 'This should succeed',
            'delivery_status' => 'sent',
        ]);

        $job3 = new SendOutboundMessageJob($message3->id);
        $job3->handle(app(ChannelManager::class));

        $message3->refresh();
        $this->assertEquals('sent', $message3->delivery_status);
        $this->assertEquals('wamid.HBgLMTU1NTk5OTk5OTkVAgIGGAIA', $message3->external_message_id);
    }
}
