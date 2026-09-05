<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\ChannelConnection;
use App\Models\Conversation;
use App\Models\Contact;
use App\Models\Message;
use App\Models\User;
use App\Services\Channels\ChannelManager;
use App\Jobs\SendOutboundMessageJob;
use App\Jobs\ProcessInboundMessageJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class EmailChannelIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'company_name' => 'WhatsOmni Email Corp',
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Support',
            'last_name' => 'Agent',
            'email' => 'agent@whatsomni.io',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'status' => 'active',
        ]);
    }

    /**
     * Test Email driver is correctly registered in ChannelManager.
     */
    public function test_email_driver_is_registered_in_channel_manager(): void
    {
        $manager = app(ChannelManager::class);
        $driver = $manager->driver('email');

        $this->assertInstanceOf(\App\Services\Channels\Drivers\EmailDriver::class, $driver);
    }

    /**
     * Test Email connection APIs.
     */
    public function test_email_connection_endpoints_flow(): void
    {
        // 1. Get status - should be disconnected
        $response = $this->actingAs($this->user)->getJson('/api/integrations/email/status');
        $response->assertStatus(200);
        $response->assertJson(['connected' => false]);


        // 3. Connect real BYOK with mock inputs
        $response = $this->actingAs($this->user)->postJson('/api/integrations/email/connect', [
            'name' => 'SMTP Production Mail',
            'provider' => 'smtp',
            'email_address' => 'support@whatsomni.io',
            'smtp_host' => 'localhost',
            'smtp_port' => 587,
            'smtp_username' => 'smtpuser',
            'smtp_password' => 'smtppass',
            'smtp_encryption' => 'tls',
            'imap_host' => 'localhost',
            'imap_port' => 993,
            'imap_username' => 'imapuser',
            'imap_password' => 'imappass',
            'imap_encryption' => 'ssl',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'connected' => true,
            'provider' => 'smtp',
            'name' => 'SMTP Production Mail',
        ]);

        // Verify status reflects update
        $response = $this->actingAs($this->user)->getJson('/api/integrations/email/status');
        $response->assertStatus(200);
        $response->assertJsonPath('details.email_address', 'support@whatsomni.io');

        // 4. Disconnect Email channel
        $response = $this->actingAs($this->user)->deleteJson('/api/integrations/email/disconnect');
        $response->assertStatus(200);
        $response->assertJson(['connected' => false]);
    }

    /**
     * Test outbound dispatch sending normalisation.
     */
    public function test_email_outbound_dispatch_job(): void
    {
        $connection = ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'email',
            'name' => 'Outbox Test',
            'status' => 'connected',
            'credentials' => [
                'provider' => 'sandbox',
                'email_address' => 'support@whatsomni.io',
            ],
        ]);

        $contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Alice',
            'last_name' => 'Smith',
            'phone' => '1234567890',
            'email' => 'alice@example.com',
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'channel_connection_id' => $connection->id,
            'external_chat_id' => 'alice@example.com',
            'contact_id' => $contact->id,
        ]);

        $message = Message::create([
            'tenant_id' => $this->tenant->id,
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'status' => 'pending',
            'content_type' => 'text',
            'body' => 'Hello Alice, how are you today?',
        ]);

        // Dispatch & run outbound job synchronously
        SendOutboundMessageJob::dispatchSync($message->id);

        $message->refresh();
        $this->assertEquals('sent', $message->delivery_status);
        $this->assertNotEmpty($message->external_message_id);
    }

    /**
     * Test inbound email threading via message id and reply to.
     */
    public function test_email_inbound_job_resolves_thread(): void
    {
        $connection = ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'email',
            'name' => 'Support Inbox',
            'status' => 'connected',
            'credentials' => [
                'provider' => 'sandbox',
                'email_address' => 'support@whatsomni.io',
            ],
        ]);

        $contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Carol',
            'phone' => '1122334455',
            'email' => 'carol@example.com',
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'channel_connection_id' => $connection->id,
            'external_chat_id' => 'carol@example.com',
            'contact_id' => $contact->id,
        ]);

        // Create an outgoing message with message id
        $parentMessageId = '<parent-id-123@whatsomni.io>';
        Message::create([
            'tenant_id' => $this->tenant->id,
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'status' => 'sent',
            'content_type' => 'text',
            'body' => 'Did you get the file?',
            'external_message_id' => $parentMessageId,
        ]);

        // Process incoming email reply referencing the parent id
        $inboundPayload = [
            'from' => 'carol@example.com',
            'subject' => 'Re: Did you get the file?',
            'text' => 'Yes, thank you!',
            'message_id' => '<reply-id-456@whatsomni.io>',
            'in_reply_to' => $parentMessageId,
        ];

        ProcessInboundMessageJob::dispatchSync($connection->id, $inboundPayload);

        // Verify the message was stored in the same conversation
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'body' => "Subject: Re: Did you get the file?\n\nYes, thank you!",
            'external_message_id' => '<reply-id-456@whatsomni.io>',
        ]);
    }

    /**
     * Test Artisan IMAP email poller runs successfully.
     */
    public function test_email_poll_artisan_command_executes(): void
    {
        $connection = ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'email',
            'name' => 'IMAP Email Poller',
            'status' => 'connected',
            'credentials' => [
                'provider' => 'smtp',
                'email_address' => 'poll@domain.com',
                'imap_host' => 'localhost',
                'imap_port' => 993,
                'imap_username' => 'user',
                'imap_password' => 'pass',
                'imap_encryption' => 'ssl',
            ],
        ]);

        $exitCode = Artisan::call('email:poll');
        $this->assertEquals(0, $exitCode);
    }
}
