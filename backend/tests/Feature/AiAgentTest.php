<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Contact;
use App\Models\AiAgent;
use App\Models\ChannelConnection;
use App\Models\Flow;
use App\Models\KnowledgeBase;
use App\Models\AIProviderConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AiAgentTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Tenant $tenant;
    protected ChannelConnection $channel;

    protected function setUp(): void
    {
        parent::setUp();

        \App\Models\Plan::create([
            'id' => 1,
            'name' => 'Free Plan',
            'description' => 'Free description',
            'is_active' => true,
            'trial_days' => 14,
            'max_team_members' => 5,
            'max_campaigns' => 5,
            'max_integrations' => 5,
            'own_crm_access' => true,
            'max_channels' => 5,
            'max_automations' => 5,
            'sort_order' => 1
        ]);

        $this->tenant = Tenant::create([
            'company_name' => 'Agent Inc',
            'status' => 'trial',
            'onboarding_step' => 'complete',
            'plan_id' => 1
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@lovelace.io',
            'password' => Hash::make('Lovelace123!'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->channel = ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'telegram',
            'name' => 'Telegram Test Bot',
            'display_name' => 'Telegram Test Bot',
            'status' => 'connected',
            'credentials' => ['token' => 'mock_token'],
        ]);
    }

    public function test_can_manage_ai_agents(): void
    {
        $this->actingAs($this->user);

        // 1. Create AI Agent
        $response = $this->postJson('/api/agents', [
            'name' => 'Support Desk Agent',
            'type' => 'inbound',
            'system_prompt' => 'You are support assistant.',
            'fallback_message' => 'Sorry, outside hours.',
            'status' => 'active',
            'channel_ids' => [$this->channel->id]
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('name', 'Support Desk Agent');

        $agentId = $response->json('id');

        // 2. Fetch list
        $response = $this->getJson('/api/agents');
        $response->assertStatus(200)
            ->assertJsonCount(1);

        // 3. Update agent
        $response = $this->putJson("/api/agents/{$agentId}", [
            'name' => 'Updated Support Desk Agent',
            'type' => 'inbound',
            'system_prompt' => 'You are helper.',
            'status' => 'inactive',
            'channel_ids' => []
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('name', 'Updated Support Desk Agent')
            ->assertJsonPath('status', 'inactive');

        // 4. Delete agent
        $response = $this->deleteJson("/api/agents/{$agentId}");
        $response->assertStatus(200);

        $this->assertSoftDeleted('ai_agents', ['id' => $agentId]);
    }

    public function test_cannot_assign_already_assigned_inbound_channel_to_new_inbound_agent(): void
    {
        $this->actingAs($this->user);

        // 1. Create first Inbound Agent assigned to the channel
        $firstAgentRes = $this->postJson('/api/agents', [
            'name' => 'Primary Inbound Bot',
            'type' => 'inbound',
            'system_prompt' => 'You are the primary bot.',
            'status' => 'active',
            'channel_ids' => [$this->channel->id]
        ]);
        $firstAgentRes->assertStatus(201);
        $firstAgentId = $firstAgentRes->json('id');

        // 2. Attempt to create second Inbound Agent with the SAME channel -> Should be blocked (422)
        $secondAgentRes = $this->postJson('/api/agents', [
            'name' => 'Secondary Inbound Bot',
            'type' => 'inbound',
            'system_prompt' => 'You are the secondary bot.',
            'status' => 'active',
            'channel_ids' => [$this->channel->id]
        ]);

        $secondAgentRes->assertStatus(422)
            ->assertJsonPath('conflict_channel_id', $this->channel->id)
            ->assertJsonPath('conflict_agent_name', 'Primary Inbound Bot');

        $this->assertStringContainsString('already assigned to "Primary Inbound Bot" for inbound handling', $secondAgentRes->json('message'));

        // 3. Creating an OUTBOUND agent with the same channel is ALLOWED
        $outboundRes = $this->postJson('/api/agents', [
            'name' => 'Outbound Marketing Bot',
            'type' => 'outbound',
            'system_prompt' => 'You are the outbound bot.',
            'status' => 'active',
            'channel_ids' => [$this->channel->id]
        ]);
        $outboundRes->assertStatus(201);
    }
}
