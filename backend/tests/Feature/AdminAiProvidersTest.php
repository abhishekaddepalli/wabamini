<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\PlatformSetting;

class AdminAiProvidersTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;
    protected AdminRole $role;

    protected function setUp(): void
    {
        parent::setUp();

        // Create admin role
        $this->role = AdminRole::create([
            'name' => 'Super Admin',
            'permissions' => ['*'],
        ]);

        // Create an admin user
        $this->admin = Admin::create([
            'role_id' => $this->role->id,
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'email' => 'admin_ai_test@whatsomni.com',
            'password' => bcrypt('password123'),
            'status' => 'active',
        ]);
    }

    /**
     * Test admin can fetch default AI configuration settings.
     */
    public function test_admin_can_fetch_ai_providers_config(): void
    {
        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/ai-providers');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'ai_providers_config' => [
                    'openai' => [
                        'enabled',
                        'models'
                    ]
                ]
            ]);
    }

    /**
     * Test admin can update AI configuration settings.
     */
    public function test_admin_can_update_ai_providers_config(): void
    {
        $payload = [
            'ai_providers_config' => [
                'openai' => [
                    'enabled' => true,
                    'models' => [
                        ['id' => 'gpt-4o', 'name' => 'GPT-4o', 'enabled' => true, 'custom' => false],
                        ['id' => 'gpt-4o-mini', 'name' => 'GPT-4o Mini', 'enabled' => false, 'custom' => false],
                        ['id' => 'gpt-custom-model', 'name' => 'Custom GPT Model', 'enabled' => true, 'custom' => true]
                    ]
                ]
            ]
        ];

        $response = $this->actingAs($this->admin, 'admin')
            ->putJson('/api/admin/ai-providers', $payload);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'message' => 'AI providers configuration saved successfully.'
            ]);

        $this->assertDatabaseHas('platform_settings', [
            'key' => 'ai_providers_config'
        ]);
    }
}
