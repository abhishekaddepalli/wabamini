<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\Tenant;
use App\Models\User;
use App\Models\SupportTicket;

class AdminSupportTicketsTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;
    protected AdminRole $role;
    protected Tenant $tenant;
    protected User $user;
    protected SupportTicket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->role = AdminRole::create([
            'name' => 'Super Admin',
            'permissions' => ['*'],
        ]);

        $this->admin = Admin::create([
            'role_id' => $this->role->id,
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'email' => 'admin_tickets_test@whatsomni.com',
            'password' => bcrypt('password123'),
            'status' => 'active',
        ]);

        $this->tenant = Tenant::create([
            'company_name' => 'Tenant Dev Workspace',
            'domain' => 'tenant-dev',
            'status' => 'active',
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@whatsomni.com',
            'password' => bcrypt('password123'),
            'role' => 'owner',
        ]);

        $this->ticket = SupportTicket::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'subject' => 'Database Sync Issue',
            'description' => 'Unable to sync records to HubSpot CRM.',
            'type' => 'technical',
            'priority' => 'high',
            'status' => 'open',
        ]);
    }

    /**
     * Test admin can fetch tickets index.
     */
    public function test_admin_can_fetch_tickets_index(): void
    {
        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/tickets');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'tickets' => [
                    'data' => [
                        '*' => [
                            'id',
                            'subject',
                            'type',
                            'priority',
                            'status',
                            'user',
                            'tenant'
                        ]
                    ]
                ]
            ]);
    }

    /**
     * Test admin can search tickets by query parameter.
     */
    public function test_admin_can_search_tickets(): void
    {
        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/tickets?search=Database');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'tickets.data')
            ->assertJsonPath('tickets.data.0.subject', 'Database Sync Issue');

        $responseEmpty = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/tickets?search=NonExistingTerm');

        $responseEmpty->assertStatus(200)
            ->assertJsonCount(0, 'tickets.data');
    }

    /**
     * Test admin can view specific ticket thread.
     */
    public function test_admin_can_view_support_ticket(): void
    {
        $response = $this->actingAs($this->admin, 'admin')
            ->getJson("/api/admin/tickets/{$this->ticket->id}");

        $response->assertStatus(200)
            ->assertJsonPath('ticket.id', $this->ticket->id)
            ->assertJsonStructure([
                'ticket' => [
                    'id',
                    'messages'
                ]
            ]);
    }

    /**
     * Test admin can reply to support ticket.
     */
    public function test_admin_can_reply_to_ticket(): void
    {
        $payload = [
            'message' => 'We are working on this sync issue. It will be resolved shortly.',
        ];

        $response = $this->actingAs($this->admin, 'admin')
            ->postJson("/api/admin/tickets/{$this->ticket->id}/reply", $payload);

        $response->assertStatus(201)
            ->assertJsonPath('reply.is_admin_reply', true);

        $this->assertDatabaseHas('support_ticket_messages', [
            'support_ticket_id' => $this->ticket->id,
            'is_admin_reply' => true,
            'message' => $payload['message'],
        ]);
    }

    /**
     * Test admin can update ticket status.
     */
    public function test_admin_can_update_ticket_status(): void
    {
        $payload = [
            'status' => 'resolved',
        ];

        $response = $this->actingAs($this->admin, 'admin')
            ->putJson("/api/admin/tickets/{$this->ticket->id}/status", $payload);

        $response->assertStatus(200)
            ->assertJsonPath('ticket.status', 'resolved');

        $this->ticket->refresh();
        $this->assertEquals('resolved', $this->ticket->status);
    }
}
