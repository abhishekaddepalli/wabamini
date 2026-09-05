<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Tenant;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\PlatformSetting;
use App\Models\AIProviderConfig;
use App\Services\AIProviderService;

class AIOperationalModelsTest extends TestCase
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
            'company_name' => 'Acme Labs',
            'flow_credits_balance' => 10,
        ]);

        $this->tenantUser = User::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@acmelabs.com',
            'password' => bcrypt('Secret123!'),
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

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

        $this->aiService = app(AIProviderService::class);
    }

    public function test_superadmin_can_fetch_and_update_ai_operational_model(): void
    {
        // 1. Fetch AI config
        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/ai-providers');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'operational_model',
                'ai_providers_config',
                'ai_providers_keys',
                'ai_feature_routing',
                'features_list',
            ]);

        // 2. Update to Master Fixed
        $updateRes = $this->actingAs($this->admin, 'admin')
            ->putJson('/api/admin/ai-providers', [
                'operational_model' => 'master_fixed',
                'ai_providers_keys' => [
                    'openai' => 'sk-master-openai-key-12345678',
                    'gemini' => 'AIzaSyMasterGeminiKey-87654321',
                ],
                'ai_feature_routing' => [
                    'prompt_to_flow' => ['provider' => 'gemini', 'model' => 'gemini-1.5-flash'],
                    'ai_agents' => ['provider' => 'openai', 'model' => 'gpt-4o'],
                ]
            ]);

        $updateRes->assertStatus(200)
            ->assertJson([
                'operational_model' => 'master_fixed',
            ]);

        $this->assertEquals('master_fixed', $this->aiService->getAiOperationalModel());
        
        // Assert keys are masked in response
        $keys = $updateRes->json('ai_providers_keys');
        $this->assertTrue($keys['openai']['has_key']);
        $this->assertStringContainsString('••••', $keys['openai']['preview']);
        $this->assertNotEquals('sk-master-openai-key-12345678', $keys['openai']['preview']);
    }

    public function test_ai_execution_resolution_in_master_fixed_mode(): void
    {
        $this->aiService->setAiOperationalModel('master_fixed');
        $this->aiService->setAdminProvidersKeys([
            'openai' => 'sk-admin-fixed-openai-key',
            'gemini' => 'AIzaSy-admin-fixed-gemini-key',
        ]);
        $this->aiService->setAiFeatureRouting([
            'prompt_to_flow' => ['provider' => 'gemini', 'model' => 'gemini-1.5-pro'],
            'ai_agents' => ['provider' => 'openai', 'model' => 'gpt-4o'],
            'flow_ai_condition' => ['provider' => 'openai', 'model' => 'gpt-4o-mini'],
        ]);

        // Resolve prompt_to_flow
        $resolvedPrompt = $this->aiService->resolveAIExecution($this->tenant->id, 'prompt_to_flow');
        $this->assertEquals('gemini', $resolvedPrompt['provider']);
        $this->assertEquals('gemini-1.5-pro', $resolvedPrompt['model']);
        $this->assertEquals('AIzaSy-admin-fixed-gemini-key', $resolvedPrompt['api_key']);
        $this->assertEquals('master_fixed', $resolvedPrompt['mode']);

        // Resolve ai_agents
        $resolvedAgent = $this->aiService->resolveAIExecution($this->tenant->id, 'ai_agents');
        $this->assertEquals('openai', $resolvedAgent['provider']);
        $this->assertEquals('gpt-4o', $resolvedAgent['model']);
        $this->assertEquals('sk-admin-fixed-openai-key', $resolvedAgent['api_key']);
    }

    public function test_ai_execution_resolution_in_byok_mode(): void
    {
        $this->aiService->setAiOperationalModel('byok');

        // Create tenant BYOK config
        AIProviderConfig::create([
            'tenant_id' => $this->tenant->id,
            'provider_name' => 'openai',
            'api_key' => 'sk-tenant-private-key-777',
            'enabled_models' => ['gpt-4o-mini'],
            'default_model' => 'gpt-4o-mini',
            'is_active' => true,
        ]);

        $resolved = $this->aiService->resolveAIExecution($this->tenant->id, 'flow_ai_condition');
        $this->assertEquals('openai', $resolved['provider']);
        $this->assertEquals('gpt-4o-mini', $resolved['model']);
        $this->assertEquals('sk-tenant-private-key-777', $resolved['api_key']);
        $this->assertEquals('byok', $resolved['mode']);
    }

    public function test_tenant_cannot_modify_keys_when_in_master_modes(): void
    {
        $this->aiService->setAiOperationalModel('master_fixed');

        // Tenant attempts to save key
        $response = $this->actingAs($this->tenantUser)
            ->postJson('/api/settings/ai-providers', [
                'provider_name' => 'openai',
                'api_key' => 'sk-tenant-should-fail',
                'enabled_models' => ['gpt-4o'],
            ]);

        $response->assertStatus(403);

        // Tenant attempts to validate key
        $valResponse = $this->actingAs($this->tenantUser)
            ->postJson('/api/settings/ai-providers/validate', [
                'provider' => 'openai',
                'api_key' => 'sk-tenant-should-fail',
            ]);

        $valResponse->assertStatus(403);
    }

    public function test_ai_agent_provider_configs_endpoint_adapts_to_operational_model(): void
    {
        // 1. In Master Fixed: returns ONLY the single centrally routed provider for ai_agents
        $this->aiService->setAiOperationalModel('master_fixed');
        $this->aiService->setAdminProvidersKeys([
            'groq' => 'gsk_admin_test_key_groq',
        ]);
        $this->aiService->setAiFeatureRouting([
            'ai_agents' => ['provider' => 'groq', 'model' => 'openai/gpt-oss-120b'],
        ]);

        $fixedRes = $this->actingAs($this->tenantUser)
            ->getJson('/api/agents/provider-configs');

        $fixedRes->assertStatus(200);
        $fixedData = $fixedRes->json();
        $this->assertCount(1, $fixedData);
        $this->assertEquals('platform', $fixedData[0]['provider_name']);
        $this->assertEquals(['WhatsOmni Enterprise Model'], $fixedData[0]['enabled_models']);
        $this->assertTrue($fixedData[0]['is_centrally_managed']);
        $this->assertEquals('master_fixed', $fixedData[0]['mode']);

        // 2. In BYOK with no keys: returns empty array []
        $this->aiService->setAiOperationalModel('byok');
        $byokEmptyRes = $this->actingAs($this->tenantUser)
            ->getJson('/api/agents/provider-configs');
        $byokEmptyRes->assertStatus(200);
        $this->assertEmpty($byokEmptyRes->json());

        // 3. In BYOK with tenant Groq key: returns tenant's Groq config
        AIProviderConfig::create([
            'tenant_id' => $this->tenant->id,
            'provider_name' => 'groq',
            'api_key' => 'gsk_tenant_private_groq_key',
            'enabled_models' => ['openai/gpt-oss-120b'],
            'default_model' => 'openai/gpt-oss-120b',
            'is_active' => true,
        ]);

        $byokConfiguredRes = $this->actingAs($this->tenantUser)
            ->getJson('/api/agents/provider-configs');
        $byokConfiguredRes->assertStatus(200);
        $byokData = $byokConfiguredRes->json();
        $this->assertCount(1, $byokData);
        $this->assertEquals('groq', $byokData[0]['provider_name']);
    }

    public function test_tenant_can_create_ai_agent_in_all_operational_modes(): void
    {
        // 1. Create agent in Master Fixed mode
        $this->aiService->setAiOperationalModel('master_fixed');
        $this->aiService->setAdminProvidersKeys(['groq' => 'gsk_master_key']);
        $this->aiService->setAiFeatureRouting([
            'ai_agents' => ['provider' => 'groq', 'model' => 'openai/gpt-oss-120b'],
        ]);

        $createFixedRes = $this->actingAs($this->tenantUser)
            ->postJson('/api/agents', [
                'name' => 'Support Bot Master Fixed',
                'type' => 'inbound',
                'provider' => 'groq',
                'model' => 'openai/gpt-oss-120b',
                'system_prompt' => 'You are a helpful assistant.',
            ]);

        $createFixedRes->assertStatus(201);
        $this->assertDatabaseHas('ai_agents', [
            'name' => 'Support Bot Master Fixed',
            'tenant_id' => $this->tenant->id,
            'model' => 'openai/gpt-oss-120b',
        ]);

        // 2. Create agent in BYOK mode
        $this->aiService->setAiOperationalModel('byok');
        $tenantConfig = AIProviderConfig::create([
            'tenant_id' => $this->tenant->id,
            'provider_name' => 'groq',
            'api_key' => 'gsk_tenant_key',
            'enabled_models' => ['qwen/qwen3.6-27b'],
            'default_model' => 'qwen/qwen3.6-27b',
            'is_active' => true,
        ]);

        $createByokRes = $this->actingAs($this->tenantUser)
            ->postJson('/api/agents', [
                'name' => 'BYOK Bot',
                'type' => 'inbound',
                'ai_provider_config_id' => $tenantConfig->id,
                'model' => 'qwen/qwen3.6-27b',
                'system_prompt' => 'You are a BYOK bot.',
            ]);

        $createByokRes->assertStatus(201);
        $this->assertDatabaseHas('ai_agents', [
            'name' => 'BYOK Bot',
            'ai_provider_config_id' => $tenantConfig->id,
            'model' => 'qwen/qwen3.6-27b',
        ]);
    }
}
