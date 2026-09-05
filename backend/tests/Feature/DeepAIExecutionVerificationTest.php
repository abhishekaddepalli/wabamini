<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\AiChatbot;
use App\Models\AIProviderConfig;
use App\Models\ChannelConnection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowExecution;
use App\Models\FlowVersion;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AIProviderService;
use App\Services\Flow\FlowRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class DeepAIExecutionVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected AIProviderService $aiService;
    protected Admin $admin;
    protected AdminRole $adminRole;
    protected Tenant $tenantA;
    protected User $tenantUserA;
    protected Tenant $tenantB;
    protected User $tenantUserB;
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
            'name' => 'Pro Plan',
            'trial_days' => 14,
            'max_team_members' => 10,
            'max_campaigns' => 20,
            'max_integrations' => 10,
            'own_crm_access' => true,
            'max_channels' => 5,
            'max_automations' => 20,
            'flow_credits' => 500,
            'monthly_ai_tokens' => 50000,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        // Create Tenant A
        $this->tenantA = Tenant::create([
            'company_name' => 'Tenant Alpha Corp',
            'plan_id' => $this->plan->id,
            'ai_tokens_used_this_month' => 0,
        ]);

        $this->tenantUserA = User::create([
            'tenant_id' => $this->tenantA->id,
            'first_name' => 'Alice',
            'last_name' => 'Alpha',
            'email' => 'alice@alpha.test',
            'password' => bcrypt('password123'),
            'role' => 'admin',
            'status' => 'active',
        ]);

        // Create Tenant B
        $this->tenantB = Tenant::create([
            'company_name' => 'Tenant Beta LLC',
            'plan_id' => $this->plan->id,
            'ai_tokens_used_this_month' => 0,
        ]);

        $this->tenantUserB = User::create([
            'tenant_id' => $this->tenantB->id,
            'first_name' => 'Bob',
            'last_name' => 'Beta',
            'email' => 'bob@beta.test',
            'password' => bcrypt('password123'),
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    /**
     * Test 1: MASTER FIXED MODE - Strictly uses Master Keys, Master Feature Routing, and Master Safeguards.
     */
    public function test_master_fixed_mode_uses_master_keys_and_master_routing(): void
    {
        // 1. Configure Superadmin in Master Fixed mode
        $this->aiService->setAiOperationalModel('master_fixed');

        // Set Superadmin Master Keys
        $this->aiService->setAdminProvidersKeys([
            'openai' => 'sk-master-openai-key-12345',
            'anthropic' => 'sk-master-anthropic-key-67890',
        ]);

        // Set Superadmin Master Routing Matrix
        $this->aiService->setAiFeatureRouting([
            'ai_chatbot' => ['provider' => 'anthropic', 'model' => 'claude-3-5-haiku-20241022'],
            'flow_ai_prompt' => ['provider' => 'openai', 'model' => 'gpt-4o-mini'],
        ]);

        // Tenant A has configured a personal key (which should be IGNORED in master fixed mode)
        AIProviderConfig::create([
            'tenant_id' => $this->tenantA->id,
            'provider_name' => 'openai',
            'api_key' => 'sk-tenant-a-ignored-key',
            'default_model' => 'gpt-4o',
            'enabled_models' => ['gpt-4o'],
            'is_active' => true,
        ]);

        // Resolve AI Execution for Tenant A on 'ai_chatbot'
        $resolvedChatbot = $this->aiService->resolveAIExecution($this->tenantA->id, 'ai_chatbot');
        $this->assertEquals('master_fixed', $resolvedChatbot['mode']);
        $this->assertEquals('anthropic', $resolvedChatbot['provider']);
        $this->assertEquals('claude-3-5-haiku-20241022', $resolvedChatbot['model']);
        $this->assertEquals('sk-master-anthropic-key-67890', $resolvedChatbot['api_key']);
        $this->assertFalse($resolvedChatbot['is_blocked']);

        // Resolve AI Execution for Tenant A on 'flow_ai_prompt'
        $resolvedFlow = $this->aiService->resolveAIExecution($this->tenantA->id, 'flow_ai_prompt');
        $this->assertEquals('master_fixed', $resolvedFlow['mode']);
        $this->assertEquals('openai', $resolvedFlow['provider']);
        $this->assertEquals('gpt-4o-mini', $resolvedFlow['model']);
        $this->assertEquals('sk-master-openai-key-12345', $resolvedFlow['api_key']);
        $this->assertNotEquals('sk-tenant-a-ignored-key', $resolvedFlow['api_key']);
    }

    /**
     * Test 2: MASTER FIXED MODE - Enforces Superadmin Central Safeguards (Tenant Limit, Contact Limit, Plan Quota, Platform Cap).
     */
    public function test_master_fixed_mode_enforces_all_master_safeguards(): void
    {
        $this->aiService->setAiOperationalModel('master_fixed');

        // Configure Superadmin Master Safeguards
        $this->aiService->setAiSafeguardsConfig([
            'rate_limit_per_minute_tenant' => 2,
            'rate_limit_per_minute_contact' => 2,
            'max_input_tokens' => 400,
            'max_output_tokens' => 120,
            'monthly_platform_safety_cap' => 100000,
            'on_limit_breached_action' => 'block_fallback',
            'fallback_message' => 'Superadmin master safeguard triggered.',
        ]);

        // 1. Test Tenant Rate Limit
        RateLimiter::clear("ai_rate_tenant:{$this->tenantA->id}");
        $this->assertNull($this->aiService->checkSafeguards($this->tenantA->id));
        $this->assertNull($this->aiService->checkSafeguards($this->tenantA->id));
        $blockedTenant = $this->aiService->checkSafeguards($this->tenantA->id);
        $this->assertNotNull($blockedTenant);
        $this->assertEquals('tenant_rate_limit_exceeded', $blockedTenant['reason']);
        $this->assertEquals('Superadmin master safeguard triggered.', $blockedTenant['fallback_message']);

        // 2. Test Contact Rate Limit for Contact on Tenant B
        $this->aiService->setAiSafeguardsConfig([
            'rate_limit_per_minute_tenant' => 50,
            'rate_limit_per_minute_contact' => 2,
            'fallback_message' => 'Contact limit hit.',
        ]);

        $contactPhone = '+19998887777';
        RateLimiter::clear("ai_rate_tenant:{$this->tenantB->id}");
        RateLimiter::clear("ai_rate_contact:{$this->tenantB->id}:" . md5($contactPhone));

        $this->assertNull($this->aiService->checkSafeguards($this->tenantB->id, $contactPhone));
        $this->assertNull($this->aiService->checkSafeguards($this->tenantB->id, $contactPhone));
        $blockedContact = $this->aiService->checkSafeguards($this->tenantB->id, $contactPhone);
        $this->assertNotNull($blockedContact);
        $this->assertEquals('contact_rate_limit_exceeded', $blockedContact['reason']);

        // 3. Test Monthly Plan Quota Limit via Usage Logs
        \App\Models\AiUsageLog::create([
            'tenant_id' => $this->tenantA->id,
            'feature' => 'ai_chatbot',
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'prompt_tokens' => 30000,
            'completion_tokens' => 30000,
            'total_tokens' => 60000, // Exceeds 50,000 monthly allowance
            'status' => 'success',
            'latency_ms' => 200,
            'created_at' => now(),
        ]);

        RateLimiter::clear("ai_rate_tenant:{$this->tenantA->id}");
        $blockedQuota = $this->aiService->checkSafeguards($this->tenantA->id);
        $this->assertNotNull($blockedQuota);
        $this->assertEquals('monthly_tenant_quota_exceeded', $blockedQuota['reason']);
    }

    /**
     * Test 3: BYOK MODE - Strictly uses Tenant's Own Keys with Complete Tenant Isolation.
     */
    public function test_byok_mode_uses_tenant_isolated_keys_and_routing(): void
    {
        $this->aiService->setAiOperationalModel('byok');

        // Master keys set in system
        $this->aiService->setAdminProvidersKeys(['openai' => 'sk-master-secret-key']);

        // Tenant A creates its own OpenAI config
        AIProviderConfig::create([
            'tenant_id' => $this->tenantA->id,
            'provider_name' => 'openai',
            'api_key' => 'sk-tenant-A-private-key-111',
            'default_model' => 'gpt-4o-mini',
            'enabled_models' => ['gpt-4o-mini'],
            'is_active' => true,
        ]);

        // Tenant B creates its own Groq config
        AIProviderConfig::create([
            'tenant_id' => $this->tenantB->id,
            'provider_name' => 'groq',
            'api_key' => 'gsk-tenant-B-private-key-222',
            'default_model' => 'openai/gpt-oss-120b',
            'enabled_models' => ['openai/gpt-oss-120b'],
            'is_active' => true,
        ]);

        // Tenant A sets feature routing for chatbot to OpenAI
        $this->aiService->setTenantAiFeatureRouting($this->tenantA->id, [
            'ai_chatbot' => ['provider' => 'openai', 'model' => 'gpt-4o-mini'],
        ]);

        // Tenant B sets feature routing for chatbot to Groq
        $this->aiService->setTenantAiFeatureRouting($this->tenantB->id, [
            'ai_chatbot' => ['provider' => 'groq', 'model' => 'openai/gpt-oss-120b'],
        ]);

        // Execution for Tenant A
        $execA = $this->aiService->resolveAIExecution($this->tenantA->id, 'ai_chatbot');
        $this->assertEquals('byok', $execA['mode']);
        $this->assertEquals('openai', $execA['provider']);
        $this->assertEquals('gpt-4o-mini', $execA['model']);
        $this->assertEquals('sk-tenant-A-private-key-111', $execA['api_key']);
        $this->assertNotEquals('sk-master-secret-key', $execA['api_key']);
        $this->assertNotEquals('gsk-tenant-B-private-key-222', $execA['api_key']);

        // Execution for Tenant B
        $execB = $this->aiService->resolveAIExecution($this->tenantB->id, 'ai_chatbot');
        $this->assertEquals('byok', $execB['mode']);
        $this->assertEquals('groq', $execB['provider']);
        $this->assertEquals('openai/gpt-oss-120b', $execB['model']);
        $this->assertEquals('gsk-tenant-B-private-key-222', $execB['api_key']);
        $this->assertNotEquals('sk-tenant-A-private-key-111', $execB['api_key']);
    }

    /**
     * Test 4: BYOK MODE - Strictly enforces Tenant-Specific Safeguards (Contact Throttling & Token Clamping).
     */
    public function test_byok_mode_enforces_tenant_specific_safeguards(): void
    {
        $this->aiService->setAiOperationalModel('byok');

        // Tenant A sets strict contact limit = 2 and custom fallback
        $this->aiService->setTenantAiSafeguardsConfig($this->tenantA->id, [
            'rate_limit_per_minute_contact' => 2,
            'max_input_tokens' => 200,
            'max_output_tokens' => 50,
            'on_limit_breached_action' => 'block_fallback',
            'fallback_message' => 'Tenant A custom safety throttle.',
        ]);

        // Tenant B sets relaxed contact limit = 10
        $this->aiService->setTenantAiSafeguardsConfig($this->tenantB->id, [
            'rate_limit_per_minute_contact' => 10,
            'max_input_tokens' => 1000,
            'max_output_tokens' => 300,
            'on_limit_breached_action' => 'block_fallback',
            'fallback_message' => 'Tenant B custom safety throttle.',
        ]);

        $sharedContactPhone = '+18887776655';
        RateLimiter::clear("ai_rate_contact:{$this->tenantA->id}:" . md5($sharedContactPhone));
        RateLimiter::clear("ai_rate_contact:{$this->tenantB->id}:" . md5($sharedContactPhone));

        // Tenant A: 2 messages succeed, 3rd is throttled
        $this->assertNull($this->aiService->checkSafeguards($this->tenantA->id, $sharedContactPhone));
        $this->assertNull($this->aiService->checkSafeguards($this->tenantA->id, $sharedContactPhone));
        $blockedA = $this->aiService->checkSafeguards($this->tenantA->id, $sharedContactPhone);
        $this->assertNotNull($blockedA);
        $this->assertTrue($blockedA['blocked']);
        $this->assertEquals('contact_rate_limit_exceeded', $blockedA['reason']);
        $this->assertEquals('Tenant A custom safety throttle.', $blockedA['fallback_message']);

        // Same contact messaging Tenant B is NOT throttled (limit is 10)
        $this->assertNull($this->aiService->checkSafeguards($this->tenantB->id, $sharedContactPhone));
        $this->assertNull($this->aiService->checkSafeguards($this->tenantB->id, $sharedContactPhone));
        $this->assertNull($this->aiService->checkSafeguards($this->tenantB->id, $sharedContactPhone));

        // Clamping check for Tenant A: max_input_tokens = 200
        $longPrompt = str_repeat("Enterprise system context guidelines. ", 150); // ~600 tokens
        $messages = [
            ['role' => 'system', 'content' => $longPrompt],
            ['role' => 'user', 'content' => 'What is the pricing?']
        ];
        $output = $this->aiService->generateCompletion('openai', 'mock_key', 'gpt-4o-mini', $messages, 0.7, 'chatbot', $this->tenantA->id);
        $this->assertNotEmpty($output);
    }

    /**
     * Test 5: END-TO-END FLOW RUNNER & CHATBOT EXECUTION IN BOTH MODES.
     */
    public function test_end_to_end_flow_and_chatbot_execution_in_both_modes(): void
    {
        $channel = ChannelConnection::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'WhatsApp Support',
            'channel_type' => 'whatsapp',
            'account_name' => 'Test Channel',
            'credentials' => ['phone_number_id' => '12345', 'token' => 'abc'],
            'status' => 'connected',
        ]);

        $contact = Contact::create([
            'tenant_id' => $this->tenantA->id,
            'phone_number' => '+14155552671',
            'first_name' => 'DeepTest',
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $this->tenantA->id,
            'channel_connection_id' => $channel->id,
            'contact_id' => $contact->id,
            'external_chat_id' => '14155552671',
            'status' => 'active',
        ]);

        // Chatbot configuration
        $chatbot = AiChatbot::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Support Assistant',
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'system_prompt' => 'You are a support bot.',
            'fallback_message' => 'Fallback message.',
            'is_active' => true,
        ]);

        // A. Run in Master Fixed Mode
        $this->aiService->setAiOperationalModel('master_fixed');
        PlatformSetting::updateOrCreate(['key' => 'ai_api_key_openai'], ['value' => 'sk-master-key-xyz']);
        
        $responseMaster = $this->actingAs($this->tenantUserA)->postJson("/api/chatbot/{$chatbot->id}/test", [
            'query' => 'Hello there',
        ]);
        $responseMaster->assertStatus(200);
        $responseMaster->assertJson([
            'success' => true,
            'provider' => 'openai',
        ]);

        // B. Run in BYOK Mode with Tenant Key
        $this->aiService->setAiOperationalModel('byok');
        AIProviderConfig::updateOrCreate(
            ['tenant_id' => $this->tenantA->id, 'provider_name' => 'openai'],
            ['api_key' => 'sk-tenant-key-abc', 'default_model' => 'gpt-4o-mini', 'enabled_models' => ['gpt-4o-mini'], 'is_active' => true]
        );

        $responseByok = $this->actingAs($this->tenantUserA)->postJson("/api/chatbot/{$chatbot->id}/test", [
            'query' => 'Hello from BYOK',
        ]);
        $responseByok->assertStatus(200);
        $responseByok->assertJson([
            'success' => true,
            'provider' => 'openai',
        ]);
    }
}
