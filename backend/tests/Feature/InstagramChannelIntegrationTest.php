<?php

namespace Tests\Feature;

use App\Models\ChannelConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Channels\Drivers\InstagramDriver;
use App\Services\Channels\ChannelManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InstagramChannelIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['company_name' => 'Instagram Sandbox Org']);
        $this->user = User::create([
            'first_name' => 'Instagram',
            'last_name' => 'Tester',
            'email' => 'sandbox@instaflow.io',
            'password' => bcrypt('password123'),
            'tenant_id' => $this->tenant->id,
        ]);
    }

    /**
     * Test querying status without authentication.
     */
    public function test_instagram_status_requires_auth()
    {
        $response = $this->getJson('/api/integrations/instagram/status');
        $response->assertStatus(401);
    }

    /**
     * Test status endpoint returns correct connection details.
     */
    public function test_instagram_status_returns_details()
    {
        // 1. Initially disconnected
        $response = $this->actingAs($this->user)->getJson('/api/integrations/instagram/status');
        $response->assertStatus(200);
        $response->assertJson(['connected' => false]);

        // 2. Connected
        ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'instagram',
            'name' => 'Insta Biz Support',
            'status' => 'connected',
            'credentials' => [
                'page_access_token' => 'my-page-token',
                'instagram_business_account_id' => 'ig-account-123',
                'webhook_verify_token' => 'my-verify-token',
            ],
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/integrations/instagram/status');
        $response->assertStatus(200);
        $response->assertJson([
            'connected' => true,
            'name' => 'Insta Biz Support',
            'instagram_business_account_id' => 'ig-account-123',
            'webhook_verify_token' => 'my-verify-token',
        ]);
    }



    /**
     * Test disconnecting deletes the channel connection.
     */
    public function test_instagram_disconnect()
    {
        ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'instagram',
            'name' => 'Instagram Support',
            'status' => 'connected',
            'credentials' => ['page_access_token' => 'mock_token'],
        ]);

        $response = $this->actingAs($this->user)->deleteJson('/api/integrations/instagram/disconnect');
        $response->assertStatus(200);

        $connection = ChannelConnection::where('tenant_id', $this->tenant->id)
            ->where('channel_type', 'instagram')
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
            'channel_type' => 'instagram',
            'name' => 'Insta Sandbox',
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
        $driver = new InstagramDriver();

        // 1. Inbound direct mock webhook payload
        $inboundMock = [
            'external_chat_id' => '10049281982',
            'sender_identifier' => 'Jane Cooper',
            'body' => 'Hi, is this open?',
        ];

        $normalized = $driver->normalizeInboundPayload($inboundMock);
        $this->assertEquals('10049281982', $normalized['external_chat_id']);
        $this->assertEquals('Jane Cooper', $normalized['sender_identifier']);
        $this->assertEquals('Hi, is this open?', $normalized['body']);

        // 2. Official Meta Graph format payload
        $inboundOfficial = [
            'object' => 'instagram',
            'entry' => [
                [
                    'id' => 'my-ig-id',
                    'messaging' => [
                        [
                          'sender' => ['id' => '9984928190'],
                          'recipient' => ['id' => 'my-ig-id'],
                          'message' => [
                              'mid' => 'm_mid_1495',
                              'text' => 'Official payload text',
                          ]
                        ]
                    ]
                ]
            ]
        ];

        $normalizedOfficial = $driver->normalizeInboundPayload($inboundOfficial);
        $this->assertEquals('9984928190', $normalizedOfficial['external_chat_id']);
        $this->assertEquals('m_mid_1495', $normalizedOfficial['external_message_id']);
        $this->assertEquals('Official payload text', $normalizedOfficial['body']);
    }

    /**
     * Test driver sends messages through Meta Graph mock response.
     */
    public function test_driver_sends_messages()
    {
        $driver = new InstagramDriver();

        // 1. Sandbox mock connection message sending
        $sandboxCreds = [
            'page_access_token' => 'mock_token',
            'instagram_business_account_id' => 'mock_ig_id',
        ];

        $res = $driver->sendMessage($sandboxCreds, [
            'external_chat_id' => 'user_123',
            'body' => 'Hello sandbox user!',
        ]);

        $this->assertEquals('sent', $res['delivery_status']);
        $this->assertStringContainsString('mock_ig_msg_', $res['external_message_id']);

        // 2. Official HTTP request sending with fake response
        Http::fake([
            'https://graph.facebook.com/v20.0/me/messages' => Http::response([
                'message_id' => 'meta_msg_9849128',
            ], 200),
        ]);

        $officialCreds = [
            'page_access_token' => 'real_token_value',
            'instagram_business_account_id' => 'real_ig_id',
        ];

        $res = $driver->sendMessage($officialCreds, [
            'external_chat_id' => 'real_user_id',
            'body' => 'Hello official!',
        ]);

        $this->assertEquals('sent', $res['delivery_status']);
        $this->assertEquals('meta_msg_9849128', $res['external_message_id']);
    }
}
