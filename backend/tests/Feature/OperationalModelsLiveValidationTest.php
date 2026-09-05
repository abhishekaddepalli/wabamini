<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Tenant;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\AIProviderConfig;
use App\Services\AIProviderService;

class OperationalModelsLiveValidationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $tenantUser;
    protected Admin $admin;
    protected AdminRole $adminRole;
    protected AIProviderService $aiService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'company_name' => 'Test Corp',
            'flow_credits_balance' => 100,
        ]);

        $this->tenantUser = User::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Alice',
            'last_name' => 'Tester',
            'email' => 'alice@testcorp.com',
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
     * TEST 1: BYOK (Bring Your Own Key) Model Validation
     */
    public function test_byok_mode_lifecycle_and_resolution(): void
    {
        $this->aiService->setAiOperationalModel('byok');

        // Sub-test A: Tenant has 0 connected keys
        $emptyConfigsResponse = $this->actingAs($this->tenantUser)
            ->getJson('/api/agents/provider-configs');
        
        $emptyConfigsResponse->assertStatus(200);
        $emptyConfigs = $emptyConfigsResponse->json();
        
        // Assert no providers or models are shown
        $this->assertEmpty($emptyConfigs, 'BYOK with 0 tenant keys must return 0 providers to tenant');

        // Execution when no keys exist falls back to simulated
        $simulatedExec = $this->aiService->resolveAIExecution($this->tenant->id, 'ai_agents');
        $this->assertTrue($simulatedExec['is_simulated'], 'BYOK without keys must be marked as simulated');
        $this->assertEquals('byok', $simulatedExec['mode']);

        // Sub-test B: Tenant adds their private Groq Key
        $tenantGroqKey = 'gsk_tenant_private_live_test_key_999';
        $config = AIProviderConfig::create([
            'tenant_id' => $this->tenant->id,
            'provider_name' => 'groq',
            'api_key' => $tenantGroqKey,
            'enabled_models' => ['openai/gpt-oss-120b', 'qwen/qwen3.6-27b'],
            'default_model' => 'openai/gpt-oss-120b',
            'is_active' => true,
        ]);

        $configuredConfigsResponse = $this->actingAs($this->tenantUser)
            ->getJson('/api/agents/provider-configs');
        
        $configuredConfigsResponse->assertStatus(200);
        $configuredConfigs = $configuredConfigsResponse->json();

        // Assert ONLY tenant's configured Groq provider & models are returned
        $this->assertCount(1, $configuredConfigs);
        $this->assertEquals('groq', $configuredConfigs[0]['provider_name']);
        $this->assertEquals(['openai/gpt-oss-120b', 'qwen/qwen3.6-27b'], $configuredConfigs[0]['enabled_models']);

        // Execution uses tenant's private key
        $liveExec = $this->aiService->resolveAIExecution(
            $this->tenant->id,
            'ai_agents',
            'groq',
            'openai/gpt-oss-120b'
        );

        $this->assertEquals('groq', $liveExec['provider']);
        $this->assertEquals('openai/gpt-oss-120b', $liveExec['model']);
        $this->assertEquals($tenantGroqKey, $liveExec['api_key'], 'BYOK must strictly use the tenant private key');
        $this->assertFalse($liveExec['is_simulated']);
        $this->assertEquals('byok', $liveExec['mode']);
    }

    /**
     * TEST 2: Master Fixed Model Validation
     */
    public function test_master_fixed_mode_lifecycle_and_resolution(): void
    {
        $superadminGroqKey = 'gsk_master_admin_fixed_key_777';
        
        $this->aiService->setAiOperationalModel('master_fixed');
        $this->aiService->setAdminProvidersKeys([
            'groq' => $superadminGroqKey,
        ]);
        $this->aiService->setAiFeatureRouting([
            'ai_agents' => ['provider' => 'groq', 'model' => 'openai/gpt-oss-120b'],
            'flow_ai_prompt' => ['provider' => 'groq', 'model' => 'openai/gpt-oss-20b'],
        ]);

        // 1. Check what is shown to tenant in /agents/provider-configs
        $response = $this->actingAs($this->tenantUser)
            ->getJson('/api/agents/provider-configs');

        $response->assertStatus(200);
        $data = $response->json();

        // Must NOT leak 'groq' or 'openai/gpt-oss-120b' - must return platform abstraction
        $this->assertCount(1, $data);
        $this->assertEquals('platform', $data[0]['provider_name']);
        $this->assertEquals('master_fixed', $data[0]['mode']);
        $this->assertEquals(['WhatsOmni Enterprise Model'], $data[0]['enabled_models']);

        // 2. Check execution resolution
        $resolvedAgentExec = $this->aiService->resolveAIExecution($this->tenant->id, 'ai_agents');
        $this->assertEquals('groq', $resolvedAgentExec['provider']);
        $this->assertEquals('openai/gpt-oss-120b', $resolvedAgentExec['model']);
        $this->assertEquals($superadminGroqKey, $resolvedAgentExec['api_key'], 'Master Fixed must use Superadmin Master Key');
        $this->assertEquals('master_fixed', $resolvedAgentExec['mode']);

        $resolvedFlowExec = $this->aiService->resolveAIExecution($this->tenant->id, 'flow_ai_prompt');
        $this->assertEquals('groq', $resolvedFlowExec['provider']);
        $this->assertEquals('openai/gpt-oss-20b', $resolvedFlowExec['model']);
        $this->assertEquals($superadminGroqKey, $resolvedFlowExec['api_key']);

        // 3. Ensure tenant cannot edit keys in settings (403 Forbidden)
        $forbiddenSave = $this->actingAs($this->tenantUser)
            ->postJson('/api/settings/ai-providers', [
                'provider_name' => 'openai',
                'api_key' => 'sk-invalid',
            ]);
        $forbiddenSave->assertStatus(403);
    }
}
