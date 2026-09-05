<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\ChannelConnection;
use App\Models\Conversation;
use App\Models\Contact;
use App\Models\Message;
use App\Services\Channels\ChannelManager;
use App\Jobs\SendOutboundMessageJob;
use App\Jobs\ProcessInboundMessageJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ChannelFrameworkTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['company_name' => 'Test Tenant Company']);

        // Register inline mock driver for testing
        $mockDriver = new class implements \App\Contracts\ChannelProviderInterface {
            public function sendMessage(array $credentials, array $messageData): array
            {
                return [
                    'external_message_id' => 'mock_msg_' . uniqid(),
                    'delivery_status' => 'sent',
                    'error_message' => null,
                ];
            }

            public function verifyWebhookSignature(array $headers, string $payload, array $credentials): bool
            {
                $signature = $headers['x-mock-signature'] ?? $headers['X-Mock-Signature'] ?? null;
                $expectedToken = $credentials['webhook_token'] ?? 'valid_sig_token';
                return $signature === $expectedToken;
            }

            public function normalizeInboundPayload(array $payload): array
            {
                return [
                    'external_chat_id' => (string)($payload['chat_id'] ?? 'mock_chat_123'),
                    'sender_identifier' => $payload['sender'] ?? 'Mock User',
                    'external_message_id' => (string)($payload['message_id'] ?? 'mock_in_' . uniqid()),
                    'message_type' => 'text',
                    'body' => $payload['text'] ?? '',
                    'media_url' => null,
                ];
            }

            public function normalizeStatusPayload(array $payload): array
            {
                return [
                    'external_message_id' => (string)($payload['external_message_id'] ?? ''),
                    'delivery_status' => $payload['delivery_status'] ?? 'delivered',
                    'error_message' => $payload['error_message'] ?? null,
                ];
            }
        };

        app(ChannelManager::class)->registerDriver('mock', $mockDriver);
    }

    /**
     * Test credentials auto-encryption/decryption.
     */
    public function test_connection_credentials_are_securely_encrypted(): void
    {
        $connection = ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'mock',
            'name' => 'Testing Mock Connection',
            'credentials' => [
                'webhook_token' => 'top_secret_123',
                'api_key' => 'auth_key_xyz',
            ],
        ]);

        $this->assertDatabaseHas('channel_connections', [
            'id' => $connection->id,
            'name' => 'Testing Mock Connection',
        ]);

        // Raw DB credentials field must not contain raw text
        $this->assertStringNotContainsString('top_secret_123', $connection->getRawOriginal('credentials'));

        // Decrypted values must match original array
        $this->assertEquals('top_secret_123', $connection->decrypted_credentials['webhook_token']);
        $this->assertEquals('auth_key_xyz', $connection->decrypted_credentials['api_key']);
    }

    /**
     * Test Driver registry and custom driver registrations.
     */
    public function test_driver_manager_resolves_mock_driver(): void
    {
        $manager = app(ChannelManager::class);
        $driver = $manager->driver('mock');

        $this->assertInstanceOf(\App\Contracts\ChannelProviderInterface::class, $driver);
    }

    /**
     * Test signature challenge verification (GET).
     */
    public function test_webhook_challenge_verification(): void
    {
        $connection = ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'mock',
            'name' => 'Test Webhook Ingress',
            'credentials' => [
                'webhook_verify_token' => 'secure_verify_token',
            ],
        ]);

        $response = $this->getJson("/api/webhooks/channel/{$connection->id}?hub_mode=subscribe&hub_verify_token=secure_verify_token&hub_challenge=echo_back_me");
        
        $response->assertStatus(200);
        $response->assertSeeText('echo_back_me');
    }

    /**
     * Test Inbound Webhook dispatches queues on valid signature (POST).
     */
    public function test_webhook_post_authenticates_signature_and_queues_job(): void
    {
        Queue::fake();

        $connection = ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'mock',
            'name' => 'Test Post Webhook',
            'credentials' => [
                'webhook_token' => 'valid_sig_token',
            ],
        ]);

        // Invalid signature
        $responseInvalid = $this->withHeaders([
            'X-Mock-Signature' => 'wrong_token'
        ])->postJson("/api/webhooks/channel/{$connection->id}", [
            'text' => 'Hello World'
        ]);

        $responseInvalid->assertStatus(401);
        Queue::assertNotPushed(ProcessInboundMessageJob::class);

        // Valid signature
        $responseValid = $this->withHeaders([
            'X-Mock-Signature' => 'valid_sig_token'
        ])->postJson("/api/webhooks/channel/{$connection->id}", [
            'chat_id' => '123456789',
            'sender' => 'Alice',
            'text' => 'Hello World'
        ]);

        $responseValid->assertStatus(200);
        Queue::assertPushed(ProcessInboundMessageJob::class, function ($job) use ($connection) {
            return $job->connectionId === $connection->id;
        });
    }

    /**
     * Test ProcessInboundMessageJob stores message, conversation, auto-creates contact, and broadcasts events.
     */
    public function test_inbound_message_job_flow(): void
    {
        Event::fake([
            \App\Events\MessageReceived::class,
            \App\Events\MessageStatusUpdated::class
        ]);

        $connection = ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'mock',
            'name' => 'Mock Connection',
            'credentials' => ['webhook_token' => 'xyz'],
        ]);

        $inboundPayload = [
            'chat_id' => '987654321',
            'sender' => 'Bobby Smith',
            'text' => 'Testing inbound queue normalization',
            'message_id' => 'external_inbound_xyz_123',
        ];

        // Contacts list empty originally
        $this->assertEquals(0, Contact::count());

        // Process Job
        $job = new ProcessInboundMessageJob($connection->id, $inboundPayload);
        $job->handle(app(ChannelManager::class));

        // Contact should be auto-created
        $this->assertEquals(1, Contact::count());
        $contact = Contact::first();
        $this->assertEquals('Bobby Smith', $contact->first_name);
        $this->assertEquals('987654321', $contact->phone);

        // Conversation should be created
        $this->assertEquals(1, Conversation::count());
        $conversation = Conversation::first();
        $this->assertEquals($contact->id, $conversation->contact_id);

        // Message should be stored
        $this->assertEquals(1, Message::count());
        $message = Message::first();
        $this->assertEquals('inbound', $message->direction);
        $this->assertEquals('Testing inbound queue normalization', $message->body);
        $this->assertEquals('external_inbound_xyz_123', $message->external_message_id);

        Event::assertDispatched(\App\Events\MessageReceived::class);
    }

    /**
     * Test SendOutboundMessageJob runs send handler, updates message status, and triggers broadcasts.
     */
    public function test_outbound_message_job_flow(): void
    {
        Event::fake([\App\Events\MessageStatusUpdated::class]);

        $connection = ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'mock',
            'name' => 'Mock Connection Outbound',
            'credentials' => ['webhook_token' => 'abc'],
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'channel_connection_id' => $connection->id,
            'external_chat_id' => '11223344',
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'body' => 'Sending mock outbound text',
            'delivery_status' => 'sent',
        ]);

        // Process outbound job
        $job = new SendOutboundMessageJob($message->id);
        $job->handle(app(ChannelManager::class));

        $message->refresh();

        $this->assertNotNull($message->external_message_id);
        $this->assertStringStartsWith('mock_msg_', $message->external_message_id);
        $this->assertEquals('sent', $message->delivery_status);

        Event::assertDispatched(\App\Events\MessageStatusUpdated::class);
    }
}
