<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\PlatformSetting;

class AdminChannelsTest extends TestCase
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
            'email' => 'admin_channels_test@whatsomni.com',
            'password' => bcrypt('password123'),
            'status' => 'active',
        ]);
    }

    /**
     * Test admin can fetch default channels configurations.
     */
    public function test_admin_can_fetch_channels_config(): void
    {
        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/channels');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'channels_enabled',
                'sms_providers_config' => [
                    'active_provider',
                    'providers'
                ]
            ]);
    }

    /**
     * Test admin can update channels and sms configuration settings.
     */
    public function test_admin_can_update_channels_config(): void
    {
        $payload = [
            'channels_enabled' => [
                'whatsapp_cloud' => true,
                'whatsapp_baileys' => true,
                'sms' => false,
                'email' => true,
                'instagram' => false,
                'messenger' => false,
                'telegram' => false,
            ],
            'sms_providers_config' => [
                'active_provider' => 'twilio',
                'providers' => [
                    'twilio' => [
                        'twilio_account_sid' => 'ACtest_sid',
                        'twilio_auth_token' => 'test_token',
                        'twilio_phone_number' => '+12345',
                    ],
                    'vonage' => [
                        'vonage_api_key' => '',
                        'vonage_api_secret' => '',
                        'vonage_phone_number' => '',
                    ],
                    'messagebird' => [
                        'messagebird_access_key' => '',
                        'messagebird_originator' => '',
                    ],
                    'plivo' => [
                        'plivo_auth_id' => '',
                        'plivo_auth_token' => '',
                        'plivo_phone_number' => '',
                    ],
                    'sinch' => [
                        'sinch_service_plan_id' => '',
                        'sinch_api_token' => '',
                        'sinch_phone_number' => '',
                    ],
                    'telnyx' => [
                        'telnyx_api_key' => '',
                        'telnyx_phone_number' => '',
                    ]
                ]
            ]
        ];

        $response = $this->actingAs($this->admin, 'admin')
            ->putJson('/api/admin/channels', $payload);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'message' => 'Channels settings saved successfully.'
            ]);

        $this->assertDatabaseHas('platform_settings', [
            'key' => 'channels_enabled'
        ]);

        $this->assertDatabaseHas('platform_settings', [
            'key' => 'sms_providers_config'
        ]);
    }

    /**
     * Test admin can test Twilio SMS gateway connection with mock SID.
     */
    public function test_admin_can_test_twilio_sms_gateway(): void
    {
        $payload = [
            'provider' => 'twilio',
            'credentials' => [
                'twilio_account_sid' => 'mock_sid',
                'twilio_auth_token' => 'mock_token',
                'twilio_phone_number' => '+12345',
            ],
            'recipient' => '+19998887777',
            'message' => 'This is a test message from WhatsOmni setup.',
        ];

        $response = $this->actingAs($this->admin, 'admin')
            ->postJson('/api/admin/channels/test-sms', $payload);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'success' => true
            ]);
    }
}
