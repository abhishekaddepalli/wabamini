<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ChannelConnection;
use App\Models\AiAgent;
use App\Models\AIProviderConfig;
use App\Models\EcommerceConnection;
use App\Models\EcommerceOrder;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeSource;
use App\Models\KnowledgeChunk;
use App\Jobs\SyncEcommerceCatalogJob;
use App\Jobs\ProcessInboundMessageJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiEcommerceToolsTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected ChannelConnection $connection;
    protected Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        // Register default plan
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
            'company_name' => 'Support AI Systems',
            'status' => 'trial',
            'onboarding_step' => 'complete',
            'plan_id' => 1
        ]);

        $this->connection = ChannelConnection::create([
            'tenant_id' => $this->tenant->id,
            'channel_type' => 'telegram',
            'name' => 'Telegram Test Bot',
            'credentials' => ['token' => 'mock_token'],
            'is_active' => true,
        ]);

        $this->contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Jane',
            'last_name' => 'Cooper',
            'phone' => '10049281982',
        ]);
    }

    /**
     * Test SyncEcommerceCatalogJob embeds catalog items correctly.
     */
    public function test_catalog_sync_and_vectorization(): void
    {
        $storeConn = EcommerceConnection::create([
            'tenant_id' => $this->tenant->id,
            'platform' => 'shopify',
            'store_url' => 'myshop.myshopify.com',
            'credentials' => ['access_token' => 'mock_access_token'],
            'status' => 'connected',
        ]);

        // Run sync job synchronously
        SyncEcommerceCatalogJob::dispatchSync($storeConn->id);

        // Verify KnowledgeBase & chunks exist
        $kb = KnowledgeBase::where('tenant_id', $this->tenant->id)->first();
        $this->assertNotNull($kb);

        $source = KnowledgeSource::where('knowledge_base_id', $kb->id)
            ->where('source_type', 'ecommerce_catalog')
            ->first();

        $this->assertNotNull($source);
        $this->assertEquals('indexed', $source->status);

        // Chunks count should match mock catalog items (5 items)
        $this->assertEquals(5, KnowledgeChunk::where('knowledge_source_id', $source->id)->count());

        $firstChunk = KnowledgeChunk::where('knowledge_source_id', $source->id)->first();
        $this->assertStringContainsString('Product ID: shopify_prod_1', $firstChunk->content);
        $this->assertStringContainsString('Premium Leather Jacket', $firstChunk->content);
        $this->assertCount(1536, $firstChunk->embedding); // 1536 size mock embedding vector
    }

    /**
     * Test AI Sales Agent responds to product stock availability tool call.
     */
    public function test_ai_agent_product_stock_tool_calling(): void
    {
        // 1. Setup E-commerce Catalog source chunks
        $storeConn = EcommerceConnection::create([
            'tenant_id' => $this->tenant->id,
            'platform' => 'shopify',
            'store_url' => 'myshop.myshopify.com',
            'credentials' => ['access_token' => 'mock_access_token'],
            'status' => 'connected',
        ]);
        SyncEcommerceCatalogJob::dispatchSync($storeConn->id);

        // 2. Setup AI Agent & Active config
        $providerConfig = AIProviderConfig::create([
            'tenant_id' => $this->tenant->id,
            'provider_name' => 'openai',
            'api_key' => 'mock_key',
            'enabled_models' => ['gpt-4o-mini'],
            'default_model' => 'gpt-4o-mini',
            'is_active' => true,
        ]);

        $agent = AiAgent::create([
            'tenant_id' => $this->tenant->id,
            'ai_provider_config_id' => $providerConfig->id,
            'name' => 'Sales Assistant',
            'model' => 'gpt-4o-mini',
            'system_prompt' => 'You are a helpful product sales agent.',
            'status' => 'active',
        ]);
        $agent->channels()->attach($this->connection->id);

        // 3. Create conversation & set agent
        $conversation = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'contact_id' => $this->contact->id,
            'channel_connection_id' => $this->connection->id,
            'external_chat_id' => '10049281982',
            'ai_agent_id' => $agent->id,
            'ai_active' => true,
            'status' => 'open',
        ]);

        $inboundPayload = [
            'update_id' => 128392,
            'message' => [
                'message_id' => 74829,
                'from' => [
                    'id' => 10049281982,
                    'is_bot' => false,
                    'first_name' => 'Jane',
                    'last_name' => 'Cooper',
                ],
                'chat' => [
                    'id' => 10049281982,
                    'first_name' => 'Jane',
                    'last_name' => 'Cooper',
                    'type' => 'private',
                ],
                'date' => 1618033988,
                'text' => 'Hello! Is shopify_prod_2 Wireless headphones in stock?',
            ],
        ];

        // Process message job (triggers agent Response direct execution)
        (new ProcessInboundMessageJob($this->connection->id, $inboundPayload))->handle(
            app(\App\Services\Channels\ChannelManager::class)
        );

        // Verify outbound response generated by agent
        $outbound = Message::where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->orderBy('id', 'desc')
            ->first();

        $this->assertNotNull($outbound);
        $this->assertStringContainsString('Based on the store records', $outbound->body);
        $this->assertStringContainsString('Wireless Noise-Canceling Headphones', $outbound->body);
        $this->assertStringContainsString('Stock Inventory: 42', $outbound->body);
    }

    /**
     * Test AI Sales Agent responds to order status tracking tool call.
     */
    public function test_ai_agent_order_status_tool_calling(): void
    {
        // 1. Create order in connection
        $storeConn = EcommerceConnection::create([
            'tenant_id' => $this->tenant->id,
            'platform' => 'shopify',
            'store_url' => 'myshop.myshopify.com',
            'credentials' => ['access_token' => 'mock_access_token'],
            'status' => 'connected',
        ]);

        $order = EcommerceOrder::create([
            'tenant_id' => $this->tenant->id,
            'ecommerce_connection_id' => $storeConn->id,
            'external_order_id' => '99482',
            'order_number' => '#1025',
            'customer_email' => 'jane@example.com',
            'customer_phone' => '10049281982',
            'total_price' => '150.00',
            'financial_status' => 'paid',
            'fulfillment_status' => 'shipped',
            'tracking_number' => 'USPS-TRK-90042',
            'tracking_url' => 'https://www.usps.com/track?num=90042',
            'items_summary' => [],
            'external_created_at' => now(),
        ]);

        // 2. Setup AI Agent
        $providerConfig = AIProviderConfig::create([
            'tenant_id' => $this->tenant->id,
            'provider_name' => 'openai',
            'api_key' => 'mock_key',
            'enabled_models' => ['gpt-4o-mini'],
            'default_model' => 'gpt-4o-mini',
            'is_active' => true,
        ]);

        $agent = AiAgent::create([
            'tenant_id' => $this->tenant->id,
            'ai_provider_config_id' => $providerConfig->id,
            'name' => 'Order Agent',
            'model' => 'gpt-4o-mini',
            'system_prompt' => 'You track packages.',
            'status' => 'active',
        ]);
        $agent->channels()->attach($this->connection->id);

        // 3. Setup conversation
        $conversation = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'contact_id' => $this->contact->id,
            'channel_connection_id' => $this->connection->id,
            'external_chat_id' => '10049281982',
            'ai_agent_id' => $agent->id,
            'ai_active' => true,
            'status' => 'open',
        ]);

        $inboundPayload = [
            'update_id' => 128392,
            'message' => [
                'message_id' => 74830,
                'from' => [
                    'id' => 10049281982,
                    'is_bot' => false,
                    'first_name' => 'Jane',
                    'last_name' => 'Cooper',
                ],
                'chat' => [
                    'id' => 10049281982,
                    'first_name' => 'Jane',
                    'last_name' => 'Cooper',
                    'type' => 'private',
                ],
                'date' => 1618033989,
                'text' => 'Can you track my order #1025 under email jane@example.com?',
            ],
        ];

        // Process message job
        (new ProcessInboundMessageJob($this->connection->id, $inboundPayload))->handle(
            app(\App\Services\Channels\ChannelManager::class)
        );

        $outbound = Message::where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->orderBy('id', 'desc')
            ->first();

        $this->assertNotNull($outbound);
        $this->assertStringContainsString('Based on the store records', $outbound->body);
        $this->assertStringContainsString('Order Number: #1025', $outbound->body);
        $this->assertStringContainsString('USPS-TRK-90042', $outbound->body);
        $this->assertStringContainsString('shipped', $outbound->body);
    }

    /**
     * Test e-commerce analytics, manual trigger recovery, and customer context retrieval.
     */
    public function test_ecommerce_analytics_and_customer_context_widgets(): void
    {
        $user = \App\Models\User::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@lovelace.io',
            'password' => \Illuminate\Support\Facades\Hash::make('Lovelace123!'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $this->actingAs($user);

        // 1. Seed abandoned cart
        $cart = \App\Models\EcommerceAbandonedCart::create([
            'tenant_id' => $this->tenant->id,
            'contact_id' => $this->contact->id,
            'cart_token' => 'cart_abc_123',
            'checkout_url' => 'https://mockstore.myshopify.com/checkout/123',
            'total_price' => '250.00',
            'items_summary' => [['title' => 'Wireless Noise-Canceling Headphones', 'quantity' => 1]],
            'recovery_status' => 'pending',
        ]);

        // 2. Query e-commerce analytics
        $response = $this->getJson('/api/integrations/ecommerce/analytics');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'metrics' => [
                'carts_abandoned',
                'carts_recovered',
                'recovery_rate',
                'recovered_revenue',
            ],
            'carts',
        ]);

        // Verify metrics
        $response->assertJsonPath('metrics.carts_abandoned', 1);

        // 3. Query customer context widget
        $response = $this->getJson("/api/integrations/ecommerce/customer-context?email={$this->contact->email}&phone={$this->contact->phone}");
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'active_cart',
            'orders',
        ]);

        // 4. Trigger manual recovery
        $response = $this->postJson("/api/integrations/ecommerce/abandoned-carts/{$cart->id}/trigger");
        $response->assertStatus(200);
        $response->assertJsonPath('status', 'recovered');

        // Confirm cart status updated
        $this->assertEquals('recovered', $cart->fresh()->recovery_status);
    }
}
