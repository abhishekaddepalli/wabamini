<?php

namespace Tests\Feature;

use App\Models\ChannelConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Events\WhatsAppBaileysQrCodeEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BaileysChannelTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'company_name' => 'Acme Corp',
        ]);

        $this->user = User::create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@acme.com',
            'password' => bcrypt('password123'),
            'tenant_id' => $this->tenant->id,
        ]);
    }

    /**
     * Test starting/connecting a Baileys channel connection.
     */
    public function test_baileys_connect_provisions_connection_and_notifies_worker()
    {
        Http::fake([
            'http://localhost:5001/sessions/start' => Http::response(['success' => true], 200),
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/integrations/baileys/connect', [
            'name' => 'Unofficial Dev Phone',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['id', 'name', 'status', 'webhook_verify_token']);

        $connection = ChannelConnection::first();
        $this->assertNotNull($connection);
        $this->assertEquals('whatsapp_baileys', $connection->channel_type);
        $this->assertEquals('disconnected', $connection->status);
        $this->assertEquals('Unofficial Dev Phone', $connection->name);
        $this->assertArrayHasKey('webhook_verify_token', $connection->decrypted_credentials);
    }

    /**
     * Test disconnecting deletes connection.
     */
    public function test_baileys_disconnect_notifies_worker_and_deletes()
    {
        Http::fake([
            'http://localhost:5001/sessions/stop' => Http::response(['success' => true], 200),
        ]);

        $connection = ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'whatsapp_baileys',
            'name' => 'Baileys Phone',
            'status' => 'connected',
            'credentials' => ['webhook_verify_token' => 'my-token'],
        ]);

        $response = $this->actingAs($this->user)->deleteJson('/api/integrations/baileys/disconnect');

        $response->assertStatus(200);
        $this->assertNull(ChannelConnection::find($connection->id));
    }

    /**
     * Test worker status webhook.
     */
    public function test_worker_webhook_updates_status()
    {
        $connection = ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'whatsapp_baileys',
            'name' => 'Baileys Phone',
            'status' => 'disconnected',
            'credentials' => ['webhook_verify_token' => 'my-token'],
        ]);

        // Connected status update
        $response = $this->postJson('/api/integrations/baileys/webhook', [
            'connection_id' => $connection->id,
            'event' => 'connected',
        ], [
            'X-Baileys-Token' => 'my-token'
        ]);

        $response->assertStatus(200);
        $connection->refresh();
        $this->assertEquals('connected', $connection->status);

        // Disconnected status update
        $response = $this->postJson('/api/integrations/baileys/webhook', [
            'connection_id' => $connection->id,
            'event' => 'disconnected',
        ], [
            'X-Baileys-Token' => 'my-token'
        ]);

        $response->assertStatus(200);
        $connection->refresh();
        $this->assertEquals('disconnected', $connection->status);
    }

    /**
     * Test QR event broadcast.
     */
    public function test_worker_webhook_broadcasts_qr_events()
    {
        Event::fake([WhatsAppBaileysQrCodeEvent::class]);

        $connection = ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'whatsapp_baileys',
            'name' => 'Baileys Phone',
            'status' => 'disconnected',
            'credentials' => ['webhook_verify_token' => 'my-token'],
        ]);

        $response = $this->postJson('/api/integrations/baileys/webhook', [
            'connection_id' => $connection->id,
            'event' => 'qr',
            'qr' => 'data:image/png;base64,mockqrstr',
        ], [
            'X-Baileys-Token' => 'my-token'
        ]);

        $response->assertStatus(200);
        Event::assertDispatched(WhatsAppBaileysQrCodeEvent::class, function ($event) use ($connection) {
            return $event->connectionId === $connection->id && $event->qr === 'data:image/png;base64,mockqrstr';
        });
    }

    /**
     * Test webhook verify invalid token gets rejected.
     */
    public function test_webhook_with_invalid_token_is_unauthorized()
    {
        $connection = ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'whatsapp_baileys',
            'name' => 'Baileys Phone',
            'status' => 'disconnected',
            'credentials' => ['webhook_verify_token' => 'my-token'],
        ]);

        $response = $this->postJson('/api/integrations/baileys/webhook', [
            'connection_id' => $connection->id,
            'event' => 'connected',
        ], [
            'X-Baileys-Token' => 'wrong-token'
        ]);

        $response->assertStatus(401);
    }

    /**
     * Test WhatsAppBaileysDriver sendMessage with external_chat_id.
     */
    public function test_driver_sends_message_successfully_with_external_chat_id()
    {
        Http::fake([
            'http://localhost:5001/sessions/send' => Http::response([
                'success' => true,
                'message_id' => 'baileys_msg_123',
            ], 200),
        ]);

        $connection = ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'whatsapp_baileys',
            'name' => 'Baileys Phone',
            'status' => 'connected',
            'credentials' => ['webhook_verify_token' => 'my-token'],
        ]);

        $driver = app(\App\Services\Channels\ChannelManager::class)->driver('whatsapp_baileys');
        $res = $driver->sendMessage($connection->decrypted_credentials, [
            'connection_id' => $connection->id,
            'external_chat_id' => '+919876543210',
            'body' => 'Hello from WhatsOmni Baileys!',
        ]);

        $this->assertEquals('sent', $res['delivery_status']);
        $this->assertEquals('baileys_msg_123', $res['external_message_id']);
        $this->assertNull($res['error_message']);
    }

    /**
     * Test WhatsAppBaileysDriver normalizes inbound phone and strips device suffixes.
     */
    public function test_driver_normalizes_inbound_phone_and_strips_device_suffix()
    {
        $driver = app(\App\Services\Channels\ChannelManager::class)->driver('whatsapp_baileys');

        // Test with device suffix :1
        $payloadWithDevice = [
            'external_chat_id' => '919876543210:1',
            'sender_identifier' => 'John Doe',
            'external_message_id' => 'msg_abc',
            'body' => 'Test message',
        ];
        $normalized = $driver->normalizeInboundPayload($payloadWithDevice);
        $this->assertEquals('919876543210', $normalized['external_chat_id']);
        $this->assertEquals('John Doe', $normalized['sender_identifier']);

        // Test with @s.whatsapp.net domain
        $payloadWithDomain = [
            'external_chat_id' => '919876543210:2@s.whatsapp.net',
            'sender_identifier' => 'Jane Doe',
            'external_message_id' => 'msg_def',
            'body' => 'Another message',
        ];
        $normalized2 = $driver->normalizeInboundPayload($payloadWithDomain);
        $this->assertEquals('919876543210', $normalized2['external_chat_id']);
    }
}
