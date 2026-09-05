<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Contact;
use App\Models\Flow;
use App\Models\FlowVersion;
use App\Models\FlowExecution;
use App\Models\Deal;
use App\Services\Flow\FlowRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FlowTriggersDeepTest extends TestCase
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
            'company_name' => 'Deep Test Automations',
            'status' => 'active',
            'onboarding_step' => 'complete',
            'plan_id' => 1
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Alan',
            'last_name' => 'Turing',
            'email' => 'alan@turing.org',
            'password' => Hash::make('TuringEnigma123!'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'email' => 'grace.hopper@usnavy.mil',
            'phone' => '+15559876',
            'tags' => ['vip', 'inbound'],
            'custom_fields' => [
                'company' => 'COBOL Systems',
                'account_tier' => 'Platinum'
            ]
        ]);
    }

    /**
     * SCENARIO 1: Inbound Message Trigger with Condition Matrix, Tagging, and Question Asking
     */
    public function test_inbound_message_trigger_full_execution(): void
    {
        $this->actingAs($this->user);

        $flow = Flow::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Customer Inbound Support Flow',
            'trigger_type' => 'inbound_message',
            'is_active' => true,
        ]);

        $definition = [
            'nodes' => [
                [
                    'id' => 'trigger_1',
                    'type' => 'inbound_message',
                    'data' => ['title' => 'Inbound Customer Message']
                ],
                [
                    'id' => 'cond_pricing',
                    'type' => 'condition',
                    'data' => [
                        'title' => 'Check if Pricing Query',
                        'operator' => 'AND',
                        'conditions' => [
                            [
                                'field' => 'inbound_message_body',
                                'operator' => 'contains',
                                'value' => 'pricing'
                            ]
                        ]
                    ]
                ],
                [
                    'id' => 'tag_pricing_lead',
                    'type' => 'tag_contact',
                    'data' => [
                        'title' => 'Tag as Pricing Lead',
                        'tagAction' => 'add_tag',
                        'targetTag' => 'Pricing_Inquiry'
                    ]
                ],
                [
                    'id' => 'reply_pricing',
                    'type' => 'send_message',
                    'data' => [
                        'title' => 'Send Pricing Details',
                        'body' => 'Hello {{ contact.first_name }} from {{ contact.custom_fields.company }}! Our plans start at $29/mo.'
                    ]
                ]
            ],
            'edges' => [
                ['source' => 'trigger_1', 'sourceHandle' => 'out', 'target' => 'cond_pricing', 'targetHandle' => 'in'],
                ['source' => 'cond_pricing', 'sourceHandle' => 'true', 'target' => 'tag_pricing_lead', 'targetHandle' => 'in'],
                ['source' => 'tag_pricing_lead', 'sourceHandle' => 'out', 'target' => 'reply_pricing', 'targetHandle' => 'in'],
            ]
        ];

        // Simulate execution with incoming message containing "pricing"
        $response = $this->postJson("/api/flows/{$flow->id}/simulator", [
            'contact_id' => $this->contact->id,
            'definition' => $definition,
            'variables' => [
                'inbound_message_body' => 'Can you please send me your pricing list?',
            ]
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'completed');

        // Verify simulated message interpolation
        $messages = $response->json('simulated_messages');
        $this->assertCount(1, $messages);
        $this->assertEquals(
            'Hello Grace from COBOL Systems! Our plans start at $29/mo.',
            $messages[0]['body']
        );
    }

    /**
     * SCENARIO 2: Outbound Broadcast Campaign Trigger with CRM Deal Creation
     */
    public function test_outbound_campaign_trigger_with_deal_creation(): void
    {
        $this->actingAs($this->user);

        $flow = Flow::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Q3 Enterprise Campaign Outreach',
            'trigger_type' => 'outbound_campaign',
            'is_active' => true,
        ]);

        $definition = [
            'nodes' => [
                [
                    'id' => 'trigger_1',
                    'type' => 'outbound_campaign',
                    'data' => ['title' => 'Mass Outbound Broadcast']
                ],
                [
                    'id' => 'send_broadcast_msg',
                    'type' => 'send_message',
                    'data' => [
                        'title' => 'Broadcast Pitch',
                        'body' => 'Greetings {{ contact.first_name }} {{ contact.last_name }} ({{ contact.custom_fields.account_tier }} tier member)!'
                    ]
                ],
                [
                    'id' => 'create_campaign_deal',
                    'type' => 'create_deal',
                    'data' => [
                        'title' => 'Create High Priority Deal',
                        'dealName' => 'Enterprise Expansion - {{ contact.first_name }}',
                        'dealValue' => 15000,
                        'stage' => 'qualified'
                    ]
                ]
            ],
            'edges' => [
                ['source' => 'trigger_1', 'sourceHandle' => 'out', 'target' => 'send_broadcast_msg', 'targetHandle' => 'in'],
                ['source' => 'send_broadcast_msg', 'sourceHandle' => 'out', 'target' => 'create_campaign_deal', 'targetHandle' => 'in']
            ]
        ];

        $response = $this->postJson("/api/flows/{$flow->id}/simulator", [
            'contact_id' => $this->contact->id,
            'definition' => $definition,
            'variables' => []
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'completed');

        $messages = $response->json('simulated_messages');
        $this->assertCount(1, $messages);
        $this->assertEquals(
            'Greetings Grace Hopper (Platinum tier member)!',
            $messages[0]['body']
        );
    }

    /**
     * SCENARIO 3: API Webhook Trigger with Deep Nested JSON Parsing and Variable Extraction
     */
    public function test_webhook_trigger_nested_json_parsing_and_variable_mappings(): void
    {
        $this->actingAs($this->user);

        $flow = Flow::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Stripe / Shopify Inbound Webhook Listener',
            'trigger_type' => 'webhook_trigger',
            'is_active' => true,
        ]);

        $definition = [
            'nodes' => [
                [
                    'id' => 'trigger_1',
                    'type' => 'webhook_trigger',
                    'data' => [
                        'title' => 'API Webhook Inbound Payload',
                        'method' => 'POST',
                        'variable_mappings' => [
                            ['jsonPath' => 'event.id', 'variableName' => 'evt_id'],
                            ['jsonPath' => 'data.order.number', 'variableName' => 'order_no'],
                            ['jsonPath' => 'data.order.total_usd', 'variableName' => 'order_total'],
                            ['jsonPath' => 'data.customer.shipping_address.city', 'variableName' => 'ship_city'],
                            ['jsonPath' => 'data.items.0.sku', 'variableName' => 'first_sku'],
                        ]
                    ]
                ],
                [
                    'id' => 'send_order_confirm',
                    'type' => 'send_message',
                    'data' => [
                        'title' => 'Order Confirmation Alert',
                        'body' => 'Order #{{ order_no }} confirmed! Total: ${{ order_total }}. First Item: {{ first_sku }}. Shipping to {{ ship_city }} (Event Ref: {{ evt_id }}).'
                    ]
                ]
            ],
            'edges' => [
                ['source' => 'trigger_1', 'sourceHandle' => 'out', 'target' => 'send_order_confirm', 'targetHandle' => 'in']
            ]
        ];

        // Complex nested JSON payload supplied in execution context
        $inboundJson = [
            'event' => [
                'id' => 'EVT_998811',
                'type' => 'checkout.completed'
            ],
            'data' => [
                'order' => [
                    'number' => 'ORD-5542',
                    'total_usd' => '499.00',
                ],
                'customer' => [
                    'shipping_address' => [
                        'city' => 'San Francisco',
                        'country' => 'USA'
                    ]
                ],
                'items' => [
                    ['sku' => 'QUANTUM-CHIP-V2', 'qty' => 1, 'price' => 499.00],
                    ['sku' => 'COOLING-PAD', 'qty' => 2, 'price' => 25.00]
                ]
            ]
        ];

        $response = $this->postJson("/api/flows/{$flow->id}/simulator", [
            'contact_id' => $this->contact->id,
            'definition' => $definition,
            'variables' => [
                'raw_payload' => json_encode($inboundJson),
                'evt_id' => 'EVT_998811',
                'order_no' => 'ORD-5542',
                'order_total' => '499.00',
                'ship_city' => 'San Francisco',
                'first_sku' => 'QUANTUM-CHIP-V2',
            ]
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'completed');

        $messages = $response->json('simulated_messages');
        $this->assertCount(1, $messages);
        $this->assertEquals(
            'Order #ORD-5542 confirmed! Total: $499.00. First Item: QUANTUM-CHIP-V2. Shipping to San Francisco (Event Ref: EVT_998811).',
            $messages[0]['body']
        );
    }

    /**
     * SCENARIO 4: Webhook Dispatch (Outbound API HTTP Request) Node with Simulated Fallback & Variable Extraction
     */
    public function test_webhook_dispatch_node_with_simulated_response_and_variable_extraction(): void
    {
        $this->actingAs($this->user);

        $flow = Flow::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Third Party CRM Sync Flow',
            'trigger_type' => 'inbound_message',
            'is_active' => true,
        ]);

        $simulatedApiResponse = [
            'status' => 'success',
            'auth' => 'verified',
            'account' => [
                'uuid' => 'ACC-UUID-776655',
                'credit_score' => 780,
                'tier' => 'Gold VIP'
            ]
        ];

        $definition = [
            'nodes' => [
                [
                    'id' => 'trigger_1',
                    'type' => 'inbound_message',
                    'data' => ['title' => 'Start Trigger']
                ],
                [
                    'id' => 'dispatch_api_call',
                    'type' => 'webhook_dispatch',
                    'data' => [
                        'title' => 'Check Customer Credit Tier',
                        'method' => 'POST',
                        'url' => 'https://api.external-credit-bureau.com/v1/score',
                        'headers' => [
                            ['key' => 'Authorization', 'value' => 'Bearer SECRET_TOKEN_123'],
                            ['key' => 'Content-Type', 'value' => 'application/json']
                        ],
                        'example_response' => json_encode($simulatedApiResponse),
                        'variable_mappings' => [
                            ['jsonPath' => 'account.uuid', 'variableName' => 'acc_uuid'],
                            ['jsonPath' => 'account.credit_score', 'variableName' => 'credit_rating'],
                            ['jsonPath' => 'account.tier', 'variableName' => 'vip_tier'],
                        ],
                        'saveKey' => 'full_bureau_response'
                    ]
                ],
                [
                    'id' => 'send_tier_reply',
                    'type' => 'send_message',
                    'data' => [
                        'title' => 'Send Credit Approval',
                        'body' => 'Account {{ acc_uuid }} verified! Tier: {{ vip_tier }} (Rating: {{ credit_rating }}).'
                    ]
                ]
            ],
            'edges' => [
                ['source' => 'trigger_1', 'sourceHandle' => 'out', 'target' => 'dispatch_api_call', 'targetHandle' => 'in'],
                ['source' => 'dispatch_api_call', 'sourceHandle' => 'out', 'target' => 'send_tier_reply', 'targetHandle' => 'in']
            ]
        ];

        $response = $this->postJson("/api/flows/{$flow->id}/simulator", [
            'contact_id' => $this->contact->id,
            'definition' => $definition,
            'variables' => []
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'completed');

        // Verify variables were parsed from example response
        $vars = $response->json('variables');
        $this->assertEquals('ACC-UUID-776655', $vars['acc_uuid']);
        $this->assertEquals('780', (string)$vars['credit_rating']);
        $this->assertEquals('Gold VIP', $vars['vip_tier']);

        $messages = $response->json('simulated_messages');
        $this->assertCount(1, $messages);
        $this->assertEquals(
            'Account ACC-UUID-776655 verified! Tier: Gold VIP (Rating: 780).',
            $messages[0]['body']
        );
    }

    /**
     * SCENARIO 5: Ecommerce Abandoned Cart Trigger with Cart Evaluation, Coupon Code Generation & Personalized Link
     */
    public function test_ecommerce_abandoned_cart_trigger_with_dynamic_coupon(): void
    {
        $this->actingAs($this->user);

        $flow = Flow::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Shopify Abandoned Cart Recovery Bot',
            'trigger_type' => 'ecommerceCheckoutAbandoned',
            'is_active' => true,
        ]);

        $definition = [
            'nodes' => [
                [
                    'id' => 'trigger_1',
                    'type' => 'ecommerceCheckoutAbandoned',
                    'data' => ['title' => 'Cart Checkout Abandoned']
                ],
                [
                    'id' => 'check_cart',
                    'type' => 'checkCartStatus',
                    'data' => [
                        'title' => 'Check if Cart > $100',
                        'cartProperty' => 'total_price',
                        'operator' => 'gt',
                        'value' => 100
                    ]
                ],
                [
                    'id' => 'gen_discount',
                    'type' => 'generateDiscountCode',
                    'data' => [
                        'title' => 'Generate Recovery Voucher',
                        'codePrefix' => 'RECOVERVIP',
                        'discountType' => 'percentage',
                        'discountValue' => 15,
                        'expiryDays' => 3
                    ]
                ],
                [
                    'id' => 'send_recovery_sms',
                    'type' => 'send_message',
                    'data' => [
                        'title' => 'Recovery SMS with Coupon',
                        'body' => 'Hey {{ contact.first_name }}! You left items in your cart. Finish your order at {{ checkout_url }} with code {{ discount.code }} for 15% off!'
                    ]
                ]
            ],
            'edges' => [
                ['source' => 'trigger_1', 'sourceHandle' => 'out', 'target' => 'check_cart', 'targetHandle' => 'in'],
                ['source' => 'check_cart', 'sourceHandle' => 'true', 'target' => 'gen_discount', 'targetHandle' => 'in'],
                ['source' => 'gen_discount', 'sourceHandle' => 'out', 'target' => 'send_recovery_sms', 'targetHandle' => 'in'],
            ]
        ];

        $response = $this->postJson("/api/flows/{$flow->id}/simulator", [
            'contact_id' => $this->contact->id,
            'definition' => $definition,
            'variables' => [
                'checkout_url' => 'https://quickshop.store/checkouts/ac_991823',
                'cart_total' => '249.50',
                'items_summary' => json_encode([['title' => 'Wireless Headphones', 'qty' => 1, 'price' => 249.50]])
            ]
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'completed');

        $vars = $response->json('variables');
        $this->assertArrayHasKey('discount.code', $vars);
        $this->assertStringStartsWith('RECOVERVIP-', $vars['discount.code']);

        $messages = $response->json('simulated_messages');
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('Hey Grace! You left items in your cart.', $messages[0]['body']);
        $this->assertStringContainsString('https://quickshop.store/checkouts/ac_991823', $messages[0]['body']);
        $this->assertStringContainsString('RECOVERVIP-', $messages[0]['body']);
    }

    /**
     * SCENARIO 6: Contact Created Event Trigger
     */
    public function test_contact_created_trigger(): void
    {
        $this->actingAs($this->user);

        $flow = Flow::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'New Contact Welcome Flow',
            'trigger_type' => 'contact_created',
            'is_active' => true,
        ]);

        $definition = [
            'nodes' => [
                [
                    'id' => 'trigger_1',
                    'type' => 'contact_created',
                    'data' => ['title' => 'Contact Created']
                ],
                [
                    'id' => 'welcome_msg',
                    'type' => 'send_message',
                    'data' => [
                        'title' => 'Welcome Message',
                        'body' => 'Welcome to WhatsOmni, {{ contact.first_name }}!'
                    ]
                ]
            ],
            'edges' => [
                ['source' => 'trigger_1', 'sourceHandle' => 'out', 'target' => 'welcome_msg', 'targetHandle' => 'in']
            ]
        ];

        $response = $this->postJson("/api/flows/{$flow->id}/simulator", [
            'contact_id' => $this->contact->id,
            'definition' => $definition,
            'variables' => []
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'completed');

        $messages = $response->json('simulated_messages');
        $this->assertCount(1, $messages);
        $this->assertEquals('Welcome to WhatsOmni, Grace!', $messages[0]['body']);
    }

    /**
     * SCENARIO 7: Deal Stage Updated Event Trigger
     */
    public function test_deal_updated_trigger(): void
    {
        $this->actingAs($this->user);

        $flow = Flow::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Deal Stage Movement Flow',
            'trigger_type' => 'deal_updated',
            'is_active' => true,
        ]);

        $definition = [
            'nodes' => [
                [
                    'id' => 'trigger_1',
                    'type' => 'deal_updated',
                    'data' => ['title' => 'Deal Updated']
                ],
                [
                    'id' => 'deal_alert_msg',
                    'type' => 'send_message',
                    'data' => [
                        'title' => 'Deal Alert',
                        'body' => 'Deal {{ deal_title }} moved to stage {{ deal_stage }} valued at ${{ deal_value }}.'
                    ]
                ]
            ],
            'edges' => [
                ['source' => 'trigger_1', 'sourceHandle' => 'out', 'target' => 'deal_alert_msg', 'targetHandle' => 'in']
            ]
        ];

        $response = $this->postJson("/api/flows/{$flow->id}/simulator", [
            'contact_id' => $this->contact->id,
            'definition' => $definition,
            'variables' => [
                'deal_title' => 'Global Expansion Deal',
                'deal_stage' => 'Proposal Sent',
                'deal_value' => '75000'
            ]
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'completed');

        $messages = $response->json('simulated_messages');
        $this->assertCount(1, $messages);
        $this->assertEquals('Deal Global Expansion Deal moved to stage Proposal Sent valued at $75000.', $messages[0]['body']);
    }

    /**
     * SCENARIO 8: Manual Inbound Trigger
     */
    public function test_manual_trigger(): void
    {
        $this->actingAs($this->user);

        $flow = Flow::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Manual Followup Workflow',
            'trigger_type' => 'manual',
            'is_active' => true,
        ]);

        $definition = [
            'nodes' => [
                [
                    'id' => 'trigger_1',
                    'type' => 'manual',
                    'data' => ['title' => 'Manual Trigger']
                ],
                [
                    'id' => 'manual_msg',
                    'type' => 'send_message',
                    'data' => [
                        'title' => 'Manual Message',
                        'body' => 'Manual outreach initiated for {{ contact.first_name }}.'
                    ]
                ]
            ],
            'edges' => [
                ['source' => 'trigger_1', 'sourceHandle' => 'out', 'target' => 'manual_msg', 'targetHandle' => 'in']
            ]
        ];

        $response = $this->postJson("/api/flows/{$flow->id}/simulator", [
            'contact_id' => $this->contact->id,
            'definition' => $definition,
            'variables' => []
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'completed');

        $messages = $response->json('simulated_messages');
        $this->assertCount(1, $messages);
        $this->assertEquals('Manual outreach initiated for Grace.', $messages[0]['body']);
    }

    /**
     * SCENARIO 9: End Flow Node with Farewell Message
     */
    public function test_end_flow_node(): void
    {
        $this->actingAs($this->user);

        $flow = Flow::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Exit Flow Test',
            'trigger_type' => 'inbound_message',
            'is_active' => true,
        ]);

        $definition = [
            'nodes' => [
                [
                    'id' => 'trigger_1',
                    'type' => 'inbound_message',
                    'data' => ['title' => 'Start']
                ],
                [
                    'id' => 'end_1',
                    'type' => 'end_flow',
                    'data' => [
                        'title' => 'End Conversation',
                        'body' => 'Thank you for reaching out, {{ contact.first_name }}! Have a wonderful day.'
                    ]
                ]
            ],
            'edges' => [
                ['source' => 'trigger_1', 'sourceHandle' => 'out', 'target' => 'end_1', 'targetHandle' => 'in']
            ]
        ];

        $response = $this->postJson("/api/flows/{$flow->id}/simulator", [
            'contact_id' => $this->contact->id,
            'definition' => $definition,
            'variables' => []
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'completed');

        $messages = $response->json('simulated_messages');
        $this->assertCount(1, $messages);
        $this->assertEquals('Thank you for reaching out, Grace! Have a wonderful day.', $messages[0]['body']);
    }

    /**
     * SCENARIO 10: RAG Knowledge Base in Single Answer Mode (Answers once and immediately advances)
     */
    public function test_rag_query_single_answer_mode(): void
    {
        $this->actingAs($this->user);

        $flow = Flow::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Single Answer RAG Knowledge Base Flow',
            'trigger_type' => 'inbound_message',
            'is_active' => true,
        ]);

        $definition = [
            'nodes' => [
                [
                    'id' => 'trigger_1',
                    'type' => 'inbound_message',
                    'data' => ['title' => 'Inbound Customer Question']
                ],
                [
                    'id' => 'rag_node',
                    'type' => 'rag_query',
                    'data' => [
                        'title' => 'Single KB Answer',
                        'mode' => 'single',
                        'saveKey' => 'faq_answer'
                    ]
                ],
                [
                    'id' => 'next_action',
                    'type' => 'send_message',
                    'data' => [
                        'title' => 'Followup Action',
                        'body' => 'Did that answer your question, {{ contact.first_name }}?'
                    ]
                ]
            ],
            'edges' => [
                ['source' => 'trigger_1', 'sourceHandle' => 'out', 'target' => 'rag_node', 'targetHandle' => 'in'],
                ['source' => 'rag_node', 'sourceHandle' => 'out', 'target' => 'next_action', 'targetHandle' => 'in']
            ]
        ];

        $response = $this->postJson("/api/flows/{$flow->id}/simulator", [
            'contact_id' => $this->contact->id,
            'definition' => $definition,
            'variables' => [
                'inbound_message_body' => 'What are your working hours?'
            ]
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'completed');

        $messages = $response->json('simulated_messages');
        $this->assertCount(2, $messages);
        // First message is the RAG response
        $this->assertNotEmpty($messages[0]['body']);
        // Second message is the immediate downstream next node
        $this->assertEquals('Did that answer your question, Grace?', $messages[1]['body']);
    }

    /**
     * SCENARIO 11: RAG Knowledge Base in Looping Mode (Answers & pauses until exit keyword is received)
     */
    public function test_rag_query_looping_mode_and_exit(): void
    {
        $this->actingAs($this->user);

        $flow = Flow::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Continuous Loop RAG Knowledge Base Flow',
            'trigger_type' => 'inbound_message',
            'is_active' => true,
        ]);

        $definition = [
            'nodes' => [
                [
                    'id' => 'trigger_1',
                    'type' => 'inbound_message',
                    'data' => ['title' => 'Inbound Customer Question']
                ],
                [
                    'id' => 'rag_node',
                    'type' => 'rag_query',
                    'data' => [
                        'title' => 'Looping KB Q&A',
                        'mode' => 'loop',
                        'exit_keywords' => 'exit, bye, thanks, done',
                        'saveKey' => 'faq_answer'
                    ]
                ],
                [
                    'id' => 'goodbye_node',
                    'type' => 'tag_contact',
                    'data' => [
                        'title' => 'Tag Resolved',
                        'target_tag' => 'resolved_by_kb'
                    ]
                ]
            ],
            'edges' => [
                ['source' => 'trigger_1', 'sourceHandle' => 'out', 'target' => 'rag_node', 'targetHandle' => 'in'],
                ['source' => 'rag_node', 'sourceHandle' => 'out', 'target' => 'goodbye_node', 'targetHandle' => 'in']
            ]
        ];

        // Step 1: Initial Question in Looping mode -> should answer and pause
        $response = $this->postJson("/api/flows/{$flow->id}/simulator", [
            'contact_id' => $this->contact->id,
            'definition' => $definition,
            'variables' => [
                'inbound_message_body' => 'Where are you located?'
            ]
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'paused_waiting_reply');

        $messages = $response->json('simulated_messages');
        $this->assertCount(1, $messages);

        // Step 2: Customer sends exit keyword "thanks" -> should break out of loop and advance to goodbye_node
        $flowVersion = \App\Models\FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'definition' => $definition,
            'created_by' => $this->user->id,
            'status' => 'draft'
        ]);

        $execution = \App\Models\FlowExecution::create([
            'flow_id' => $flow->id,
            'flow_version_id' => $flowVersion->id,
            'tenant_id' => $this->tenant->id,
            'contact_id' => $this->contact->id,
            'current_node_id' => 'rag_node',
            'status' => 'paused_waiting_reply',
            'context' => [
                'variables' => [
                    'rag_query_waiting_rag_node' => true,
                    'inbound_message_body' => 'thanks'
                ]
            ]
        ]);

        $flowRunner = app(\App\Services\Flow\FlowRunner::class);
        $flowRunner->execute($execution);

        $freshExecution = $execution->fresh();
        $this->assertEquals('completed', $freshExecution->status, "Execution failed with error: " . ($freshExecution->last_error ?? 'none'));
        $this->assertNull($freshExecution->context['variables']['rag_query_waiting_rag_node'] ?? null);
        $this->assertContains('resolved_by_kb', $this->contact->fresh()->tags);
    }

    /**
     * SCENARIO 12: Deep Scan & Test Custom Exit Keywords Parsing and Matching
     */
    public function test_rag_query_custom_exit_keywords_deep_scenarios(): void
    {
        $this->actingAs($this->user);

        $flow = Flow::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Custom Exit Keywords RAG Flow',
            'trigger_type' => 'inbound_message',
            'is_active' => true,
        ]);

        $customExitKeywords = 'book doctor, schedule visit, cancel enquiry, finish session, done';

        $definition = [
            'nodes' => [
                [
                    'id' => 'trigger_1',
                    'type' => 'inbound_message',
                    'data' => ['title' => 'Customer Message']
                ],
                [
                    'id' => 'rag_node',
                    'type' => 'rag_query',
                    'data' => [
                        'title' => 'Custom Keywords KB',
                        'mode' => 'loop',
                        'exit_keywords' => $customExitKeywords,
                        'saveKey' => 'answer'
                    ]
                ],
                [
                    'id' => 'book_appointment_node',
                    'type' => 'tag_contact',
                    'data' => [
                        'title' => 'Tag For Appointment',
                        'target_tag' => 'booking_requested'
                    ]
                ]
            ],
            'edges' => [
                ['source' => 'trigger_1', 'sourceHandle' => 'out', 'target' => 'rag_node', 'targetHandle' => 'in'],
                ['source' => 'rag_node', 'sourceHandle' => 'out', 'target' => 'book_appointment_node', 'targetHandle' => 'in']
            ]
        ];

        $flowVersion = \App\Models\FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'definition' => $definition,
            'created_by' => $this->user->id,
            'status' => 'published'
        ]);

        $flowRunner = app(\App\Services\Flow\FlowRunner::class);

        // TEST 1: User sends a standard question during active loop -> MUST NOT EXIT
        $executionQ = \App\Models\FlowExecution::create([
            'flow_id' => $flow->id,
            'flow_version_id' => $flowVersion->id,
            'tenant_id' => $this->tenant->id,
            'contact_id' => $this->contact->id,
            'current_node_id' => 'rag_node',
            'status' => 'paused_waiting_reply',
            'context' => [
                'variables' => [
                    'rag_query_waiting_rag_node' => true,
                    'inbound_message_body' => 'What is the cost of consultation?'
                ]
            ]
        ]);

        $flowRunner->execute($executionQ);
        $freshQ = $executionQ->fresh();
        // Stays in loop
        $this->assertEquals('paused_waiting_reply', $freshQ->status);
        $this->assertTrue($freshQ->context['variables']['rag_query_waiting_rag_node'] ?? false);
        $this->assertNotContains('booking_requested', $this->contact->fresh()->tags);

        // TEST 2: User sends custom phrase with mixed case & punctuation -> "I want to Book Doctor!" -> MUST MATCH & EXIT
        $executionExit1 = \App\Models\FlowExecution::create([
            'flow_id' => $flow->id,
            'flow_version_id' => $flowVersion->id,
            'tenant_id' => $this->tenant->id,
            'contact_id' => $this->contact->id,
            'current_node_id' => 'rag_node',
            'status' => 'paused_waiting_reply',
            'context' => [
                'variables' => [
                    'rag_query_waiting_rag_node' => true,
                    'inbound_message_body' => 'I want to Book Doctor!'
                ]
            ]
        ]);

        $flowRunner->execute($executionExit1);
        $freshExit1 = $executionExit1->fresh();
        // Successfully exited and completed downstream tag node!
        $this->assertEquals('completed', $freshExit1->status);
        $this->assertNull($freshExit1->context['variables']['rag_query_waiting_rag_node'] ?? null);
        $this->assertContains('booking_requested', $this->contact->fresh()->tags);

        // TEST 3: User sends single custom word -> "Schedule visit" -> MUST MATCH & EXIT
        $executionExit2 = \App\Models\FlowExecution::create([
            'flow_id' => $flow->id,
            'flow_version_id' => $flowVersion->id,
            'tenant_id' => $this->tenant->id,
            'contact_id' => $this->contact->id,
            'current_node_id' => 'rag_node',
            'status' => 'paused_waiting_reply',
            'context' => [
                'variables' => [
                    'rag_query_waiting_rag_node' => true,
                    'inbound_message_body' => 'Schedule visit.'
                ]
            ]
        ]);

        $flowRunner->execute($executionExit2);
        $freshExit2 = $executionExit2->fresh();
        $this->assertEquals('completed', $freshExit2->status);
    }
}
