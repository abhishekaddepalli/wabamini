<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Tenant;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\AIProviderConfig;
use App\Models\AiAgent;
use App\Services\AIProviderService;

class DeepOperationalModesTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenantA;
    protected User $tenantUserA;
    protected Tenant $tenantB;
    protected User $tenantUserB;
    protected Admin $admin;
    protected AdminRole $adminRole;
    protected AIProviderService $aiService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create([
            'company_name' => 'Acme Alpha Corp',
            'flow_credits_balance' => 100,
        ]);

        $this->tenantUserA = User::create([
            'tenant_id' => $this->tenantA->id,
            'first_name' => 'Alice',
            'last_name' => 'Alpha',
            'email' => 'alice@alpha.com',
            'password' => bcrypt('Password123!'),
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->tenantB = Tenant::create([
            'company_name' => 'Beta Dynamics Corp',
            'flow_credits_balance' => 50,
        ]);

        $this->tenantUserB = User::create([
            'tenant_id' => $this->tenantB->id,
            'first_name' => 'Bob',
            'last_name' => 'Beta',
            'email' => 'bob@beta.com',
            'password' => bcrypt('Password123!'),
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->adminRole = AdminRole::firstOrCreate(
            ['name' => 'Super Admin'],
            ['permissions' => ['*']]
        );

        $this->admin = Admin::firstOrCreate(
            ['email' => 'superadmin@whatsomni.com'],
            [
                'role_id' => $this->adminRole->id,
                'first_name' => 'Super',
                'last_name' => 'Admin',
                'password' => bcrypt('AdminPassword123!'),
                'status' => 'active',
            ]
        );

        $this->aiService = app(AIProviderService::class);
    }

    /**
     * TEST 1: BYOK - End-to-End Lifecycle, Multi-tenant Isolation, Enabled Models & Execution
     */
    public function test_byok_deep_lifecycle_and_isolation(): void
    {
        $this->aiService->setAiOperationalModel('byok');

        // 1. Check Initial State for Tenant A (0 keys)
        $respA = $this->actingAs($this->tenantUserA)->getJson('/api/settings/ai-providers');
        $respA->assertStatus(200);
        $this->assertEquals('byok', $respA->json('operational_model'));
        $providersA = $respA->json('providers');
        $this->assertFalse($providersA['openai']['is_configured']);
        $this->assertFalse($providersA['groq']['is_configured']);

        // 2. Tenant A connects OpenAI Key with only 1 model enabled ('gpt-4o-mini')
        \Illuminate\Support\Facades\Http::fake([
            'https://api.openai.com/*' => \Illuminate\Support\Facades\Http::response(['data' => []], 200),
        ]);

        $tenantAKey = 'sk-tenant-A-private-secret-key-123456';
        $saveResp = $this->actingAs($this->tenantUserA)->postJson('/api/settings/ai-providers', [
            'provider_name' => 'openai',
            'api_key' => $tenantAKey,
            'enabled_models' => ['gpt-4o-mini'],
            'default_model' => 'gpt-4o-mini',
        ]);
        $saveResp->assertStatus(200);

        // Verify /api/settings/ai-providers for Tenant A shows configured & sanitized default_model
        $respAfterSave = $this->actingAs($this->tenantUserA)->getJson('/api/settings/ai-providers');
        $openaiDetails = $respAfterSave->json('providers.openai');
        $this->assertTrue($openaiDetails['is_configured']);
        $this->assertTrue($openaiDetails['is_active']);
        $this->assertEquals(['gpt-4o-mini'], $openaiDetails['enabled_models']);
        $this->assertEquals('gpt-4o-mini', $openaiDetails['default_model']);

        // 3. Verify /api/agents/provider-configs for Tenant A strictly returns only OpenAI with gpt-4o-mini
        $agentConfigsA = $this->actingAs($this->tenantUserA)->getJson('/api/agents/provider-configs');
        $agentConfigsA->assertStatus(200);
        $listA = $agentConfigsA->json();
        $this->assertCount(1, $listA);
        $this->assertEquals('openai', $listA[0]['provider_name']);
        $this->assertEquals(['gpt-4o-mini'], $listA[0]['enabled_models']);
        $this->assertEquals('gpt-4o-mini', $listA[0]['default_model']);

        // 4. Verify Multi-tenant Isolation: Tenant B has 0 keys configured
        $agentConfigsB = $this->actingAs($this->tenantUserB)->getJson('/api/agents/provider-configs');
        $agentConfigsB->assertStatus(200);
        $this->assertEmpty($agentConfigsB->json(), 'Tenant B must not see Tenant A keys or providers');

        // 5. Execution Resolution for Tenant A: strictly uses Tenant A's private key
        $execA = $this->aiService->resolveAIExecution($this->tenantA->id, 'ai_agents', 'openai', 'gpt-4o-mini');
        $this->assertEquals('byok', $execA['mode']);
        $this->assertEquals('openai', $execA['provider']);
        $this->assertEquals('gpt-4o-mini', $execA['model']);
        $this->assertEquals($tenantAKey, $execA['api_key']);
        $this->assertFalse($execA['is_simulated']);

        // 6. Tenant B attempts execution without keys: strictly simulated
        $execB = $this->aiService->resolveAIExecution($this->tenantB->id, 'ai_agents');
        $this->assertTrue($execB['is_simulated'], 'Tenant B without keys must be marked simulated');
    }

    /**
     * TEST 2: Master Fixed - Central Routing, Super Keys, and Zero Tenant Exposure
     */
    public function test_master_fixed_deep_validation(): void
    {
        $superAdminAnthropicKey = 'sk-ant-master-superadmin-secret-777';
        $superAdminGeminiKey = 'AIzaSyMasterSuperAdminGeminiKey-888';

        $this->aiService->setAiOperationalModel('master_fixed');
        $this->aiService->setAdminProvidersKeys([
            'anthropic' => $superAdminAnthropicKey,
            'gemini' => $superAdminGeminiKey,
        ]);
        $this->aiService->setAiFeatureRouting([
            'ai_agents' => ['provider' => 'anthropic', 'model' => 'claude-3-5-sonnet-20241022'],
            'rag_kb' => ['provider' => 'gemini', 'model' => 'gemini-1.5-pro'],
            'flow_ai_prompt' => ['provider' => 'anthropic', 'model' => 'claude-3-5-haiku-20241022'],
        ]);

        // 1. Settings page for tenant returns empty providers and BYOK is hidden
        $settingsResp = $this->actingAs($this->tenantUserA)->getJson('/api/settings/ai-providers');
        $settingsResp->assertStatus(200);
        $this->assertEquals('master_fixed', $settingsResp->json('operational_model'));
        $this->assertEmpty($settingsResp->json('providers'));

        // 2. Tenant cannot modify keys (403 Forbidden)
        $forbiddenResp = $this->actingAs($this->tenantUserA)->postJson('/api/settings/ai-providers', [
            'provider_name' => 'openai',
            'api_key' => 'sk-invalid-hack',
            'enabled_models' => ['gpt-4o'],
        ]);
        $forbiddenResp->assertStatus(403);

        // 3. /api/agents/provider-configs returns abstract platform descriptor
        $configsResp = $this->actingAs($this->tenantUserA)->getJson('/api/agents/provider-configs');
        $configsResp->assertStatus(200);
        $configs = $configsResp->json();
        $this->assertCount(1, $configs);
        $this->assertEquals('platform', $configs[0]['provider_name']);
        $this->assertEquals('master_fixed', $configs[0]['mode']);
        $this->assertTrue($configs[0]['is_centrally_managed']);

        // 4. Execution Resolution strictly uses Super Keys and feature matrix
        $agentExec = $this->aiService->resolveAIExecution($this->tenantA->id, 'ai_agents');
        $this->assertEquals('master_fixed', $agentExec['mode']);
        $this->assertEquals('anthropic', $agentExec['provider']);
        $this->assertEquals('claude-3-5-sonnet-20241022', $agentExec['model']);
        $this->assertEquals($superAdminAnthropicKey, $agentExec['api_key']);
        $this->assertFalse($agentExec['is_simulated']);

        $kbExec = $this->aiService->resolveAIExecution($this->tenantA->id, 'rag_kb');
        $this->assertEquals('gemini', $kbExec['provider']);
        $this->assertEquals('gemini-1.5-pro', $kbExec['model']);
        $this->assertEquals($superAdminGeminiKey, $kbExec['api_key']);

        $flowExec = $this->aiService->resolveAIExecution($this->tenantA->id, 'flow_ai_prompt');
        $this->assertEquals('anthropic', $flowExec['provider']);
        $this->assertEquals('claude-3-5-haiku-20241022', $flowExec['model']);
        $this->assertEquals($superAdminAnthropicKey, $flowExec['api_key']);
    }
}
