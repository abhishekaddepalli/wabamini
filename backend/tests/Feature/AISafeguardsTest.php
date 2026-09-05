<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\AiChatbot;
use App\Models\AiUsageLog;
use App\Models\ChannelConnection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowExecution;
use App\Models\FlowVersion;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AIProviderService;
use App\Services\Flow\FlowRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AISafeguardsTest extends TestCase
{
    use RefreshDatabase;

    protected AIProviderService $aiService;
    protected Admin $admin;
    protected AdminRole $adminRole;
    protected Tenant $tenant;
    protected User $tenantUser;
    protected Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aiService = app(AIProviderService::class);

        $this->adminRole = AdminRole::firstOrCreate(
            ['name' => 'Super Admin'],
            ['permissions' => ['*']]
        );

        $this->admin = Admin::firstOrCreate(
            ['email' => 'admin@whatsomni.com'],
            [
                'role_id' => $this->adminRole->id,
                'first_name' => 'Super',
                'last_name' => 'Admin',
                'password' => bcrypt('Secret123!'),
                'status' => 'active',
            ]
        );

        $this->plan = Plan::create([
            'name' => 'Starter Plan',
            'trial_days' => 14,
            'max_team_members' => 5,
            'max_campaigns' => 10,
            'max_integrations' => 5,
            'own_crm_access' => true,
            'max_channels' => 3,
            'max_automations' => 10,
            'flow_credits' => 100,
            'monthly_ai_tokens' => 500, // Small allowance for test
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->tenant = Tenant::create([
            'company_name' => 'Safeguard Test Co',
            'plan_id' => $this->plan->id,
        ]);

        $this->tenantUser = User::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'John',
            'last_name' => 'Tester',
            'email' => 'john@safeguard.test',
            'password' => bcrypt('password123'),
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->aiService->setAiOperationalModel('master_fixed');
    }

    public function test_superadmin_can_fetch_and_update_ai_safeguards(): void
    {
        $response = $this->actingAs($this->admin, 'admin')->getJson('/api/admin/ai-providers');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'ai_safeguards_config' => [
                'rate_limit_per_minute_tenant',
                'rate_limit_per_minute_contact',
                'max_input_tokens',
                'max_output_tokens',
                'monthly_platform_safety_cap',
                'on_limit_breached_action',
                'fallback_message',
            ],
            'platform_usage_stats',
        ]);

        // Update safeguards
        $updateResponse = $this->actingAs($this->admin, 'admin')->putJson('/api/admin/ai-providers', [
            'ai_safeguards_config' => [
                'rate_limit_per_minute_tenant' => 45,
                'rate_limit_per_minute_contact' => 12,
                'max_input_tokens' => 3000,
                'max_output_tokens' => 400,
                'monthly_platform_safety_cap' => 5000000,
                'on_limit_breached_action' => 'block_fallback',
                'fallback_message' => 'Custom platform safeguard message.',
            ]
        ]);

        $updateResponse->assertStatus(200);
        $this->assertEquals(45, $updateResponse->json('ai_safeguards_config.rate_limit_per_minute_tenant'));
        $this->assertEquals(400, $updateResponse->json('ai_safeguards_config.max_output_tokens'));
    }

    public function test_tenant_rate_limiter_blocks_excessive_requests(): void
    {
        $this->aiService->setAiSafeguardsConfig([
            'rate_limit_per_minute_tenant' => 2,
            'fallback_message' => 'Rate limit hit!',
            'on_limit_breached_action' => 'block_fallback',
        ]);

        RateLimiter::clear("ai_rate_tenant:{$this->tenant->id}");

        // Attempt 1: Allowed
        $check1 = $this->aiService->checkSafeguards($this->tenant->id);
        $this->assertNull($check1);

        // Attempt 2: Allowed
        $check2 = $this->aiService->checkSafeguards($this->tenant->id);
        $this->assertNull($check2);

        // Attempt 3: Blocked
        $check3 = $this->aiService->checkSafeguards($this->tenant->id);
        $this->assertNotNull($check3);
        $this->assertTrue($check3['blocked']);
        $this->assertEquals('tenant_rate_limit_exceeded', $check3['reason']);
        $this->assertEquals('Rate limit hit!', $check3['fallback_message']);
    }

    public function test_contact_rate_limiter_blocks_excessive_requests_per_session(): void
    {
        $this->aiService->setAiSafeguardsConfig([
            'rate_limit_per_minute_tenant' => 50,
            'rate_limit_per_minute_contact' => 2,
            'fallback_message' => 'Contact rate limit hit!',
            'on_limit_breached_action' => 'block_fallback',
        ]);

        $contactPhone = '+15551234567';
        RateLimiter::clear("ai_rate_tenant:{$this->tenant->id}");
        RateLimiter::clear("ai_rate_contact:{$this->tenant->id}:" . md5($contactPhone));

        // Attempt 1: Allowed
        $check1 = $this->aiService->checkSafeguards($this->tenant->id, $contactPhone);
        $this->assertNull($check1);

        // Attempt 2: Allowed
        $check2 = $this->aiService->checkSafeguards($this->tenant->id, $contactPhone);
        $this->assertNull($check2);

        // Attempt 3: Blocked for this contact
        $check3 = $this->aiService->checkSafeguards($this->tenant->id, $contactPhone);
        $this->assertNotNull($check3);
        $this->assertTrue($check3['blocked']);
        $this->assertEquals('contact_rate_limit_exceeded', $check3['reason']);

        // Another contact is NOT blocked
        $otherContactPhone = '+15559876543';
        RateLimiter::clear("ai_rate_contact:{$this->tenant->id}:" . md5($otherContactPhone));
        $checkOther = $this->aiService->checkSafeguards($this->tenant->id, $otherContactPhone);
        $this->assertNull($checkOther);
    }

    public function test_monthly_quota_blocks_when_plan_tokens_exhausted(): void
    {
        $this->aiService->setAiOperationalModel('master_fixed');
        $this->aiService->setAiSafeguardsConfig([
            'rate_limit_per_minute_tenant' => 100,
            'fallback_message' => 'Quota exhausted!',
            'on_limit_breached_action' => 'block_fallback',
        ]);

        RateLimiter::clear("ai_rate_tenant:{$this->tenant->id}");

        // Record usage exceeding plan limit (500 tokens)
        AiUsageLog::create([
            'tenant_id' => $this->tenant->id,
            'feature' => 'ai_chatbot',
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'prompt_tokens' => 300,
            'completion_tokens' => 250,
            'total_tokens' => 550,
            'status' => 'success',
            'created_at' => now(),
        ]);

        $check = $this->aiService->checkSafeguards($this->tenant->id);
        $this->assertNotNull($check);
        $this->assertTrue($check['blocked']);
        $this->assertEquals('monthly_tenant_quota_exceeded', $check['reason']);

        // Verify resolveAIExecution returns is_blocked => true
        $resolved = $this->aiService->resolveAIExecution($this->tenant->id, 'ai_chatbot');
        $this->assertTrue($resolved['is_blocked']);
        $this->assertEquals('Quota exhausted!', $resolved['fallback_message']);
    }

    public function test_platform_global_safety_cap_blocks_all_tenants(): void
    {
        // Give tenant unlimited plan allowance so tenant quota is not exceeded
        $this->plan->update(['monthly_ai_tokens' => 0]);

        $this->aiService->setAiOperationalModel('master_fixed');
        $this->aiService->setAiSafeguardsConfig([
            'rate_limit_per_minute_tenant' => 100,
            'monthly_platform_safety_cap' => 1000,
            'fallback_message' => 'Platform global limit reached!',
            'on_limit_breached_action' => 'block_fallback',
        ]);

        RateLimiter::clear("ai_rate_tenant:{$this->tenant->id}");

        // Record platform-wide usage exceeding 1,000 tokens
        AiUsageLog::create([
            'tenant_id' => $this->tenant->id,
            'feature' => 'ai_chatbot',
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'prompt_tokens' => 600,
            'completion_tokens' => 500,
            'total_tokens' => 1100,
            'status' => 'success',
            'created_at' => now(),
        ]);

        $check = $this->aiService->checkSafeguards($this->tenant->id);
        $this->assertNotNull($check);
        $this->assertTrue($check['blocked']);
        $this->assertEquals('platform_global_cap_exceeded', $check['reason']);
        $this->assertEquals('Platform global limit reached!', $check['fallback_message']);
    }

    public function test_input_context_clamping(): void
    {
        $longSystem = str_repeat("Important business context information. ", 500); // ~2,500 tokens
        $messages = [
            ['role' => 'system', 'content' => $longSystem],
            ['role' => 'user', 'content' => 'First user question.'],
            ['role' => 'assistant', 'content' => 'Assistant response.'],
            ['role' => 'user', 'content' => 'Latest question from user.'],
        ];

        $clamped = $this->aiService->clampMessagesToTokenLimit($messages, 500);
        $estimatedTokens = AIProviderService::estimateTokens($clamped);

        $this->assertLessThanOrEqual(550, $estimatedTokens);
        $this->assertEquals('Latest question from user.', end($clamped)['content']);
    }

    public function test_chatbot_test_endpoint_returns_fallback_when_safeguard_triggered(): void
    {
        $this->aiService->setAiOperationalModel('master_fixed');
        $this->aiService->setAiSafeguardsConfig([
            'rate_limit_per_minute_tenant' => 1,
            'fallback_message' => 'Safety limit hit for testing.',
            'on_limit_breached_action' => 'block_fallback',
        ]);

        $chatbot = AiChatbot::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Support Assistant',
            'system_prompt' => 'You are a helpful assistant.',
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'is_active' => true,
        ]);

        RateLimiter::clear("ai_rate_tenant:{$this->tenant->id}");

        // Attempt 1: consumes the 1 allowable attempt
        $this->actingAs($this->tenantUser)->postJson("/api/chatbot/{$chatbot->id}/test", [
            'query' => 'Hello AI'
        ]);

        // Attempt 2: Exceeds rate limit
        $response = $this->actingAs($this->tenantUser)->postJson("/api/chatbot/{$chatbot->id}/test", [
            'query' => 'Are you there?'
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'is_safeguard_blocked' => true,
            'reply' => 'Safety limit hit for testing.',
            'block_reason' => 'tenant_rate_limit_exceeded',
        ]);
    }

    public function test_visual_flow_runner_handles_safeguard_blocks(): void
    {
        $this->aiService->setAiOperationalModel('master_fixed');
        $this->aiService->setAiSafeguardsConfig([
            'rate_limit_per_minute_tenant' => 1,
            'fallback_message' => 'Flow AI throttled by platform safety limits.',
            'on_limit_breached_action' => 'block_fallback',
        ]);

        RateLimiter::clear("ai_rate_tenant:{$this->tenant->id}");

        // Burn the single attempt
        $this->aiService->checkSafeguards($this->tenant->id);

        $contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Alice',
            'phone_number' => '+15550001111',
        ]);

        $connection = ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'whatsapp',
            'name' => 'Main WA',
            'status' => 'connected',
            'credentials' => ['access_token' => 'mock_token'],
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'contact_id' => $contact->id,
            'channel_connection_id' => $connection->id,
            'external_chat_id' => 'wa_chat_123',
            'status' => 'open',
        ]);

        $flow = Flow::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Safeguard Test Flow',
            'trigger_type' => 'inbound_message',
            'is_active' => true,
        ]);

        $flowVersion = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'created_by' => $this->tenantUser->id,
            'is_published' => true,
            'definition' => [
                'nodes' => [
                    [
                        'id' => 'node_ai_1',
                        'type' => 'ai_prompt',
                        'data' => [
                            'prompt' => 'Generate greeting',
                            'save_key' => 'ai_greeting',
                        ]
                    ]
                ],
                'edges' => []
            ],
            'canvas_data' => [
                'nodes' => [
                    [
                        'id' => 'node_ai_1',
                        'type' => 'ai_prompt',
                        'data' => [
                            'prompt' => 'Generate greeting',
                            'save_key' => 'ai_greeting',
                        ]
                    ]
                ],
                'edges' => []
            ]
        ]);

        $execution = FlowExecution::create([
            'tenant_id' => $this->tenant->id,
            'flow_id' => $flow->id,
            'flow_version_id' => $flowVersion->id,
            'contact_id' => $contact->id,
            'channel_connection_id' => $connection->id,
            'conversation_id' => $conversation->id,
            'current_node_id' => 'node_ai_1',
            'status' => 'running',
            'context' => ['variables' => []],
        ]);

        $flowRunner = app(FlowRunner::class);
        $flowRunner->execute($execution);

        $execution->refresh();
        $this->assertEquals('completed', $execution->status);
        $this->assertEquals('Flow AI throttled by platform safety limits.', $execution->context['variables']['ai_greeting']);
    }

    public function test_tenant_can_fetch_and_update_own_byok_safeguards(): void
    {
        $response = $this->actingAs($this->tenantUser)->getJson('/api/settings/ai-providers/settings');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'operational_model',
            'safeguards' => [
                'rate_limit_per_minute_contact',
                'max_input_tokens',
                'max_output_tokens',
                'on_limit_breached_action',
                'fallback_message',
            ],
        ]);

        // Tenant updates safeguards
        $updateResp = $this->actingAs($this->tenantUser)->postJson('/api/settings/ai-providers/settings', [
            'safeguards' => [
                'rate_limit_per_minute_contact' => 5,
                'max_input_tokens' => 1500,
                'max_output_tokens' => 250,
                'on_limit_breached_action' => 'block_fallback',
                'fallback_message' => 'Tenant custom fallback message for customer throttling.',
            ]
        ]);

        $updateResp->assertStatus(200);
        $this->assertEquals(5, $updateResp->json('safeguards.rate_limit_per_minute_contact'));
        $this->assertEquals(250, $updateResp->json('safeguards.max_output_tokens'));
        $this->assertEquals('Tenant custom fallback message for customer throttling.', $updateResp->json('safeguards.fallback_message'));

        $persisted = $this->aiService->getTenantAiSafeguardsConfig($this->tenant->id);
        $this->assertEquals(5, $persisted['rate_limit_per_minute_contact']);
        $this->assertEquals(1500, $persisted['max_input_tokens']);
    }

    public function test_byok_mode_enforces_contact_chat_throttling(): void
    {
        $this->aiService->setAiOperationalModel('byok');
        $this->aiService->setTenantAiSafeguardsConfig($this->tenant->id, [
            'rate_limit_per_minute_contact' => 2,
            'fallback_message' => 'BYOK contact rate limit exceeded.',
            'on_limit_breached_action' => 'block_fallback',
        ]);

        $contactPhone = '+15554443322';
        RateLimiter::clear("ai_rate_contact:{$this->tenant->id}:" . md5($contactPhone));

        // Attempt 1: Allowed
        $check1 = $this->aiService->checkSafeguards($this->tenant->id, $contactPhone);
        $this->assertNull($check1);

        // Attempt 2: Allowed
        $check2 = $this->aiService->checkSafeguards($this->tenant->id, $contactPhone);
        $this->assertNull($check2);

        // Attempt 3: Blocked
        $check3 = $this->aiService->checkSafeguards($this->tenant->id, $contactPhone);
        $this->assertNotNull($check3);
        $this->assertTrue($check3['blocked']);
        $this->assertEquals('contact_rate_limit_exceeded', $check3['reason']);
        $this->assertEquals('BYOK contact rate limit exceeded.', $check3['fallback_message']);

        // Check resolveAIExecution reflects blocked state
        $resolved = $this->aiService->resolveAIExecution($this->tenant->id, 'ai_chatbot', null, null, $contactPhone);
        $this->assertTrue($resolved['is_blocked']);
        $this->assertEquals('BYOK contact rate limit exceeded.', $resolved['fallback_message']);
    }

    public function test_byok_mode_clamping_with_tenant_safeguards(): void
    {
        $this->aiService->setAiOperationalModel('byok');
        $this->aiService->setTenantAiSafeguardsConfig($this->tenant->id, [
            'max_input_tokens' => 300,
            'max_output_tokens' => 150,
        ]);

        $longSystem = str_repeat("Detailed enterprise tenant background documentation. ", 200); // ~1,000 tokens
        $messages = [
            ['role' => 'system', 'content' => $longSystem],
            ['role' => 'user', 'content' => 'Customer query regarding warranty.'],
        ];

        // Call mock completion with tenantId
        $output = $this->aiService->generateCompletion('openai', 'mock_key', 'gpt-4o-mini', $messages, 0.7, 'chatbot', $this->tenant->id);
        $this->assertNotEmpty($output);
    }
}
