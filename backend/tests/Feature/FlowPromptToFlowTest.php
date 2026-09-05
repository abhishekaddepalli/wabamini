<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Plan;
use App\Models\Flow;
use App\Models\FlowVersion;
use App\Models\AIProviderConfig;
use App\Models\AiAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FlowPromptToFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Tenant $tenant;
    protected Plan $plan;
    protected Flow $flow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = Plan::create([
            'id' => 1,
            'name' => 'Growth Plan',
            'description' => 'Growth Plan description',
            'is_active' => true,
            'trial_days' => 14,
            'max_team_members' => 5,
            'max_campaigns' => 5,
            'max_integrations' => 5,
            'own_crm_access' => true,
            'max_channels' => 5,
            'max_automations' => 5,
            'flow_credits' => 10,
            'sort_order' => 1
        ]);

        $this->tenant = Tenant::create([
            'company_name' => 'Flow AI Labs',
            'status' => 'active',
            'onboarding_step' => 'complete',
            'plan_id' => $this->plan->id,
            'used_flow_credits' => 2
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Sarah',
            'last_name' => 'Connor',
            'email' => 'sarah@flowailabs.io',
            'password' => Hash::make('Cyberdyne123!'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->flow = Flow::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Workspace Flow',
            'trigger_type' => 'inbound_message',
            'channel_type' => 'omnichannel',
            'is_active' => false,
        ]);

        FlowVersion::create([
            'flow_id' => $this->flow->id,
            'version_number' => 1,
            'definition' => ['nodes' => [], 'edges' => []],
            'is_published' => false,
            'created_by' => $this->user->id,
        ]);
    }

    public function test_get_flow_credits_returns_correct_balance(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/flows/flow-credits');

        $response->assertStatus(200)
            ->assertJson([
                'max_credits' => 10,
                'used_credits' => 2,
                'remaining_credits' => 8,
                'plan_name' => 'Growth Plan'
            ]);
    }

    public function test_prompt_to_flow_generates_workflow_and_deducts_credit(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/flows/{$this->flow->id}/prompt-to-flow", [
                'prompt' => 'Create a customer support bot with a menu for booking or speaking with an agent'
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'definition' => ['nodes', 'edges'],
                'flow_name',
                'description',
                'version',
                'credits' => ['max_credits', 'used_credits', 'remaining_credits']
            ]);

        $this->assertEquals(3, $this->tenant->fresh()->used_flow_credits);
        $this->assertEquals(7, $response->json('credits.remaining_credits'));
        $this->assertGreaterThanOrEqual(2, count($response->json('definition.nodes')));
    }

    public function test_prompt_to_flow_enforces_credits_limit(): void
    {
        $this->tenant->update(['used_flow_credits' => 10]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/flows/{$this->flow->id}/prompt-to-flow", [
                'prompt' => 'Create a simple lead flow'
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'error_code' => 'FLOW_CREDITS_EXHAUSTED'
            ]);

        $this->assertEquals(10, $this->tenant->fresh()->used_flow_credits);
    }
}
