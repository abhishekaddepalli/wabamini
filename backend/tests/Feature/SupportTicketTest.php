<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    public function test_support_tickets_lifecycle_flow(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Help Desk Tenant',
            'status' => 'active',
        ]);

        $role = Role::create([
            'tenant_id' => $tenant->id,
            'name' => 'agent',
            'display_name' => 'Agent',
            'permissions' => [],
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'first_name' => 'Support',
            'last_name' => 'User',
            'email' => 'support.user@example.com',
            'password' => 'password123',
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        $this->actingAs($user, 'web');

        // 1. Create a support ticket
        $responseCreate = $this->postJson('/api/help/tickets', [
            'subject' => 'Cannot connect HubSpot CRM Integration',
            'description' => 'I get a 401 error code during HubSpot sync.',
            'priority' => 'high',
            'type' => 'technical',
        ]);

        $responseCreate->assertStatus(201);
        $this->assertDatabaseHas('support_tickets', [
            'tenant_id' => $tenant->id,
            'subject' => 'Cannot connect HubSpot CRM Integration',
            'type' => 'technical',
            'priority' => 'high',
            'status' => 'open',
        ]);

        $ticketId = $responseCreate->json('ticket.id');

        $this->assertDatabaseHas('support_ticket_messages', [
            'support_ticket_id' => $ticketId,
            'message' => 'I get a 401 error code during HubSpot sync.',
            'is_admin_reply' => false,
        ]);

        // 2. View tickets listing
        $responseList = $this->getJson('/api/help/tickets');
        $responseList->assertStatus(200);
        $responseList->assertJsonFragment(['subject' => 'Cannot connect HubSpot CRM Integration']);

        // 3. View ticket details thread
        $responseDetail = $this->getJson("/api/help/tickets/{$ticketId}");
        $responseDetail->assertStatus(200);
        $responseDetail->assertJsonFragment(['message' => 'I get a 401 error code during HubSpot sync.']);

        // 4. Send a reply message to the ticket
        $responseReply = $this->postJson("/api/help/tickets/{$ticketId}/reply", [
            'message' => 'I verified that my client ID is correct. Please assist.',
        ]);

        $responseReply->assertStatus(200);
        $this->assertDatabaseHas('support_ticket_messages', [
            'support_ticket_id' => $ticketId,
            'message' => 'I verified that my client ID is correct. Please assist.',
            'is_admin_reply' => false,
        ]);
    }
}
