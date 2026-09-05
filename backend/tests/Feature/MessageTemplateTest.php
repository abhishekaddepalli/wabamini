<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\ChannelConnection;
use App\Models\MessageTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MessageTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Tenant $tenant;
    protected ChannelConnection $whatsappChannel;
    protected ChannelConnection $emailChannel;

    protected function setUp(): void
    {
        parent::setUp();

        \App\Models\Plan::create([
            'id' => 1,
            'name' => 'Free Plan',
            'description' => 'Free description',
            'is_active' => true,
            'trial_days' => 14,
            'max_team_members' => 5,
            'max_campaigns' => 5,
            'max_integrations' => 5,
            'own_crm_access' => true,
            'max_channels' => 5,
            'max_automations' => 5,
            'sort_order' => 1
        ]);

        $this->tenant = Tenant::create([
            'company_name' => 'Templates Inc',
            'status' => 'trial',
            'onboarding_step' => 'complete',
            'plan_id' => 1
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'email' => 'grace@hopper.io',
            'password' => Hash::make('Hopper123!'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->whatsappChannel = ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'whatsapp',
            'name' => 'Meta API WhatsApp Number',
            'status' => 'connected',
            'credentials' => [
                'phone_number_id' => '1234567890',
                'system_user_access_token' => 'mock_token',
                'whatsapp_business_account_id' => '0987654321',
            ],
        ]);

        $this->emailChannel = ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'email',
            'name' => 'Corporate SMTP Mailer',
            'status' => 'connected',
            'credentials' => [
                'provider' => 'sandbox',
                'email_address' => 'noreply@whatsomni.io',
            ],
        ]);
    }

    /**
     * Test basic template CRUD operations.
     */
    public function test_can_manage_message_templates()
    {
        $this->actingAs($this->user);

        // 1. Create a WhatsApp template
        $response = $this->postJson('/api/templates', [
            'name' => 'welcome_back_template',
            'type' => 'whatsapp',
            'category' => 'utility',
            'language' => 'en',
            'channel_connection_id' => $this->whatsappChannel->id,
            'content' => [
                'body' => [
                    'text' => 'Hello {{1}}, welcome back to WhatsOmni!'
                ]
            ]
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('name', 'welcome_back_template');
        $response->assertJsonPath('status', 'draft');

        $templateId = $response->json('id');

        // 2. Create an Email template
        $responseEmail = $this->postJson('/api/templates', [
            'name' => 'Monthly Newsletter',
            'type' => 'email',
            'category' => 'marketing',
            'channel_connection_id' => $this->emailChannel->id,
            'content' => [
                'blocks' => [
                    [
                        'type' => 'header',
                        'content' => 'Monthly Highlights'
                    ],
                    [
                        'type' => 'paragraph',
                        'content' => 'Hello {{ contact.first_name }}! Here is our monthly update.'
                    ]
                ]
            ]
        ]);

        $responseEmail->assertStatus(201);
        $responseEmail->assertJsonPath('status', 'ready');

        // 3. List templates
        $listRes = $this->getJson('/api/templates');
        $listRes->assertStatus(200);
        $this->assertCount(2, $listRes->json());

        // 4. Update WhatsApp template
        $updateRes = $this->putJson("/api/templates/{$templateId}", [
            'category' => 'marketing',
            'language' => 'en_US'
        ]);
        $updateRes->assertStatus(200);
        $updateRes->assertJsonPath('category', 'marketing');
        $updateRes->assertJsonPath('language', 'en_US');

        // 5. Delete template
        $deleteRes = $this->deleteJson("/api/templates/{$templateId}");
        $deleteRes->assertStatus(200);

        $this->assertDatabaseMissing('message_templates', ['id' => $templateId]);
    }

    /**
     * Test submitting a WhatsApp template to Meta's API.
     */
    public function test_can_submit_whatsapp_template_to_meta()
    {
        $this->actingAs($this->user);

        $template = MessageTemplate::create([
            'tenant_id' => $this->tenant->id,
            'channel_connection_id' => $this->whatsappChannel->id,
            'name' => 'meta_promo_template',
            'type' => 'whatsapp',
            'category' => 'marketing',
            'language' => 'en_US',
            'status' => 'draft',
            'content' => [
                'header' => [
                    'text' => 'Exclusive Discount!'
                ],
                'body' => [
                    'text' => 'Hi {{1}}, get 20% off using code PROMO20.'
                ]
            ]
        ]);

        Http::fake([
            'https://graph.facebook.com/v19.0/0987654321/message_templates' => Http::response([
                'id' => 'meta_temp_id_9999',
                'status' => 'PENDING',
                'category' => 'MARKETING'
            ], 200)
        ]);

        $response = $this->postJson("/api/templates/{$template->id}/submit-whatsapp");

        $response->assertStatus(200);
        $response->assertJsonPath('template.status', 'pending');
        $response->assertJsonPath('template.meta_template_id', 'meta_temp_id_9999');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://graph.facebook.com/v19.0/0987654321/message_templates' &&
                   $request->method() === 'POST' &&
                   $request['name'] === 'meta_promo_template' &&
                   $request['components'][0]['type'] === 'HEADER' &&
                   $request['components'][1]['type'] === 'BODY';
        });
    }

    /**
     * Test test-sending an Email template.
     */
    public function test_can_send_test_email_template()
    {
        $this->actingAs($this->user);

        $template = MessageTemplate::create([
            'tenant_id' => $this->tenant->id,
            'channel_connection_id' => $this->emailChannel->id,
            'name' => 'verification_template',
            'type' => 'email',
            'status' => 'ready',
            'content' => [
                'blocks' => [
                    [
                        'type' => 'paragraph',
                        'content' => 'Please confirm your address, {{ contact.email }}.'
                    ]
                ]
            ]
        ]);

        $response = $this->postJson("/api/templates/{$template->id}/test-send-email", [
            'email' => 'recipient@example.com'
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Test email dispatched successfully.');
    }
}
