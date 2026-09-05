<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Tenant;
use App\Models\Contact;
use App\Models\ChannelConnection;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationMuteDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;
    protected Contact $contact;
    protected ChannelConnection $connection;
    protected Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['company_name' => 'Inbox Mute Delete Org']);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Inbox',
            'last_name' => 'Agent',
            'email' => 'agent@inboxorg.com',
            'password' => bcrypt('password123'),
        ]);

        $this->contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Muted',
            'last_name' => 'User',
            'phone' => '+199999999',
            'email' => 'muted@user.com',
        ]);

        $this->connection = ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'email',
            'name' => 'Support Mail',
            'credentials' => ['email_address' => 'support@inboxorg.com'],
        ]);

        $this->conversation = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'contact_id' => $this->contact->id,
            'channel_connection_id' => $this->connection->id,
            'external_chat_id' => 'muted@user.com',
            'status' => 'open',
            'last_message_at' => now(),
        ]);
    }

    /**
     * Test muting and unmuting a contact.
     */
    public function test_can_mute_and_unmute_contact(): void
    {
        // 1. Mute contact
        $response = $this->actingAs($this->user)
            ->putJson("/api/contacts/{$this->contact->id}/mute", [
                'is_muted' => true,
            ]);

        $response->assertStatus(200);
        $this->contact->refresh();
        $this->assertTrue($this->contact->is_muted);

        // 2. Unmute contact
        $response = $this->actingAs($this->user)
            ->putJson("/api/contacts/{$this->contact->id}/mute", [
                'is_muted' => false,
            ]);

        $response->assertStatus(200);
        $this->contact->refresh();
        $this->assertFalse($this->contact->is_muted);
    }

    /**
     * Test deleting a conversation.
     */
    public function test_can_delete_conversation(): void
    {
        // Create a message in this conversation
        $message = Message::create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'sender_identifier' => 'muted@user.com',
            'body' => 'Test message',
            'external_message_id' => 'msg_1234',
        ]);

        $this->assertDatabaseHas('messages', ['id' => $message->id]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/conversations/{$this->conversation->id}");

        $response->assertStatus(200);

        // Assert conversation is deleted
        $this->assertDatabaseMissing('conversations', ['id' => $this->conversation->id]);
        
        // Assert messages associated are also deleted
        $this->assertDatabaseMissing('messages', ['id' => $message->id]);
    }

    /**
     * Test cross-tenant protections prevent muting/deleting other tenant resources.
     */
    public function test_cross_tenant_protection(): void
    {
        $otherTenant = Tenant::create(['company_name' => 'Foreign Corp']);
        $otherContact = Contact::create([
            'tenant_id' => $otherTenant->id,
            'first_name' => 'Foreign',
            'last_name' => 'Contact',
            'email' => 'foreign@contact.com',
        ]);
        $otherConnection = ChannelConnection::create([
            'tenant_id' => $otherTenant->id,
            'channel_type' => 'email',
            'name' => 'Foreign Mail',
            'credentials' => ['email_address' => 'support@foreign.com'],
        ]);
        $otherConversation = Conversation::create([
            'tenant_id' => $otherTenant->id,
            'contact_id' => $otherContact->id,
            'channel_connection_id' => $otherConnection->id,
            'external_chat_id' => 'foreign@contact.com',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        // Attempt to mute foreign contact
        $response = $this->actingAs($this->user)
            ->putJson("/api/contacts/{$otherContact->id}/mute", [
                'is_muted' => true,
            ]);
        $response->assertStatus(404);

        // Attempt to delete foreign conversation
        $response = $this->actingAs($this->user)
            ->deleteJson("/api/conversations/{$otherConversation->id}");
        $response->assertStatus(404);
    }
}
