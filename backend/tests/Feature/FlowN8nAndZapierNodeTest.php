<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Contact;
use App\Models\Flow;
use App\Models\FlowVersion;
use App\Models\FlowExecution;
use App\Services\Flow\FlowRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FlowN8nAndZapierNodeTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Tenant $tenant;
    protected Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        \App\Models\Plan::create([
            'id' => 1,
            'name' => 'Enterprise Plan',
            'description' => 'Full enterprise automation',
            'is_active' => true,
            'trial_days' => 30,
            'max_team_members' => 20,
            'max_campaigns' => 50,
            'max_integrations' => 50,
            'own_crm_access' => true,
            'max_channels' => 20,
            'max_automations' => 50,
            'sort_order' => 1
        ]);

        $this->tenant = Tenant::create([
            'company_name' => 'Automations Lab',
            'status' => 'active',
            'onboarding_step' => 'complete',
            'plan_id' => 1
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@whatsomni.test',
            'password' => Hash::make('Secret123!'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Nikola',
            'last_name' => 'Tesla',
            'email' => 'nikola@tesla.tech',
            'phone' => '+15550199',
            'tags' => ['new_lead'],
        ]);
    }

    /**
     * Test n8n node executes live HTTP request with variable interpolation and response mapping.
     */
    public function test_n8n_node_executes_live_http_request_with_interpolated_variables_and_maps_response(): void
    {
        Http::fake([
            'https://n8n.workflow.io/webhook/lead-enrich' => Http::response([
                'status' => 'success',
                'data' => [
                    'lead_score' => 95,
                    'verified_company' => 'Tesla Electric',
                ]
            ], 200)
        ]);

        $flow = Flow::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'n8n Workflow Test',
            'trigger_type' => 'inbound_message',
            'channel_type' => 'omnichannel',
            'is_active' => true,
        ]);

        $definition = [
            'nodes' => [
                [
                    'id' => 'trigger_1',
                    'type' => 'inbound_message',
                    'position' => ['x' => 100, 'y' => 100],
                    'data' => ['title' => 'Inbound Message']
                ],
                [
                    'id' => 'n8n_1',
                    'type' => 'n8n',
                    'position' => ['x' => 300, 'y' => 100],
                    'data' => [
                        'title' => 'Enrich with n8n',
                        'url' => 'https://n8n.workflow.io/webhook/lead-enrich',
                        'method' => 'POST',
                        'auth_type' => 'header',
                        'auth_header_name' => 'X-N8N-API-KEY',
                        'auth_header_value' => 'n8n_secret_token_abc',
                        'body' => json_encode([
                            'contact_name' => '{{ contact.first_name }} {{ contact.last_name }}',
                            'contact_email' => '{{ contact.email }}',
                            'message' => '{{ inbound_message_body }}'
                        ]),
                        'save_key' => 'n8n_response',
                        'variable_mappings' => [
                            ['variable_name' => 'score', 'json_path' => 'data.lead_score', 'fallback' => 0],
                            ['variable_name' => 'company', 'json_path' => 'data.verified_company', 'fallback' => 'Unknown'],
                        ]
                    ]
                ],
                [
                    'id' => 'tag_1',
                    'type' => 'tag_contact',
                    'position' => ['x' => 550, 'y' => 100],
                    'data' => [
                        'title' => 'Tag Qualified',
                        'tag_action' => 'add_tag',
                        'target_tag' => 'n8n_verified'
                    ]
                ]
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'trigger_1', 'target' => 'n8n_1', 'sourceHandle' => 'out'],
                ['id' => 'e2', 'source' => 'n8n_1', 'target' => 'tag_1', 'sourceHandle' => 'success'],
            ]
        ];

        $version = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'definition' => $definition,
            'is_published' => true,
            'created_by' => $this->user->id,
        ]);

        $execution = FlowExecution::create([
            'tenant_id' => $this->tenant->id,
            'flow_id' => $flow->id,
            'flow_version_id' => $version->id,
            'contact_id' => $this->contact->id,
            'status' => 'pending',
            'current_node_id' => 'trigger_1',
            'context' => [
                'inbound_message_body' => 'I would like to inquire about AC motors'
            ]
        ]);

        $runner = app(FlowRunner::class);
        $runner->execute($execution);
        $execution->refresh();

        $this->assertEquals('completed', $execution->status);

        // Verify mapped variables into execution context
        $this->assertEquals(95, $execution->context['variables']['score']);
        $this->assertEquals('Tesla Electric', $execution->context['variables']['company']);
        $this->assertEquals('success', $execution->context['variables']['n8n_response']['status']);

        // Verify HTTP request payload and headers
        Http::assertSent(function ($request) {
            return $request->url() === 'https://n8n.workflow.io/webhook/lead-enrich'
                && $request->hasHeader('X-N8N-API-KEY', 'n8n_secret_token_abc');
        });

        // Verify downstream tag node ran
        $this->contact->refresh();
        $this->assertContains('n8n_verified', $this->contact->tags);
    }

    /**
     * Test Zapier node executes live catch hook and routes to error port on HTTP failure.
     */
    public function test_zapier_node_executes_catch_hook_and_handles_error_routing(): void
    {
        Http::fake([
            'https://hooks.zapier.com/hooks/catch/123456/abcdef/' => Http::response([
                'status' => 'error',
                'message' => 'Zapier rate limit exceeded'
            ], 500)
        ]);

        $flow = Flow::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Zapier Catch Hook Test',
            'trigger_type' => 'inbound_message',
            'channel_type' => 'omnichannel',
            'is_active' => true,
        ]);

        $definition = [
            'nodes' => [
                [
                    'id' => 'trigger_1',
                    'type' => 'inbound_message',
                    'position' => ['x' => 100, 'y' => 100],
                    'data' => ['title' => 'Inbound Message']
                ],
                [
                    'id' => 'zapier_1',
                    'type' => 'zapier',
                    'position' => ['x' => 300, 'y' => 100],
                    'data' => [
                        'title' => 'Send to Zapier CRM',
                        'url' => 'https://hooks.zapier.com/hooks/catch/123456/abcdef/',
                        'method' => 'POST',
                        'body' => json_encode([
                            'email' => '{{ contact.email }}',
                            'phone' => '{{ contact.phone }}'
                        ]),
                        'save_key' => 'zapier_response'
                    ]
                ],
                [
                    'id' => 'tag_success',
                    'type' => 'tag_contact',
                    'position' => ['x' => 550, 'y' => 50],
                    'data' => [
                        'title' => 'Tag Success',
                        'tag_action' => 'add_tag',
                        'target_tag' => 'zapier_synced'
                    ]
                ],
                [
                    'id' => 'tag_error',
                    'type' => 'tag_contact',
                    'position' => ['x' => 550, 'y' => 200],
                    'data' => [
                        'title' => 'Tag Error',
                        'tag_action' => 'add_tag',
                        'target_tag' => 'zapier_failed'
                    ]
                ]
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'trigger_1', 'target' => 'zapier_1', 'sourceHandle' => 'out'],
                ['id' => 'e2', 'source' => 'zapier_1', 'target' => 'tag_success', 'sourceHandle' => 'success'],
                ['id' => 'e3', 'source' => 'zapier_1', 'target' => 'tag_error', 'sourceHandle' => 'error'],
            ]
        ];

        $version = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'definition' => $definition,
            'is_published' => true,
            'created_by' => $this->user->id,
        ]);

        $execution = FlowExecution::create([
            'tenant_id' => $this->tenant->id,
            'flow_id' => $flow->id,
            'flow_version_id' => $version->id,
            'contact_id' => $this->contact->id,
            'status' => 'pending',
            'current_node_id' => 'trigger_1',
            'context' => []
        ]);

        $runner = app(FlowRunner::class);
        $runner->execute($execution);
        $execution->refresh();

        $this->assertEquals('completed', $execution->status);

        // Verify downstream branch routed to error tag
        $this->contact->refresh();
        $this->assertContains('zapier_failed', $this->contact->tags);
        $this->assertNotContains('zapier_synced', $this->contact->tags);
    }

    /**
     * Test simulator execution for n8n and Zapier with mock payloads.
     */
    public function test_simulator_execution_for_n8n_and_zapier(): void
    {
        $flow = Flow::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Simulator Flow',
            'trigger_type' => 'inbound_message',
            'channel_type' => 'omnichannel',
            'is_active' => true,
        ]);

        $definition = [
            'nodes' => [
                [
                    'id' => 'trigger_1',
                    'type' => 'inbound_message',
                    'position' => ['x' => 100, 'y' => 100],
                    'data' => ['title' => 'Inbound']
                ],
                [
                    'id' => 'n8n_sim',
                    'type' => 'n8n',
                    'position' => ['x' => 300, 'y' => 100],
                    'data' => [
                        'title' => 'Simulated n8n',
                        'url' => 'https://mock.n8n.io',
                        'example_response' => json_encode([
                            'status' => 'simulated_success',
                            'deal_value' => 5000
                        ]),
                        'variable_mappings' => [
                            ['variable_name' => 'mock_deal_val', 'json_path' => 'deal_value', 'fallback' => 0]
                        ]
                    ]
                ]
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'trigger_1', 'target' => 'n8n_sim', 'sourceHandle' => 'out']
            ]
        ];

        $version = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'definition' => $definition,
            'is_published' => true,
            'created_by' => $this->user->id,
        ]);

        $execution = FlowExecution::create([
            'tenant_id' => $this->tenant->id,
            'flow_id' => $flow->id,
            'flow_version_id' => $version->id,
            'contact_id' => $this->contact->id,
            'status' => 'pending',
            'current_node_id' => 'trigger_1',
            'context' => [
                'is_simulator' => true
            ]
        ]);

        $runner = app(FlowRunner::class);
        $runner->execute($execution);
        $execution->refresh();

        $this->assertEquals('completed', $execution->status);
        $this->assertEquals(5000, $execution->context['variables']['mock_deal_val']);
        $this->assertEquals('simulated_success', $execution->context['variables']['n8n_response']['status']);
    }
}
