<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\Currency;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Appointment;
use App\Models\Contact;
use App\Models\ChannelConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;
    protected Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Platform configurations
        $currency = Currency::create([
            'code' => 'USD',
            'symbol' => '$',
            'name' => 'US Dollar',
            'is_active' => true,
            'is_default' => true,
        ]);

        $plan = Plan::create([
            'name' => 'Premium Team Plan',
            'max_channels' => 10,
            'max_automations' => 100,
        ]);

        PlanPrice::create([
            'plan_id' => $plan->id,
            'currency_id' => $currency->id,
            'amount' => 4900, // $49.00
            'billing_interval' => 'month',
        ]);

        $this->tenant = Tenant::create([
            'company_name' => 'Acme Inc',
            'status' => 'active',
            'currency_id' => $currency->id,
            'plan_id' => $plan->id,
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane.analytics@example.com',
            'password' => bcrypt('password123'),
            'status' => 'active',
        ]);

        $adminRole = AdminRole::create([
            'name' => 'Super Admin',
            'permissions' => ['*'],
        ]);

        $this->admin = Admin::create([
            'role_id' => $adminRole->id,
            'first_name' => 'SaaS',
            'last_name' => 'Admin',
            'email' => 'saas.admin@example.com',
            'password' => bcrypt('password123'),
            'status' => 'active',
        ]);
    }

    /** @test */
    public function can_retrieve_tenant_workspace_analytics(): void
    {
        $this->actingAs($this->user);

        // Seed data
        $conn = ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'email',
            'name' => 'Work Mail',
            'credentials' => [],
        ]);

        $contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Tom',
            'last_name' => 'Buyer',
            'email' => 'tom@buyer.com',
        ]);

        $conv = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'channel_connection_id' => $conn->id,
            'contact_id' => $contact->id,
            'external_chat_id' => 'tom@buyer.com',
        ]);

        Message::create([
            'conversation_id' => $conv->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'body' => 'Hello',
        ]);

        Appointment::create([
            'tenant_id' => $this->tenant->id,
            'contact_id' => $contact->id,
            'start_time' => now()->addDay(),
            'end_time' => now()->addDay()->addMinutes(30),
            'status' => 'scheduled',
        ]);

        $response = $this->getJson('/api/dashboard/analytics?start_date=' . now()->subDays(2)->toDateString() . '&end_date=' . now()->addDays(2)->toDateString());
        
        $response->assertStatus(200)
            ->assertJsonStructure([
                'conversation_volume',
                'avg_response_time',
                'ai_performance',
                'campaign_stats',
                'appointments',
                'trend'
            ])
            ->assertJson([
                'conversation_volume' => 1,
                'appointments' => [
                    'total' => 1,
                    'scheduled' => 1,
                ]
            ]);
    }

    /** @test */
    public function can_retrieve_superadmin_analytics(): void
    {
        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/analytics');
        
        $response->assertStatus(200)
            ->assertJsonStructure([
                'active_tenants',
                'mrr',
                'churn_rate',
                'channel_adoption',
                'trend'
            ])
            ->assertJson([
                'active_tenants' => 1,
                'mrr' => 49.00,
                'churn_rate' => 0.0,
            ]);
    }
}
