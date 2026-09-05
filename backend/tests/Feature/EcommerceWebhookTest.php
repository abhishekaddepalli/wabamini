<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\EcommerceAbandonedCart;
use App\Models\EcommerceConnection;
use App\Models\EcommerceOrder;
use App\Models\Tenant;
use App\Jobs\SendAbandonedCartRecoveryJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EcommerceWebhookTest extends TestCase
{
    use RefreshDatabase;

    private $tenant;
    private $connection;
    private $secret = 'shpat_my_webhook_secret_key_123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'company_name' => 'Test Tenant',
        ]);

        $this->connection = EcommerceConnection::create([
            'tenant_id' => $this->tenant->id,
            'platform' => 'shopify',
            'store_url' => 'myteststore.myshopify.com',
            'credentials' => ['access_token' => 'shpat_xxxxxx'],
            'status' => 'active',
            'webhook_secret' => $this->secret,
        ]);
        $this->user = \App\Models\User::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Agent',
            'last_name' => 'Test',
            'email' => 'agent@test.io',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'status' => 'active',
        ]);
    }

    /**
     * Test Shopify checkout webhook validation & normalization.
     */
    public function test_shopify_checkout_webhook_is_processed()
    {
        $payload = [
            'id' => 123456,
            'token' => 'chk_token_789',
            'abandoned_checkout_url' => 'https://myteststore.myshopify.com/checkouts/chk_token_789',
            'total_price' => '150.00',
            'email' => 'customer@example.com',
            'customer' => [
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'email' => 'customer@example.com',
                'phone' => '+15555551234',
            ],
            'line_items' => [
                [
                    'title' => 'Product A',
                    'quantity' => 2,
                    'price' => '75.00',
                ]
            ],
        ];

        $rawBody = json_encode($payload);
        $hmac = base64_encode(hash_hmac('sha256', $rawBody, $this->secret, true));

        $response = $this->withHeaders([
            'X-Shopify-Shop-Domain' => 'myteststore.myshopify.com',
            'X-Shopify-Hmac-SHA256' => $hmac,
            'X-Shopify-Topic' => 'checkouts/create',
        ])->postJson('/api/webhooks/ecommerce/shopify', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'processed');

        // Check if Contact was created/mapped
        $contact = Contact::where('tenant_id', $this->tenant->id)->where('email', 'customer@example.com')->first();
        $this->assertNotNull($contact);
        $this->assertEquals('Jane', $contact->first_name);

        // Check if Abandoned Cart was logged
        $cart = EcommerceAbandonedCart::where('tenant_id', $this->tenant->id)->where('cart_token', 'chk_token_789')->first();
        $this->assertNotNull($cart);
        $this->assertEquals($contact->id, $cart->contact_id);
        $this->assertEquals('150.00', $cart->total_price);
    }

    /**
     * Test Shopify order placement resolves/recovers abandoned cart.
     */
    public function test_shopify_order_webhook_recovers_cart()
    {
        // 1. Create a pending abandoned cart
        $contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'customer@example.com',
        ]);

        $cart = EcommerceAbandonedCart::create([
            'tenant_id' => $this->tenant->id,
            'contact_id' => $contact->id,
            'cart_token' => 'chk_token_789',
            'checkout_url' => 'https://myteststore.myshopify.com/checkouts/chk_token_789',
            'total_price' => '150.00',
            'items_summary' => [],
            'recovery_status' => 'pending',
        ]);

        // 2. Mock order placement payload
        $orderPayload = [
            'id' => 987654,
            'order_number' => '1001',
            'name' => '#1001',
            'checkout_token' => 'chk_token_789',
            'total_price' => '150.00',
            'financial_status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'customer' => [
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'email' => 'customer@example.com',
            ],
            'line_items' => [
                [
                    'title' => 'Product A',
                    'quantity' => 2,
                    'price' => '75.00',
                ]
            ],
            'created_at' => now()->toIso8601String(),
        ];

        $rawBody = json_encode($orderPayload);
        $hmac = base64_encode(hash_hmac('sha256', $rawBody, $this->secret, true));

        $response = $this->withHeaders([
            'X-Shopify-Shop-Domain' => 'myteststore.myshopify.com',
            'X-Shopify-Hmac-SHA256' => $hmac,
            'X-Shopify-Topic' => 'orders/create',
        ])->postJson('/api/webhooks/ecommerce/shopify', $orderPayload);

        $response->assertStatus(200);

        // Check if Order was logged
        $order = EcommerceOrder::where('tenant_id', $this->tenant->id)->where('external_order_id', '987654')->first();
        $this->assertNotNull($order);
        $this->assertEquals('paid', $order->financial_status);

        // Check if cart status flipped to recovered
        $cart->refresh();
        $this->assertEquals('recovered', $cart->recovery_status);
        $this->assertNotNull($cart->recovered_at);
    }

    /**
     * Test getting e-commerce connections list.
     */
    public function test_user_can_get_ecommerce_status()
    {
        $response = $this->actingAs($this->user)->getJson('/api/integrations/ecommerce/status');

        $response->assertStatus(200);
        $response->assertJsonCount(1);
        $response->assertJsonFragment([
            'platform' => 'shopify',
            'store_url' => 'myteststore.myshopify.com',
        ]);
    }

    /**
     * Test establishing store connection.
     */
    public function test_user_can_connect_ecommerce_store()
    {
        $response = $this->actingAs($this->user)->postJson('/api/integrations/ecommerce/connect', [
            'platform' => 'woocommerce',
            'store_url' => 'https://mywoostore.com/',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('connection.store_url', 'https://mywoostore.com');

        $this->assertDatabaseHas('ecommerce_connections', [
            'tenant_id' => $this->tenant->id,
            'platform' => 'woocommerce',
            'store_url' => 'https://mywoostore.com',
        ]);
    }

    /**
     * Test manual sync triggers updates.
     */
    public function test_user_can_trigger_manual_sync()
    {
        $response = $this->actingAs($this->user)->postJson('/api/integrations/ecommerce/sync', [
            'id' => $this->connection->id,
        ]);

        $response->assertStatus(200);
        $this->connection->refresh();
        $this->assertNotNull($this->connection->last_synced_at);
    }

    /**
     * Test disconnecting e-commerce integration.
     */
    public function test_user_can_disconnect_ecommerce_store()
    {
        $response = $this->actingAs($this->user)->deleteJson('/api/integrations/ecommerce/disconnect', [
            'id' => $this->connection->id,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('ecommerce_connections', [
            'id' => $this->connection->id,
        ]);
    }

    /**
     * Test Shopify checkout webhook dispatches the recovery job.
     */
    public function test_shopify_checkout_dispatches_recovery_job()
    {
        Queue::fake();

        $payload = [
            'id' => 123456,
            'token' => 'chk_token_789',
            'abandoned_checkout_url' => 'https://myteststore.myshopify.com/checkouts/chk_token_789',
            'total_price' => '150.00',
            'email' => 'customer@example.com',
            'customer' => [
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'email' => 'customer@example.com',
                'phone' => '+15555551234',
            ],
            'line_items' => [
                [
                    'title' => 'Product A',
                    'quantity' => 2,
                    'price' => '75.00',
                ]
            ],
        ];

        $rawBody = json_encode($payload);
        $hmac = base64_encode(hash_hmac('sha256', $rawBody, $this->secret, true));

        $response = $this->withHeaders([
            'X-Shopify-Shop-Domain' => 'myteststore.myshopify.com',
            'X-Shopify-Hmac-SHA256' => $hmac,
            'X-Shopify-Topic' => 'checkouts/create',
        ])->postJson('/api/webhooks/ecommerce/shopify', $payload);

        $response->assertStatus(200);

        Queue::assertPushed(SendAbandonedCartRecoveryJob::class, function ($job) {
            return $job->delay === 30 * 60 || ($job->delay instanceof \Carbon\CarbonInterval && $job->delay->totalSeconds === 1800) || ($job->delay instanceof \DateTimeInterface);
        });
    }

    /**
     * Test fetching coupons list.
     */
    public function test_user_can_fetch_ecommerce_coupons()
    {
        $response = $this->actingAs($this->user)->getJson('/api/integrations/ecommerce/coupons');

        $response->assertStatus(200)
            ->assertJson(['WELCOME10', 'FREESHIP', 'VIP20', 'WINTER30']);
    }
}
