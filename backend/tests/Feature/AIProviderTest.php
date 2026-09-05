<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\AIProviderConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AIProviderTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Create tenant
        $this->tenant = Tenant::create([
            'company_name' => 'Acme Labs',
            'status' => 'active',
        ]);

        // Create tenant user
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Albert',
            'last_name' => 'Einstein',
            'email' => 'albert@acmelabs.com',
            'password' => Hash::make('Secret123!'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Test listing AI providers.
     */
    public function test_user_can_list_ai_providers(): void
    {
        $this->actingAs($this->user);

        // Pre-configure one provider
        AIProviderConfig::create([
            'tenant_id' => $this->tenant->id,
            'provider_name' => 'openai',
            'api_key' => 'sk-mock-123-api-key',
            'enabled_models' => ['gpt-4o-mini'],
            'default_model' => 'gpt-4o-mini',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/settings/ai-providers');

        $response->assertStatus(200)
            ->assertJsonStructure(['providers' => ['openai', 'anthropic', 'gemini']])
            ->assertJsonPath('providers.openai.is_configured', true)
            ->assertJsonPath('providers.anthropic.is_configured', false);
    }

    /**
     * Test validation of credentials key.
     */
    public function test_user_can_validate_api_key(): void
    {
        $this->actingAs($this->user);

        // Mock OpenAI API response
        Http::fake([
            'https://api.openai.com/v1/models' => Http::response(['data' => []], 200),
        ]);

        $response = $this->postJson('/api/settings/ai-providers/validate', [
            'provider' => 'openai',
            'api_key' => 'sk-valid-key-example',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * Test storing provider configuration.
     */
    public function test_user_can_store_and_encrypt_provider_config(): void
    {
        $this->actingAs($this->user);

        Http::fake([
            'https://api.openai.com/v1/models' => Http::response(['data' => []], 200),
        ]);

        $response = $this->postJson('/api/settings/ai-providers', [
            'provider_name' => 'openai',
            'api_key' => 'sk-secret-credentials-key',
            'enabled_models' => ['gpt-4o', 'gpt-4o-mini'],
            'default_model' => 'gpt-4o-mini',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('config.provider_name', 'openai')
            ->assertJsonPath('config.is_configured', true);

        // Verify encrypted in database
        $this->assertDatabaseHas('ai_provider_configs', [
            'tenant_id' => $this->tenant->id,
            'provider_name' => 'openai',
        ]);

        $config = AIProviderConfig::where('tenant_id', $this->tenant->id)->first();
        
        // Assert decrypted value matches
        $this->assertEquals('sk-secret-credentials-key', $config->api_key);
        
        // Assert value is encrypted directly in database row
        $rawRow = \DB::table('ai_provider_configs')->where('id', $config->id)->first();
        $this->assertNotEquals('sk-secret-credentials-key', $rawRow->api_key);
    }

    /**
     * Test deleting configuration clears key database entry.
     */
    public function test_user_can_delete_provider_config(): void
    {
        $this->actingAs($this->user);

        $config = AIProviderConfig::create([
            'tenant_id' => $this->tenant->id,
            'provider_name' => 'openai',
            'api_key' => 'sk-key-to-delete',
            'enabled_models' => ['gpt-4o-mini'],
            'default_model' => 'gpt-4o-mini',
            'is_active' => true,
        ]);

        $response = $this->deleteJson('/api/settings/ai-providers/openai');

        $response->assertStatus(200);
        $this->assertDatabaseMissing('ai_provider_configs', [
            'id' => $config->id,
        ]);
    }

    /**
     * Test retrieving AI & Token usage for tenant in BYOK mode.
     */
    public function test_tenant_can_retrieve_ai_usage(): void
    {
        $this->actingAs($this->user);

        \App\Models\AiUsageLog::create([
            'tenant_id' => $this->tenant->id,
            'feature' => 'chatbot',
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'prompt_tokens' => 1200,
            'completion_tokens' => 400,
            'total_tokens' => 1600,
            'estimated_cost' => 0.007,
            'latency_ms' => 380,
            'status' => 'success',
        ]);

        $response = $this->getJson('/api/settings/ai-providers/usage');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'summary' => [
                    'total_tokens',
                    'prompt_tokens',
                    'completion_tokens',
                    'total_requests',
                    'total_cost',
                    'avg_latency_ms',
                    'success_rate',
                    'distinct_models',
                ],
                'trend',
                'feature_distribution',
                'model_distribution',
                'recent_logs',
            ]);

        $this->assertEquals(1600, $response->json('summary.total_tokens'));
        $this->assertEquals(1, $response->json('summary.total_requests'));
    }
}
