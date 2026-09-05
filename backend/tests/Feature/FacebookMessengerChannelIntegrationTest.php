<?php

namespace Tests\Feature;

use App\Models\ChannelConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Channels\Drivers\FacebookMessengerDriver;
use App\Services\Channels\ChannelManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FacebookMessengerChannelIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['company_name' => 'Facebook Messenger Sandbox Org']);
        $this->user = User::create([
            'first_name' => 'Messenger',
            'last_name' => 'Tester',
            'email' => 'sandbox@messengerflow.io',
            'password' => bcrypt('password123'),
            'tenant_id' => $this->tenant->id,
        ]);
    }

    /**
     * Test querying status without authentication.
     */
    public function test_messenger_status_requires_auth()
    {
        $response = $this->getJson('/api/integrations/messenger/status');
        $response->assertStatus(401);
    }

    /**
     * Test status endpoint returns correct connection details.
     */
    public function test_messenger_status_returns_details()
    {
        // 1. Initially disconnected
        $response = $this->actingAs($this->user)->getJson('/api/integrations/messenger/status');
        $response->assertStatus(200);
        $response->assertJson(['connected' => false]);

        // 2. Connected
        ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'messenger',
            'name' => 'Acme Support Page',
            'status' => 'connected',
            'credentials' => [
                'page_access_token' => 'my-page-token',
                'page_id' => 'page-123',
                'webhook_verify_token' => 'my-verify-token',
            ],
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/integrations/messenger/status');
        $response->assertStatus(200);
        $response->assertJson([
            'connected' => true,
            'name' => 'Acme Support Page',
            'page_id' => 'page-123',
            'webhook_verify_token' => 'my-verify-token',
        ]);
    }



    /**
     * Test disconnecting deletes the channel connection.
     */
    public function test_messenger_disconnect()
    {
        ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'messenger',
            'name' => 'Messenger Support',
            'status' => 'connected',
            'credentials' => ['page_access_token' => 'mock_token'],
        ]);

        $response = $this->actingAs($this->user)->deleteJson('/api/integrations/messenger/disconnect');
        $response->assertStatus(200);

        $connection = ChannelConnection::where('tenant_id', $this->tenant->id)
            ->where('channel_type', 'messenger')
            ->first();

        $this->assertNull($connection);
    }

    /**
     * Test Webhook challenge passes validation.
     */
    public function test_webhook_challenge_verifies()
    {
        $connection = ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'messenger',
            'name' => 'Messenger Sandbox',
            'status' => 'connected',
            'credentials' => ['webhook_verify_token' => 'my-secret-token'],
        ]);

        $response = $this->get('/api/webhooks/channel/' . $connection->id . '?' . http_build_query([
            'hub_mode' => 'subscribe',
            'hub_verify_token' => 'my-secret-token',
            'hub_challenge' => 'hello_world_challenge',
        ]));

        $response->assertStatus(200);
        $response->assertSeeText('hello_world_challenge');
    }

    /**
     * Test Driver payload normalization.
     */
    public function test_driver_payload_normalization()
    {
        $driver = new FacebookMessengerDriver();

        // 1. Inbound direct mock webhook payload
        $inboundMock = [
            'external_chat_id' => '10049281982',
            'sender_identifier' => 'John Doe',
            'body' => 'Hello, can you help me?',
        ];

        $normalized = $driver->normalizeInboundPayload($inboundMock);
        $this->assertEquals('10049281982', $normalized['external_chat_id']);
        $this->assertEquals('John Doe', $normalized['sender_identifier']);
        $this->assertEquals('Hello, can you help me?', $normalized['body']);

        // 2. Official Meta format payload (including postback / quick reply parsing)
        $inboundOfficial = [
            'object' => 'page',
            'entry' => [
                [
                    'id' => 'my-page-id',
                    'messaging' => [
                        [
                          'sender' => ['id' => '9984928190'],
                          'recipient' => ['id' => 'my-page-id'],
                          'message' => [
                              'mid' => 'm_mid_9948',
                              'text' => 'Standard user message text',
                              'quick_reply' => [
                                  'payload' => 'quick_reply_clicked_payload'
                              ]
                          ]
                        ]
                    ]
                ]
            ]
        ];

        $normalizedOfficial = $driver->normalizeInboundPayload($inboundOfficial);
        $this->assertEquals('9984928190', $normalizedOfficial['external_chat_id']);
        $this->assertEquals('m_mid_9948', $normalizedOfficial['external_message_id']);
        $this->assertEquals('quick_reply_clicked_payload', $normalizedOfficial['body']);
    }

    /**
     * Test driver sends messages through Meta Graph mock response.
     */
    public function test_driver_sends_messages()
    {
        $driver = new FacebookMessengerDriver();

        // 1. Sandbox mock connection message sending
        $sandboxCreds = [
            'page_access_token' => 'mock_token',
            'page_id' => 'mock_page_id',
        ];

        $res = $driver->sendMessage($sandboxCreds, [
            'external_chat_id' => 'user_123',
            'body' => 'Hello sandbox user!',
        ]);

        $this->assertEquals('sent', $res['delivery_status']);
        $this->assertStringContainsString('mock_fb_msg_', $res['external_message_id']);

        // 2. Official HTTP request sending with fake response
        Http::fake([
            'https://graph.facebook.com/v20.0/me/messages' => Http::response([
                'message_id' => 'meta_msg_9849128',
            ], 200),
        ]);

        $officialCreds = [
            'page_access_token' => 'real_token_value',
            'page_id' => 'real_page_id',
        ];

        $res = $driver->sendMessage($officialCreds, [
            'external_chat_id' => 'real_user_id',
            'body' => 'Hello official!',
        ]);

        $this->assertEquals('sent', $res['delivery_status']);
        $this->assertEquals('meta_msg_9849128', $res['external_message_id']);
    }
}
