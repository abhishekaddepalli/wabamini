<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Tenant;
use App\Models\Contact;
use App\Models\ChannelConnection;
use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;
    protected Contact $contact;
    protected ChannelConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['company_name' => 'Support Org']);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Agent',
            'last_name' => 'Smith',
            'email' => 'agent@org.com',
            'password' => bcrypt('password123'),
        ]);

        $this->contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'phone' => '+1234567890',
            'email' => 'john@doe.com',
        ]);

        $this->connection = ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'whatsapp',
            'name' => 'Support Line',
            'credentials' => ['phone_number_id' => '123'],
        ]);
    }

    /**
     * Test starting a conversation manually.
     */
    public function test_can_start_conversation_manually(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/conversations/start', [
                'contact_id' => $this->contact->id,
                'channel_connection_id' => $this->connection->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('contact_id', $this->contact->id)
            ->assertJsonPath('channel_connection_id', $this->connection->id)
            ->assertJsonPath('status', 'open');

        $this->assertDatabaseHas('conversations', [
            'contact_id' => $this->contact->id,
            'channel_connection_id' => $this->connection->id,
            'status' => 'open',
        ]);
    }

    /**
     * Test auto-reopening a conversation if it already exists in closed status.
     */
    public function test_reopens_existing_closed_conversation(): void
    {
        $conversation = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'contact_id' => $this->contact->id,
            'channel_connection_id' => $this->connection->id,
            'external_chat_id' => '+1234567890',
            'status' => 'resolved',
            'last_message_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/conversations/start', [
                'contact_id' => $this->contact->id,
                'channel_connection_id' => $this->connection->id,
            ]);

        $response->assertStatus(200);

        $conversation->refresh();
        $this->assertEquals('open', $conversation->status);
    }

    /**
     * Test cannot start conversation for contact belonging to another tenant.
     */
    public function test_cannot_start_conversation_for_cross_tenant_contact(): void
    {
        $otherTenant = Tenant::create(['company_name' => 'Other Corp']);
        $otherContact = Contact::create([
            'tenant_id' => $otherTenant->id,
            'first_name' => 'Imposter',
            'last_name' => 'Hack',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/conversations/start', [
                'contact_id' => $otherContact->id,
                'channel_connection_id' => $this->connection->id,
            ]);

        $response->assertStatus(404);
    }
}
