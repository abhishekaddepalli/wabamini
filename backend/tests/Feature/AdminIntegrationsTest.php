<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\PlatformSetting;

class AdminIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;
    protected AdminRole $role;

    protected function setUp(): void
    {
        parent::setUp();

        $this->role = AdminRole::create([
            'name' => 'Super Admin',
            'permissions' => ['*'],
        ]);

        $this->admin = Admin::create([
            'role_id' => $this->role->id,
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'email' => 'admin_integrations_test@whatsomni.com',
            'password' => bcrypt('password123'),
            'status' => 'active',
        ]);
    }

    /**
     * Test admin can fetch integrations configuration.
     */
    public function test_admin_can_fetch_integrations_config(): void
    {
        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/integrations');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'integrations_enabled_config' => [
                    'google_sheets',
                    'google_calendar',
                    'zoom',
                    'teams',
                    'hubspot',
                    'salesforce',
                    'zoho',
                    'shopify',
                    'woocommerce'
                ],
                'env_status' => [
                    'google_sheets',
                    'google_calendar',
                    'zoom',
                    'teams',
                    'hubspot',
                    'salesforce',
                    'zoho',
                    'shopify',
                    'woocommerce'
                ]
            ]);
    }

    /**
     * Test admin can update integrations configuration.
     */
    public function test_admin_can_update_integrations_config(): void
    {
        $payload = [
            'integrations_enabled_config' => [
                'google_sheets' => true,
                'google_calendar' => false,
                'zoom' => true,
                'teams' => false,
                'hubspot' => true,
                'salesforce' => false,
                'zoho' => true,
                'shopify' => false,
                'woocommerce' => true
            ]
        ];

        $response = $this->actingAs($this->admin, 'admin')
            ->putJson('/api/admin/integrations', $payload);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'message' => 'Integrations configuration saved successfully.'
            ]);

        $this->assertDatabaseHas('platform_settings', [
            'key' => 'integrations_enabled_config'
        ]);
    }
}
