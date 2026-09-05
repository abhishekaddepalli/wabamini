<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Contact;
use App\Models\CrmIntegration;
use App\Models\CrmSyncLog;
use App\Jobs\SyncContactToCrmJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CrmIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['company_name' => 'ACME Corporation']);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'password' => bcrypt('secret123')
        ]);

        // Define env client keys so driver validation passes
        putenv('HUBSPOT_CLIENT_ID=hs_client_123');
        putenv('HUBSPOT_CLIENT_SECRET=hs_secret_123');
        putenv('SALESFORCE_CLIENT_ID=sf_client_123');
        putenv('SALESFORCE_CLIENT_SECRET=sf_secret_123');
        putenv('ZOHO_CLIENT_ID=zoho_client_123');
        putenv('ZOHO_CLIENT_SECRET=zoho_secret_123');
    }

    protected function tearDown(): void
    {
        putenv('HUBSPOT_CLIENT_ID');
        putenv('HUBSPOT_CLIENT_SECRET');
        putenv('SALESFORCE_CLIENT_ID');
        putenv('SALESFORCE_CLIENT_SECRET');
        putenv('ZOHO_CLIENT_ID');
        putenv('ZOHO_CLIENT_SECRET');

        parent::tearDown();
    }

    /**
     * Test getting CRM Status when disconnected.
     */
    public function test_can_get_crm_status_disconnected(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/integrations/crm/status');

        $response->assertStatus(200)
            ->assertJson([
                'hubspot' => [
                    'connected' => false,
                    'email' => null
                ],
                'salesforce' => [
                    'connected' => false,
                    'email' => null
                ],
                'zoho' => [
                    'connected' => false,
                    'email' => null
                ]
            ]);
    }

    /**
     * Test CRM OAuth URL Redirect generation.
     */
    public function test_can_get_oauth_url_for_all_providers(): void
    {
        $providers = ['hubspot', 'salesforce', 'zoho'];

        foreach ($providers as $provider) {
            $response = $this->actingAs($this->user)
                ->getJson("/api/integrations/crm/connect/{$provider}");

            $response->assertStatus(200);
            $this->assertStringContainsString('state=' . $this->tenant->id, $response->json('url'));
        }
    }

    /**
     * Test CRM OAuth Callback handler.
     */
    public function test_can_process_oauth_callback_for_all_providers(): void
    {
        Http::fake([
            'api.hubapi.com/*' => Http::response(['access_token' => 'hs_tok', 'expires_in' => 3600]),
            'login.salesforce.com/*' => Http::response(['access_token' => 'sf_tok', 'instance_url' => 'https://acme.salesforce.com']),
            'accounts.zoho.com/*' => Http::response(['access_token' => 'zoho_tok', 'api_domain' => 'https://zoho.com', 'expires_in' => 3600])
        ]);

        $providers = ['hubspot', 'salesforce', 'zoho'];

        foreach ($providers as $provider) {
            $response = $this->get("/api/integrations/crm/callback/{$provider}?state=" . $this->tenant->id . "&code=mock_code");

            $response->assertRedirect();
            $this->assertDatabaseHas('crm_integrations', [
                'tenant_id' => $this->tenant->id,
                'provider' => $provider
            ]);
        }
    }

    /**
     * Test updating Field Mappings.
     */
    public function test_can_update_field_mappings(): void
    {
        $integration = CrmIntegration::create([
            'tenant_id' => $this->tenant->id,
            'provider' => 'hubspot',
            'email' => 'sandbox@hubspot.com',
            'field_mapping' => [],
            'sync_direction' => 'bidirectional',
            'access_token' => 'mock_access',
            'refresh_token' => 'mock_refresh',
            'expires_at' => now()->addHour()
        ]);

        $customMapping = [
            'first_name' => 'custom_firstname',
            'last_name' => 'custom_lastname',
            'email' => 'custom_email',
            'phone' => 'custom_phone',
            'lifecycle_stage' => 'custom_lifecycle'
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/integrations/crm/mapping/hubspot', [
                'field_mapping' => $customMapping,
                'sync_direction' => 'push'
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'sync_direction' => 'push',
                'field_mapping' => $customMapping
            ]);

        $this->assertDatabaseHas('crm_integrations', [
            'tenant_id' => $this->tenant->id,
            'provider' => 'hubspot',
            'sync_direction' => 'push'
        ]);
    }

    /**
     * Test manual Pull and Push Sync for Salesforce.
     */
    public function test_can_force_manual_sync_salesforce(): void
    {
        $integration = CrmIntegration::create([
            'tenant_id' => $this->tenant->id,
            'provider' => 'salesforce',
            'email' => 'sandbox@salesforce.com',
            'field_mapping' => [
                'first_name' => 'FirstName',
                'last_name' => 'LastName',
                'email' => 'Email',
                'phone' => 'Phone',
                'lifecycle_stage' => 'LeadSource'
            ],
            'sync_direction' => 'bidirectional',
            'access_token' => 'mock_access',
            'refresh_token' => 'mock_refresh',
            'expires_at' => now()->addHour(),
            'metadata' => [
                'instance_url' => 'https://sandbox.my.salesforce.com'
            ]
        ]);

        // Create a contact to test Push
        Contact::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Steve',
            'last_name' => 'Jobs',
            'email' => 'steve@apple.com',
            'phone' => '+18880199',
            'lifecycle_stage' => 'lead'
        ]);

        Http::fake([
            '*/services/data/v57.0/query*' => Http::response([
                'records' => [
                    [
                        'Id' => 'sf_987654',
                        'FirstName' => 'Steve',
                        'LastName' => 'Salesforce',
                        'Email' => 'steve.salesforce@example.com',
                        'Phone' => '+15551199',
                        'LeadSource' => 'partner'
                    ],
                    [
                        'Id' => 'sf_876543',
                        'FirstName' => 'Sarah',
                        'LastName' => 'Force',
                        'Email' => 'sarah.force@example.com',
                        'Phone' => '+15551299',
                        'LeadSource' => 'web'
                    ]
                ]
            ]),
            '*/services/data/v57.0/sobjects/Contact*' => Http::response(['id' => 'sf_new_id', 'success' => true])
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/integrations/crm/sync/salesforce', [
                'duplicate_strategy' => 'merge'
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'pulled' => [
                    'imported' => 2,
                    'merged' => 0
                ],
                'pushed' => 3
            ]);

        $this->assertDatabaseHas('contacts', [
            'tenant_id' => $this->tenant->id,
            'email' => 'steve.salesforce@example.com'
        ]);
    }

    /**
     * Test Contact model events dispatch queue SyncContactToCrmJob.
     */
    public function test_contact_events_dispatch_background_sync_job(): void
    {
        Queue::fake();

        CrmIntegration::create([
            'tenant_id' => $this->tenant->id,
            'provider' => 'hubspot',
            'email' => 'sandbox@hubspot.com',
            'sync_direction' => 'bidirectional',
            'access_token' => 'mock_access',
            'refresh_token' => 'mock_refresh',
            'expires_at' => now()->addHour()
        ]);

        Contact::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Elon',
            'last_name' => 'Musk',
            'email' => 'elon@spacex.com',
            'phone' => '+19998888',
            'lifecycle_stage' => 'lead'
        ]);

        Queue::assertPushed(SyncContactToCrmJob::class);
    }

    /**
     * Test retrying failed logs.
     */
    public function test_can_retry_failed_sync_log(): void
    {
        $integration = CrmIntegration::create([
            'tenant_id' => $this->tenant->id,
            'provider' => 'zoho',
            'email' => 'sandbox@zoho.com',
            'sync_direction' => 'bidirectional',
            'access_token' => 'mock_access',
            'refresh_token' => 'mock_refresh',
            'expires_at' => now()->addHour(),
            'metadata' => [
                'api_domain' => 'https://www.zohoapis.com'
            ]
        ]);

        $contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Retry',
            'last_name' => 'Test',
            'email' => 'retry@zoho.com',
            'phone' => '+10000000',
            'lifecycle_stage' => 'lead'
        ]);

        $log = CrmSyncLog::create([
            'tenant_id' => $this->tenant->id,
            'crm_integration_id' => $integration->id,
            'contact_id' => $contact->id,
            'action' => 'push',
            'status' => 'failed',
            'error_message' => 'MANDATORY_NOT_FOUND'
        ]);

        Http::fake([
            '*/crm/v3/Contacts*' => Http::response([
                'data' => [
                    [
                        'code' => 'SUCCESS',
                        'details' => ['id' => 'zh_new_id_123']
                    ]
                ]
            ])
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/integrations/crm/retry-log/{$log->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true
            ]);

        $this->assertDatabaseHas('crm_sync_logs', [
            'tenant_id' => $this->tenant->id,
            'contact_id' => $contact->id,
            'action' => 'push',
            'status' => 'success'
        ]);
    }
}
