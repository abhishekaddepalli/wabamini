<?php

namespace Tests\Feature;

use App\Models\ChannelConnection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Jobs\SendOutboundMessageJob;
use App\Jobs\ProcessInboundMessageJob;
use App\Services\Channels\Drivers\SmsDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsChannelIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['company_name' => 'Sms Sandbox Org']);
        $this->user = User::create([
            'first_name' => 'SMS',
            'last_name' => 'Tester',
            'email' => 'smstester@whatsomni.io',
            'password' => bcrypt('password123'),
            'tenant_id' => $this->tenant->id,
        ]);
    }

    /**
     * Test querying status requires auth.
     */
    public function test_sms_status_requires_auth()
    {
        $response = $this->getJson('/api/integrations/sms/status');
        $response->assertStatus(401);
    }

    /**
     * Test status returns correct connected fields.
     */
    public function test_sms_status_returns_details()
    {
        // 1. Diconnected
        $response = $this->actingAs($this->user)->getJson('/api/integrations/sms/status');
        $response->assertStatus(200);
        $response->assertJson(['connected' => false]);

        // 2. Connected
        ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'sms',
            'name' => 'Twilio Primary',
            'status' => 'connected',
            'credentials' => [
                'provider' => 'twilio',
                'twilio_account_sid' => 'AC1234567890',
                'twilio_phone_number' => '+15005550006',
                'webhook_verify_token' => 'my-verify-token',
            ],
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/integrations/sms/status');
        $response->assertStatus(200);
        $response->assertJson([
            'connected' => true,
            'name' => 'Twilio Primary',
            'provider' => 'twilio',
            'webhook_verify_token' => 'my-verify-token',
            'details' => [
                'twilio_account_sid' => 'AC123456...',
                'twilio_phone_number' => '+15005550006',
            ],
        ]);
    }

    /**
     * Test connecting Twilio with success response.
     */
    public function test_sms_connect_twilio_success()
    {
        Http::fake([
            'https://api.twilio.com/2010-04-01/Accounts/ACtwilio123.json' => Http::response([
                'sid' => 'ACtwilio123',
                'status' => 'active',
            ], 200),
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/integrations/sms/connect', [
            'name' => 'My Twilio Account',
            'provider' => 'twilio',
            'twilio_account_sid' => 'ACtwilio123',
            'twilio_auth_token' => 'realtokenval',
            'twilio_phone_number' => '+15005550006',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'connected' => true,
            'name' => 'My Twilio Account',
            'provider' => 'twilio',
        ]);

        $connection = ChannelConnection::where('tenant_id', $this->tenant->id)->first();
        $this->assertNotNull($connection);
        $this->assertEquals('sms', $connection->channel_type);
        $this->assertEquals('ACtwilio123', $connection->decrypted_credentials['twilio_account_sid']);
    }

    /**
     * Test connecting Vonage with success response.
     */
    public function test_sms_connect_vonage_success()
    {
        Http::fake([
            'https://rest.nexmo.com/account/get-balance*' => Http::response([
                'value' => 10.45,
            ], 200),
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/integrations/sms/connect', [
            'name' => 'My Vonage Gateway',
            'provider' => 'vonage',
            'vonage_api_key' => 'vonagekey123',
            'vonage_api_secret' => 'vonagesecret123',
            'vonage_phone_number' => '+15005550007',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'connected' => true,
            'name' => 'My Vonage Gateway',
            'provider' => 'vonage',
        ]);
    }



    /**
     * Test disconnecting deletes the record.
     */
    public function test_sms_disconnect()
    {
        ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'sms',
            'name' => 'SMS gateway',
            'status' => 'connected',
            'credentials' => ['provider' => 'twilio'],
        ]);

        $response = $this->actingAs($this->user)->deleteJson('/api/integrations/sms/disconnect');
        $response->assertStatus(200);

        $this->assertEquals(0, ChannelConnection::count());
    }

    /**
     * Test STOP keyword automatically opts-out contact.
     */
    public function test_opt_out_keyword_detection()
    {
        $connection = ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'sms',
            'name' => 'SMS Sandbox',
            'status' => 'connected',
            'credentials' => ['provider' => 'twilio', 'twilio_account_sid' => 'mock_sid'],
        ]);

        $contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'OptOut',
            'last_name' => 'User',
            'phone' => '+15005550006',
            'opted_out_channels' => [],
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'channel_connection_id' => $connection->id,
            'external_chat_id' => '+15005550006',
            'contact_id' => $contact->id,
        ]);

        // Dispatch inbound webhook with "STOP" reply
        $inboundPayload = [
            'external_chat_id' => '+15005550006',
            'sender_identifier' => 'John',
            'body' => '  stop  ', // spaces and lower case
        ];

        (new ProcessInboundMessageJob($connection->id, $inboundPayload))->handle(app(\App\Services\Channels\ChannelManager::class));

        $contact->refresh();
        $this->assertContains('sms', $contact->opted_out_channels);

        // Try to send an outbound message, it must fail due to opt-out
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'body' => 'Hello again!',
            'delivery_status' => 'pending',
        ]);

        (new SendOutboundMessageJob($message->id))->handle(app(\App\Services\Channels\ChannelManager::class));

        $message->refresh();
        $this->assertEquals('failed', $message->delivery_status);
        $this->assertStringContainsString('opted out of this channel', $message->error_message);
    }

    /**
     * Test START keyword removes opt-out.
     */
    public function test_opt_in_keyword_detection()
    {
        $connection = ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'sms',
            'name' => 'SMS Sandbox',
            'status' => 'connected',
            'credentials' => ['provider' => 'twilio', 'twilio_account_sid' => 'mock_sid'],
        ]);

        $contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'OptIn',
            'last_name' => 'User',
            'phone' => '+15005550007',
            'opted_out_channels' => ['sms', 'whatsapp'],
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'channel_connection_id' => $connection->id,
            'external_chat_id' => '+15005550007',
            'contact_id' => $contact->id,
        ]);

        $inboundPayload = [
            'external_chat_id' => '+15005550007',
            'sender_identifier' => 'John',
            'body' => 'START',
        ];

        (new ProcessInboundMessageJob($connection->id, $inboundPayload))->handle(app(\App\Services\Channels\ChannelManager::class));

        $contact->refresh();
        $this->assertNotContains('sms', $contact->opted_out_channels);
        $this->assertContains('whatsapp', $contact->opted_out_channels); // whatsapp opt-out stays untouched
    }
}
