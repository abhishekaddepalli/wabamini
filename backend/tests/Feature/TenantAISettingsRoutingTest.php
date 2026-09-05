<?php

namespace Tests\Feature;

use App\Models\AIProviderConfig;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AIProviderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantAISettingsRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;
    protected AIProviderService $aiService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'company_name' => 'Acme Labs',
        ]);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@acmelabs.com',
            'password' => bcrypt('Secret123!'),
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $this->aiService = app(AIProviderService::class);
        $this->aiService->setAiOperationalModel('byok');
    }

    public function test_tenant_can_fetch_ai_settings_matrix(): void
    {
        AIProviderConfig::create([
            'tenant_id' => $this->tenant->id,
            'provider_name' => 'openai',
            'api_key' => 'sk-mock-openai-key',
            'is_active' => true,
            'enabled_models' => ['gpt-4o-mini', 'gpt-4o'],
            'default_model' => 'gpt-4o-mini',
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/settings/ai-providers/settings');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'operational_model',
                'features',
                'routing',
                'available_providers' => [
                    '*' => ['slug', 'name', 'enabled_models', 'default_model'],
                ],
            ]);

        $this->assertCount(1, $response->json('available_providers'));
        $this->assertEquals('openai', $response->json('available_providers.0.slug'));
    }

    public function test_tenant_can_update_ai_settings_feature_routing(): void
    {
        AIProviderConfig::create([
            'tenant_id' => $this->tenant->id,
            'provider_name' => 'openai',
            'api_key' => 'sk-mock-openai-key',
            'is_active' => true,
            'enabled_models' => ['gpt-4o-mini', 'gpt-4o'],
            'default_model' => 'gpt-4o-mini',
        ]);

        AIProviderConfig::create([
            'tenant_id' => $this->tenant->id,
            'provider_name' => 'groq',
            'api_key' => 'gsk-mock-groq-key',
            'is_active' => true,
            'enabled_models' => ['openai/gpt-oss-120b'],
            'default_model' => 'openai/gpt-oss-120b',
        ]);

        $payload = [
            'routing' => [
                'ai_chatbot' => ['provider' => 'openai', 'model' => 'gpt-4o'],
                'flow_ai_condition' => ['provider' => 'groq', 'model' => 'openai/gpt-oss-120b'],
                'knowledge_base_rag' => ['provider' => 'openai', 'model' => 'gpt-4o-mini'],
            ]
        ];

        $response = $this->actingAs($this->user)->postJson('/api/settings/ai-providers/settings', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        // Verify resolveAIExecution uses the customized tenant routing
        $resolvedChatbot = $this->aiService->resolveAIExecution($this->tenant->id, 'ai_chatbot');
        $this->assertEquals('openai', $resolvedChatbot['provider']);
        $this->assertEquals('gpt-4o', $resolvedChatbot['model']);
        $this->assertEquals('sk-mock-openai-key', $resolvedChatbot['api_key']);

        $resolvedCondition = $this->aiService->resolveAIExecution($this->tenant->id, 'flow_ai_condition');
        $this->assertEquals('groq', $resolvedCondition['provider']);
        $this->assertEquals('openai/gpt-oss-120b', $resolvedCondition['model']);
        $this->assertEquals('gsk-mock-groq-key', $resolvedCondition['api_key']);
    }
}
