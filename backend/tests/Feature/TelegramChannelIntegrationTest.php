<?php

namespace Tests\Feature;

use App\Models\ChannelConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Channels\Drivers\TelegramDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramChannelIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['company_name' => 'Telegram Bot sandbox']);
        $this->user = User::create([
            'first_name' => 'Telegram',
            'last_name' => 'Tester',
            'email' => 'tgtester@whatsomni.io',
            'password' => bcrypt('password123'),
            'tenant_id' => $this->tenant->id,
        ]);
    }

    /**
     * Test querying status requires auth.
     */
    public function test_telegram_status_requires_auth()
    {
        $response = $this->getJson('/api/integrations/telegram/status');
        $response->assertStatus(401);
    }

    /**
     * Test status endpoint returns connection details.
     */
    public function test_telegram_status_returns_details()
    {
        // 1. Diconnected
        $response = $this->actingAs($this->user)->getJson('/api/integrations/telegram/status');
        $response->assertStatus(200);
        $response->assertJson(['connected' => false]);

        // 2. Connected
        ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'telegram',
            'name' => 'My Telegram Helpdesk',
            'status' => 'connected',
            'credentials' => [
                'token' => 'my-token',
                'secret_token' => 'my-secret-verify-token',
                'bot_username' => 'helpdesk_bot',
            ],
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/integrations/telegram/status');
        $response->assertStatus(200);
        $response->assertJson([
            'connected' => true,
            'name' => 'My Telegram Helpdesk',
            'bot_username' => 'helpdesk_bot',
            'webhook_verify_token' => 'my-secret-verify-token',
        ]);
    }

    /**
     * Test connection with invalid token.
     */
    public function test_telegram_connect_invalid_token()
    {
        Http::fake([
            'https://api.telegram.org/botinvalid_token/getMe' => Http::response([
                'ok' => false,
                'description' => 'Unauthorized',
            ], 401),
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/integrations/telegram/connect', [
            'name' => 'My Bot',
            'token' => 'invalid_token',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Invalid Telegram Bot Token', $response->json('message'));
    }

    /**
     * Test connection with valid token.
     */
    public function test_telegram_connect_success()
    {
        Http::fake([
            'https://api.telegram.org/botvalid_token/getMe' => Http::response([
                'ok' => true,
                'result' => [
                    'id' => 884928,
                    'is_bot' => true,
                    'first_name' => 'Awesome Support',
                    'username' => 'awesome_support_bot',
                ]
            ], 200),
            'https://api.telegram.org/botvalid_token/setWebhook' => Http::response([
                'ok' => true,
                'description' => 'Webhook was set',
            ], 200),
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/integrations/telegram/connect', [
            'name' => 'My Bot Name',
            'token' => 'valid_token',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'connected' => true,
            'name' => 'My Bot Name',
            'bot_username' => 'awesome_support_bot',
        ]);

        $connection = ChannelConnection::where('tenant_id', $this->tenant->id)
            ->where('channel_type', 'telegram')
            ->first();

        $this->assertNotNull($connection);
        $this->assertEquals('connected', $connection->status);
        $this->assertEquals('valid_token', $connection->decrypted_credentials['token']);
        $this->assertNotEmpty($connection->decrypted_credentials['secret_token']);
    }



    /**
     * Test disconnecting.
     */
    public function test_telegram_disconnect()
    {
        ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'telegram',
            'name' => 'Telegram Bot',
            'status' => 'connected',
            'credentials' => ['token' => 'mock_token'],
        ]);

        $response = $this->actingAs($this->user)->deleteJson('/api/integrations/telegram/disconnect');
        $response->assertStatus(200);

        $connection = ChannelConnection::where('tenant_id', $this->tenant->id)
            ->where('channel_type', 'telegram')
            ->first();

        $this->assertNull($connection);
    }

    /**
     * Test driver payload normalization.
     */
    public function test_driver_payload_normalization()
    {
        $driver = new TelegramDriver();

        // 1. Mock payload
        $mockPayload = [
            'external_chat_id' => '7849281',
            'sender_identifier' => 'John Wick',
            'body' => 'I need assistance.',
        ];

        $res = $driver->normalizeInboundPayload($mockPayload);
        $this->assertEquals('7849281', $res['external_chat_id']);
        $this->assertEquals('John Wick', $res['sender_identifier']);
        $this->assertEquals('I need assistance.', $res['body']);
        $this->assertEquals('text', $res['message_type']);

        // 2. Official message text payload
        $officialTextPayload = [
            'update_id' => 99281489,
            'message' => [
                'message_id' => 381,
                'from' => [
                    'id' => 992814,
                    'first_name' => 'Bruce',
                    'last_name' => 'Wayne',
                    'username' => 'batman',
                ],
                'chat' => [
                    'id' => 992814,
                    'first_name' => 'Bruce',
                    'last_name' => 'Wayne',
                    'type' => 'private',
                ],
                'date' => 171829381,
                'text' => 'Justice',
            ]
        ];

        $resOfficial = $driver->normalizeInboundPayload($officialTextPayload);
        $this->assertEquals('992814', $resOfficial['external_chat_id']);
        $this->assertEquals('Bruce Wayne', $resOfficial['sender_identifier']);
        $this->assertEquals('381', $resOfficial['external_message_id']);
        $this->assertEquals('Justice', $resOfficial['body']);
        $this->assertEquals('text', $resOfficial['message_type']);

        // 3. Official message photo payload
        $officialPhotoPayload = [
            'update_id' => 99281490,
            'message' => [
                'message_id' => 382,
                'from' => [
                    'id' => 992814,
                    'first_name' => 'Bruce',
                ],
                'chat' => [
                    'id' => 992814,
                    'type' => 'private',
                ],
                'photo' => [
                    ['file_id' => 'low-res-id', 'file_size' => 1000],
                    ['file_id' => 'high-res-id', 'file_size' => 5000],
                ],
                'caption' => 'Secret Cave',
            ]
        ];

        $resPhoto = $driver->normalizeInboundPayload($officialPhotoPayload);
        $this->assertEquals('992814', $resPhoto['external_chat_id']);
        $this->assertEquals('Bruce', $resPhoto['sender_identifier']);
        $this->assertEquals('high-res-id', $resPhoto['media_url']);
        $this->assertEquals('image', $resPhoto['message_type']);
    }

    /**
     * Test driver sendMessage dispatch.
     */
    public function test_driver_sends_messages()
    {
        $driver = new TelegramDriver();

        // 1. Sandbox dispatch
        $credsMock = ['token' => 'mock_token'];
        $resMock = $driver->sendMessage($credsMock, [
            'external_chat_id' => 'chat-1',
            'body' => 'Hi Sandbox',
        ]);

        $this->assertEquals('sent', $resMock['delivery_status']);
        $this->assertStringContainsString('mock_tg_msg_', $resMock['external_message_id']);

        // 2. Official API dispatch
        Http::fake([
            'https://api.telegram.org/botreal_token/sendMessage' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 45293,
                ],
            ], 200),
        ]);

        $credsReal = ['token' => 'real_token'];
        $resReal = $driver->sendMessage($credsReal, [
            'external_chat_id' => '100293129',
            'body' => 'Hi Official',
        ]);

        $this->assertEquals('sent', $resReal['delivery_status']);
        $this->assertEquals('45293', $resReal['external_message_id']);
    }

    /**
     * Test webhook signature validation.
     */
    public function test_webhook_signature_verification()
    {
        $driver = new TelegramDriver();

        $headers = [
            'X-Telegram-Bot-Api-Secret-Token' => 'my-secret-token-header',
        ];

        $credentials = [
            'token' => 'real_token',
            'secret_token' => 'my-secret-token-header',
        ];

        $this->assertTrue($driver->verifyWebhookSignature($headers, '', $credentials));

        $badHeaders = [
            'X-Telegram-Bot-Api-Secret-Token' => 'invalid-token',
        ];
        $this->assertFalse($driver->verifyWebhookSignature($badHeaders, '', $credentials));
    }
}
