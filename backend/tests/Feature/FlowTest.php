<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Contact;
use App\Models\Flow;
use App\Models\FlowVersion;
use App\Models\FlowExecution;
use App\Models\FlowExecutionLog;
use App\Services\Flow\FlowRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FlowTest extends TestCase
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
            'company_name' => 'Flow Systems Inc',
            'status' => 'trial',
            'onboarding_step' => 'complete',
            'plan_id' => 1
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@lovelace.io',
            'password' => Hash::make('Lovelace123!'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Charles',
            'last_name' => 'Babbage',
            'email' => 'charles@babbage.org',
            'phone' => '+15550199',
            'tags' => ['warm'],
            'custom_fields' => ['company' => 'Difference Engine Corp']
        ]);
    }

    /**
     * Test flows CRUD endpoints.
     */
    public function test_can_manage_flows(): void
    {
        $this->actingAs($this->user);

        // 1. Create Flow
        $response = $this->postJson('/api/flows', [
            'name' => 'Lead Qualification Bot',
            'description' => 'Qualifies incoming chat leads automatically',
            'trigger_type' => 'inbound_message',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('name', 'Lead Qualification Bot');

        $flowId = $response->json('id');

        // 2. Fetch list
        $response = $this->getJson('/api/flows');
        $response->assertStatus(200)
            ->assertJsonCount(1);

        // 3. Show details
        $response = $this->getJson("/api/flows/{$flowId}");
        $response->assertStatus(200)
            ->assertJsonPath('name', 'Lead Qualification Bot');

        // 4. Delete Flow
        $response = $this->deleteJson("/api/flows/{$flowId}");
        $response->assertStatus(200);

        $this->assertSoftDeleted('flows', ['id' => $flowId]);
    }

    /**
     * Test saving draft versions.
     */
    public function test_can_save_flow_versions(): void
    {
        $this->actingAs($this->user);

        $flow = Flow::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Support Router',
            'trigger_type' => 'inbound_message',
        ]);

        $definition = [
            'nodes' => [
                [
                    'id' => 'trigger_1',
                    'type' => 'inbound_message',
                    'position' => ['x' => 100, 'y' => 100],
                    'data' => ['title' => 'Start Trigger']
                ]
            ],
            'edges' => []
        ];

        $response = $this->postJson("/api/flows/{$flow->id}/versions", [
            'definition' => $definition
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('version_number', 1)
            ->assertJsonPath('is_published', false);
    }

    /**
     * Test validation rules when publishing.
     */
    public function test_publish_validates_flow_correctly(): void
    {
        $this->actingAs($this->user);

        $flow = Flow::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Broker Bot',
            'trigger_type' => 'inbound_message',
        ]);

        // 1. Invalid definition (No Trigger node)
        $invalidDef = [
            'nodes' => [
                [
                    'id' => 'send_1',
                    'type' => 'send_message',
                    'data' => ['title' => 'Say Hello']
                ]
            ],
            'edges' => []
        ];

        $response = $this->postJson("/api/flows/{$flow->id}/publish", [
            'definition' => $invalidDef
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'errors']);

        // 2. Valid definition (Trigger + connected nodes)
        $validDef = [
            'nodes' => [
                [
                    'id' => 'trigger_1',
                    'type' => 'inbound_message',
                    'data' => ['title' => 'Start Trigger']
                ],
                [
                    'id' => 'send_1',
                    'type' => 'send_message',
                    'data' => ['title' => 'Say Hello', 'body' => 'Hi {{ contact.first_name }}!']
                ]
            ],
            'edges' => [
                [
                    'source' => 'trigger_1',
                    'sourceHandle' => 'out',
                    'target' => 'send_1',
                    'targetHandle' => 'in'
                ]
            ]
        ];

        $response = $this->postJson("/api/flows/{$flow->id}/publish", [
            'definition' => $validDef
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('flow.is_active', true);
    }

    /**
     * Test visual dry-run simulator execution sandbox.
     */
    public function test_visual_simulator_executes_successfully(): void
    {
        $this->actingAs($this->user);

        $flow = Flow::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Simulator Test Flow',
            'trigger_type' => 'inbound_message',
        ]);

        $definition = [
            'nodes' => [
                [
                    'id' => 'trigger_1',
                    'type' => 'inbound_message',
                    'data' => ['title' => 'Start Trigger']
                ],
                [
                    'id' => 'send_1',
                    'type' => 'send_message',
                    'data' => ['title' => 'Hello', 'body' => 'Hi {{ contact.first_name }}, custom company is {{ contact.custom_fields.company }}']
                ],
                [
                    'id' => 'tag_1',
                    'type' => 'tag_contact',
                    'data' => ['title' => 'Tag Warm', 'tag_action' => 'add_tag', 'target_tag' => 'hot']
                ]
            ],
            'edges' => [
                [
                    'source' => 'trigger_1',
                    'sourceHandle' => 'out',
                    'target' => 'send_1',
                    'targetHandle' => 'in'
                ],
                [
                    'source' => 'send_1',
                    'sourceHandle' => 'out',
                    'target' => 'tag_1',
                    'targetHandle' => 'in'
                ]
            ]
        ];

        $response = $this->postJson("/api/flows/{$flow->id}/simulator", [
            'contact_id' => $this->contact->id,
            'definition' => $definition,
            'variables' => [
                'inbound_message_body' => 'Fires query keyword info'
            ]
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'completed')
            ->assertJsonStructure(['status', 'variables', 'simulated_messages', 'logs']);

        $logs = $response->json('logs');
        $this->assertCount(3, $logs);
        
        $messages = $response->json('simulated_messages');
        $this->assertCount(1, $messages);
        $this->assertEquals('Hi Charles, custom company is Difference Engine Corp', $messages[0]['body']);
    }

    /**
     * Test loop prevention safety limits.
     */
    public function test_runner_prevents_infinite_loops(): void
    {
        $this->actingAs($this->user);

        $flow = Flow::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cycle Loop Test',
            'trigger_type' => 'inbound_message',
        ]);

        // Infinite direct cycle edge: trigger -> send_1 -> tag_1 -> send_1
        $definition = [
            'nodes' => [
                [
                    'id' => 'trigger_1',
                    'type' => 'inbound_message',
                    'data' => ['title' => 'Start']
                ],
                [
                    'id' => 'send_1',
                    'type' => 'send_message',
                    'data' => ['title' => 'Node A', 'body' => 'Spamming']
                ],
                [
                    'id' => 'tag_1',
                    'type' => 'tag_contact',
                    'data' => ['title' => 'Node B', 'tag_action' => 'add_tag', 'target_tag' => 'looping']
                ]
            ],
            'edges' => [
                ['source' => 'trigger_1', 'sourceHandle' => 'out', 'target' => 'send_1', 'targetHandle' => 'in'],
                ['source' => 'send_1', 'sourceHandle' => 'out', 'target' => 'tag_1', 'targetHandle' => 'in'],
                ['source' => 'tag_1', 'sourceHandle' => 'out', 'target' => 'send_1', 'targetHandle' => 'in']
            ]
        ];

        $response = $this->postJson("/api/flows/{$flow->id}/simulator", [
            'contact_id' => $this->contact->id,
            'definition' => $definition,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'failed');
            
        $this->assertStringContainsString('Infinite loop cycle detected', $response->json('last_error'));
    }

    /**
     * Test execution of e-commerce nodes in the visual flow runner simulator.
     */
    public function test_ecommerce_nodes_execute_and_branch_successfully(): void
    {
        $this->actingAs($this->user);

        $flow = Flow::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Ecommerce Flow Test',
            'trigger_type' => 'ecommerceCheckoutAbandoned',
        ]);

        $definition = [
            'nodes' => [
                [
                    'id' => 'trigger_1',
                    'type' => 'ecommerceCheckoutAbandoned',
                    'data' => ['title' => 'Checkout Abandoned']
                ],
                [
                    'id' => 'condition_1',
                    'type' => 'checkCartStatus',
                    'data' => [
                        'title' => 'Check Cart Status',
                        'cartProperty' => 'total_price',
                        'operator' => 'gt',
                        'value' => 150
                    ]
                ],
                [
                    'id' => 'discount_1',
                    'type' => 'generateDiscountCode',
                    'data' => [
                        'title' => 'Generate Discount',
                        'discountType' => 'percentage',
                        'discountValue' => 20,
                        'codePrefix' => 'SAVEBIG',
                        'expiryDays' => 5
                    ]
                ],
                [
                    'id' => 'send_high',
                    'type' => 'send_message',
                    'data' => [
                        'title' => 'VIP Message',
                        'body' => 'High cart value! Your code is {{ discount.code }} expiring on {{ discount.expiry }}'
                    ]
                ]
            ],
            'edges' => [
                ['source' => 'trigger_1', 'sourceHandle' => 'out', 'target' => 'condition_1', 'targetHandle' => 'in'],
                ['source' => 'condition_1', 'sourceHandle' => 'true', 'target' => 'discount_1', 'targetHandle' => 'in'],
                ['source' => 'discount_1', 'sourceHandle' => 'out', 'target' => 'send_high', 'targetHandle' => 'in']
            ]
        ];

        // Simulate with high cart total (above 150)
        $response = $this->postJson("/api/flows/{$flow->id}/simulator", [
            'contact_id' => $this->contact->id,
            'definition' => $definition,
            'variables' => [
                'checkout_url' => 'https://mystore.myshopify.com/checkouts/123',
                'cart_total' => '200.00',
                'items_summary' => '[{"title":"Premium Jacket","qty":1,"price":"200.00"}]'
            ]
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'completed');

        // Check variables are populated
        $vars = $response->json('variables');
        $this->assertArrayHasKey('discount.code', $vars);
        $this->assertArrayHasKey('discount.expiry', $vars);
        $this->assertStringStartsWith('SAVEBIG-', $vars['discount.code']);

        // Check simulated messages
        $messages = $response->json('simulated_messages');
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('High cart value! Your code is SAVEBIG-', $messages[0]['body']);
    }

    /**
     * Test interactive_menu node execution and choice branching.
     */
    public function test_interactive_menu_flow_execution(): void
    {
        $this->actingAs($this->user);

        $flow = Flow::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Support Menu Bot',
            'status' => 'draft',
            'trigger_type' => 'inbound_message',
        ]);

        $definition = [
            'nodes' => [
                [
                    'id' => 'trigger_1',
                    'type' => 'inbound_message',
                    'data' => ['title' => 'Start', 'keyword' => 'hello']
                ],
                [
                    'id' => 'menu_1',
                    'type' => 'interactive_menu',
                    'data' => [
                        'title' => 'Main Menu',
                        'header' => 'Welcome to Support',
                        'body' => 'Please select what you need help with:',
                        'saveVariable' => 'chosen_service',
                        'items' => [
                            ['id' => 'opt_billing', 'title' => 'Billing & Invoices', 'keywords' => '1, billing', 'value' => 'service_billing'],
                            ['id' => 'opt_tech', 'title' => 'Technical Support', 'keywords' => '2, tech', 'value' => 'service_tech'],
                            ['id' => 'opt_staff', 'title' => 'Book Specialist', 'keywords' => '3, staff', 'value' => 'service_booking']
                        ]
                    ]
                ],
                [
                    'id' => 'tag_billing',
                    'type' => 'tag_contact',
                    'data' => ['title' => 'Tag Billing', 'tagAction' => 'add_tag', 'targetTag' => 'billing_inquiry']
                ],
                [
                    'id' => 'tag_tech',
                    'type' => 'tag_contact',
                    'data' => ['title' => 'Tag Tech', 'tagAction' => 'add_tag', 'targetTag' => 'tech_inquiry']
                ]
            ],
            'edges' => [
                ['source' => 'trigger_1', 'sourceHandle' => 'out', 'target' => 'menu_1', 'targetHandle' => 'in'],
                ['source' => 'menu_1', 'sourceHandle' => 'opt_billing', 'target' => 'tag_billing', 'targetHandle' => 'in'],
                ['source' => 'menu_1', 'sourceHandle' => 'opt_tech', 'target' => 'tag_tech', 'targetHandle' => 'in'],
            ]
        ];

        // 1. Initial simulation starts and pauses at menu
        $response = $this->postJson("/api/flows/{$flow->id}/simulator", [
            'contact_id' => $this->contact->id,
            'definition' => $definition,
            'variables' => ['inbound_message_body' => 'hello']
        ]);

        $response->assertStatus(200);
        $messages = $response->json('simulated_messages');
        $this->assertNotEmpty($messages);
        $this->assertStringContainsString('Welcome to Support', $messages[0]['body']);
        $this->assertStringContainsString('1️⃣ *Billing & Invoices*', $messages[0]['body']);
        $this->assertStringContainsString('2️⃣ *Technical Support*', $messages[0]['body']);
        $this->assertStringContainsString('3️⃣ *Book Specialist*', $messages[0]['body']);

        // 2. Direct unit test of FlowRunner match resolution
        $flowRunner = app(FlowRunner::class);
        $version = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'definition' => $definition,
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);

        $execution = FlowExecution::create([
            'tenant_id' => $this->tenant->id,
            'flow_version_id' => $version->id,
            'contact_id' => $this->contact->id,
            'current_node_id' => 'menu_1',
            'status' => 'paused_waiting_reply',
            'context' => [
                'variables' => [
                    'menu_waiting_menu_1' => true,
                    'inbound_message_body' => '1', // User selects option 1 (Billing)
                    'current_save_variable' => 'chosen_service'
                ]
            ]
        ]);

        $flowRunner->execute($execution);

        $execution->refresh();
        $this->assertEquals('completed', $execution->status);
        $this->assertEquals('service_billing', $execution->context['variables']['chosen_service']);

        // 3. Test keyword reply matching ("I need tech help" matching keyword "tech")
        $execution2 = FlowExecution::create([
            'tenant_id' => $this->tenant->id,
            'flow_version_id' => $version->id,
            'contact_id' => $this->contact->id,
            'current_node_id' => 'menu_1',
            'status' => 'paused_waiting_reply',
            'context' => [
                'variables' => [
                    'menu_waiting_menu_1' => true,
                    'inbound_message_body' => 'I need tech help',
                    'current_save_variable' => 'chosen_service'
                ]
            ]
        ]);

        $flowRunner->execute($execution2);

        $execution2->refresh();
        $this->assertEquals('completed', $execution2->status);
        $this->assertEquals('service_tech', $execution2->context['variables']['chosen_service']);
    }
}
