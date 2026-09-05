<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\AiUsageLog;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAiAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_retrieve_ai_analytics(): void
    {
        $role = AdminRole::create([
            'name' => 'Super Admin',
            'permissions' => ['*'],
        ]);

        $admin = Admin::create([
            'role_id' => $role->id,
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'email' => 'ai_superadmin@whatsomni.com',
            'password' => bcrypt('password123'),
            'status' => 'active',
        ]);

        $tenant = Tenant::create([
            'company_name' => 'Test AI Tenant',
            'status' => 'active',
        ]);

        // Seed sample AI usage logs
        AiUsageLog::create([
            'tenant_id' => $tenant->id,
            'feature' => 'chatbot',
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'prompt_tokens' => 1500,
            'completion_tokens' => 500,
            'total_tokens' => 2000,
            'estimated_cost' => 0.00875,
            'latency_ms' => 450,
            'status' => 'success',
        ]);

        AiUsageLog::create([
            'tenant_id' => $tenant->id,
            'feature' => 'agent',
            'provider' => 'anthropic',
            'model' => 'claude-3-5-sonnet',
            'prompt_tokens' => 3000,
            'completion_tokens' => 1000,
            'total_tokens' => 4000,
            'estimated_cost' => 0.024,
            'latency_ms' => 620,
            'status' => 'success',
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->getJson('/api/admin/analytics/ai');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'summary' => [
                    'total_tokens',
                    'prompt_tokens',
                    'completion_tokens',
                    'total_requests',
                    'total_cost',
                    'avg_latency_ms',
                    'active_ai_tenants',
                    'success_rate',
                ],
                'trend',
                'feature_distribution',
                'model_distribution',
                'provider_distribution',
                'top_workspaces',
                'recent_logs',
            ]);

        $this->assertEquals(6000, $response->json('summary.total_tokens'));
        $this->assertEquals(2, $response->json('summary.total_requests'));
        $this->assertEquals(1, $response->json('summary.active_ai_tenants'));
    }
}
