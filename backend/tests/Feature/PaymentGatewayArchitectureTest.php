<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\User;
use App\Models\Plan;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\Drivers\StripeGatewayDriver;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class PaymentGatewayArchitectureTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;
    protected AdminRole $role;
    protected Tenant $tenant;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'company_name' => 'Test Corp',
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'testuser@example.com',
            'password' => bcrypt('password123'),
        ]);

        $this->role = AdminRole::create([
            'name' => 'Super Admin',
            'permissions' => ['*'],
        ]);

        $this->admin = Admin::create([
            'role_id' => $this->role->id,
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'email' => 'superadmin@whatsomni.io',
            'password' => bcrypt('password123'),
            'status' => 'active',
        ]);
    }

    /**
     * Test saving payment gateway configuration encrypts sensitive keys in database.
     */
    public function test_payment_gateways_config_is_aes_encrypted_in_database()
    {
        $rawSecret = 'sk_test_51MockSecretKeyForStripe12345';
        $rawWebhook = 'whsec_MockWebhookSecret67890';

        $setting = PlatformSetting::create([
            'key' => 'payment_gateways_config',
            'value' => [
                'active_gateway' => 'stripe',
                'gateways' => [
                    'stripe' => [
                        'enabled' => true,
                        'mode' => 'test',
                        'publishable_key' => 'pk_test_12345',
                        'secret_key' => $rawSecret,
                        'webhook_secret' => $rawWebhook,
                    ]
                ]
            ]
        ]);

        // Direct DB inspection (raw attributes in database should NOT contain plaintext secret key)
        $rawDbValue = $setting->getRawOriginal('value');
        $this->assertStringNotContainsString($rawSecret, $rawDbValue);
        $this->assertStringNotContainsString($rawWebhook, $rawDbValue);

        // Accessor inspection (reading $setting->value must cleanly decrypt the values)
        $setting->refresh();
        $this->assertEquals($rawSecret, $setting->value['gateways']['stripe']['secret_key']);
        $this->assertEquals($rawWebhook, $setting->value['gateways']['stripe']['webhook_secret']);
    }

    /**
     * Test PaymentGatewayManager resolves StripeGatewayDriver with encrypted DB credentials.
     */
    public function test_payment_gateway_manager_resolves_stripe_driver()
    {
        PlatformSetting::create([
            'key' => 'payment_gateways_config',
            'value' => [
                'active_gateway' => 'stripe',
                'gateways' => [
                    'stripe' => [
                        'enabled' => true,
                        'mode' => 'test',
                        'publishable_key' => 'pk_test_custom_key',
                        'secret_key' => 'sk_test_custom_secret',
                        'webhook_secret' => 'whsec_custom_webhook',
                    ]
                ]
            ]
        ]);

        /** @var PaymentGatewayManager $manager */
        $manager = app(PaymentGatewayManager::class);
        $driver = $manager->driver('stripe');

        $this->assertInstanceOf(StripeGatewayDriver::class, $driver);
        $this->assertEquals('stripe', $driver->getSlug());
        $this->assertEquals('pk_test_custom_key', $driver->getPublishableKey());
        $this->assertEquals('sk_test_custom_secret', $driver->getSecretKey());
        $this->assertEquals('whsec_custom_webhook', $driver->getWebhookSecret());
        $this->assertTrue($driver->isTestMode());
    }

    /**
     * Test Admin API returns masked credentials and never leaks plaintext secrets.
     */
    public function test_admin_api_returns_masked_credentials()
    {
        $rawSecret = 'sk_test_51ABCDEFGH1234567890';
        PlatformSetting::create([
            'key' => 'payment_gateways_config',
            'value' => [
                'active_gateway' => 'stripe',
                'gateways' => [
                    'stripe' => [
                        'enabled' => true,
                        'mode' => 'test',
                        'publishable_key' => 'pk_test_123',
                        'secret_key' => $rawSecret,
                        'webhook_secret' => 'whsec_987654321',
                    ]
                ]
            ]
        ]);

        $response = $this->actingAs($this->admin, 'admin')->getJson('/api/admin/payment-gateways');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'active_gateway',
            'gateways' => [
                'stripe' => [
                    'slug',
                    'name',
                    'enabled',
                    'is_active',
                    'mode',
                    'publishable_key',
                    'has_secret',
                    'masked_secret',
                    'has_webhook_secret',
                    'masked_webhook_secret',
                    'webhook_url',
                ]
            ]
        ]);

        $data = $response->json();
        $stripe = $data['gateways']['stripe'];

        $this->assertTrue($stripe['has_secret']);
        $this->assertStringContainsString('••••••••', $stripe['masked_secret']);
        $this->assertStringNotContainsString($rawSecret, json_encode($data));
    }

    /**
     * Test Admin API updates gateway configuration and retains existing secret when masked.
     */
    public function test_admin_api_updates_configuration_and_retains_masked_secret()
    {
        $originalSecret = 'sk_test_ORIGINAL_SECRET_KEY_999';
        PlatformSetting::create([
            'key' => 'payment_gateways_config',
            'value' => [
                'active_gateway' => 'stripe',
                'gateways' => [
                    'stripe' => [
                        'enabled' => true,
                        'mode' => 'test',
                        'publishable_key' => 'pk_test_old',
                        'secret_key' => $originalSecret,
                        'webhook_secret' => 'whsec_old',
                    ]
                ]
            ]
        ]);

        // Update with masked secret and new publishable key
        $response = $this->actingAs($this->admin, 'admin')->postJson('/api/admin/payment-gateways', [
            'active_gateway' => 'stripe',
            'gateways' => [
                'stripe' => [
                    'enabled' => true,
                    'mode' => 'live',
                    'publishable_key' => 'pk_live_new_123',
                    'secret_key' => 'sk_test••••••••_999', // masked key passed back
                    'webhook_secret' => 'whsec_new_456',
                ]
            ]
        ]);

        $response->assertStatus(200);

        /** @var PaymentGatewayManager $manager */
        $manager = app(PaymentGatewayManager::class);
        $config = $manager->getGatewayConfig('stripe');

        $this->assertEquals('live', $config['mode']);
        $this->assertEquals('pk_live_new_123', $config['publishable_key']);
        $this->assertEquals($originalSecret, $config['secret_key']); // Original secret retained
        $this->assertEquals('whsec_new_456', $config['webhook_secret']);
    }

    /**
     * Test StripeService backwards compatibility with dynamic manager.
     */
    public function test_stripe_service_delegates_to_driver()
    {
        PlatformSetting::create([
            'key' => 'payment_gateways_config',
            'value' => [
                'active_gateway' => 'stripe',
                'gateways' => [
                    'stripe' => [
                        'enabled' => true,
                        'mode' => 'test',
                        'publishable_key' => 'pk_test_service_key',
                        'secret_key' => 'sk_test_service_secret',
                        'webhook_secret' => 'whsec_service_wh',
                    ]
                ]
            ]
        ]);

        $service = app(StripeService::class);
        $this->assertInstanceOf(StripeService::class, $service);
        $this->assertNotNull($service->getClient());
    }

    /**
     * Test Payment Gateway configuration is strictly database-driven with zero dotenv fallback.
     */
    public function test_payment_gateways_have_no_dotenv_fallback()
    {
        // Ensure no DB config exists
        PlatformSetting::where('key', 'payment_gateways_config')->delete();

        /** @var PaymentGatewayManager $manager */
        $manager = app(PaymentGatewayManager::class);
        $config = $manager->getGatewayConfig('stripe');

        $this->assertFalse($config['enabled']);
        $this->assertEquals('', $config['publishable_key']);
        $this->assertEquals('', $config['secret_key']);
        $this->assertEquals('', $config['webhook_secret']);

        $driver = $manager->driver('stripe');
        $this->assertFalse($driver->isConfigured());
        $this->assertEquals('', $driver->getPublishableKey());
        $this->assertEquals('', $driver->getSecretKey());
        $this->assertEquals('', $driver->getWebhookSecret());
        $this->assertNull($driver->getClient());
    }

    /**
     * Test PaymentGatewayManager resolves RazorpayGatewayDriver with encrypted credentials.
     */
    public function test_payment_gateway_manager_resolves_razorpay_driver()
    {
        PlatformSetting::create([
            'key' => 'payment_gateways_config',
            'value' => [
                'active_gateway' => 'razorpay',
                'gateways' => [
                    'razorpay' => [
                        'enabled' => true,
                        'mode' => 'test',
                        'key_id' => 'rzp_test_mockKey123',
                        'key_secret' => 'rzp_secret_mockSecret456',
                        'webhook_secret' => 'rzp_webhook_secret_789',
                    ]
                ]
            ]
        ]);

        /** @var PaymentGatewayManager $manager */
        $manager = app(PaymentGatewayManager::class);
        $driver = $manager->driver('razorpay');

        $this->assertInstanceOf(\App\Services\Payments\Drivers\RazorpayGatewayDriver::class, $driver);
        $this->assertEquals('razorpay', $driver->getSlug());
        $this->assertEquals('rzp_test_mockKey123', $driver->getKeyId());
        $this->assertEquals('rzp_secret_mockSecret456', $driver->getKeySecret());
        $this->assertEquals('rzp_webhook_secret_789', $driver->getWebhookSecret());
        $this->assertTrue($driver->isTestMode());
        $this->assertTrue($driver->isConfigured());
    }

    /**
     * Test Admin API updates Razorpay gateway configuration and retains masked secret.
     */
    public function test_admin_api_updates_razorpay_configuration()
    {
        $originalSecret = 'rzp_secret_ORIGINAL_TEST_SECRET';
        PlatformSetting::create([
            'key' => 'payment_gateways_config',
            'value' => [
                'active_gateway' => 'stripe',
                'gateways' => [
                    'razorpay' => [
                        'enabled' => true,
                        'mode' => 'test',
                        'key_id' => 'rzp_test_111',
                        'key_secret' => $originalSecret,
                        'webhook_secret' => 'whsec_rzp_111',
                    ]
                ]
            ]
        ]);

        $response = $this->actingAs($this->admin, 'admin')->postJson('/api/admin/payment-gateways', [
            'active_gateway' => 'razorpay',
            'gateways' => [
                'razorpay' => [
                    'enabled' => true,
                    'mode' => 'test',
                    'key_id' => 'rzp_test_222',
                    'key_secret' => 'rzp_secret••••••••',
                    'webhook_secret' => 'whsec_rzp_222',
                ]
            ]
        ]);

        $response->assertStatus(200);

        /** @var PaymentGatewayManager $manager */
        $manager = app(PaymentGatewayManager::class);
        $this->assertEquals('razorpay', $manager->getActiveGateway());

        $config = $manager->getGatewayConfig('razorpay');
        $this->assertEquals('rzp_test_222', $config['key_id']);
        $this->assertEquals($originalSecret, $config['key_secret']); // Retained
        $this->assertEquals('whsec_rzp_222', $config['webhook_secret']);
    }

    /**
     * Test Razorpay webhook signature verification and subscription activation.
     */
    public function test_razorpay_webhook_activates_tenant_subscription()
    {
        $webhookSecret = 'test_webhook_secret_xyz';
        PlatformSetting::create([
            'key' => 'payment_gateways_config',
            'value' => [
                'active_gateway' => 'razorpay',
                'gateways' => [
                    'razorpay' => [
                        'enabled' => true,
                        'mode' => 'test',
                        'key_id' => 'rzp_test_123',
                        'key_secret' => 'rzp_secret_123',
                        'webhook_secret' => $webhookSecret,
                    ]
                ]
            ]
        ]);

        $plan = \App\Models\Plan::create([
            'name' => 'Pro Plan',
            'slug' => 'pro-plan',
            'is_active' => true,
        ]);

        $payload = json_encode([
            'event' => 'subscription.charged',
            'payload' => [
                'subscription' => [
                    'entity' => [
                        'id' => 'sub_rzp_mock_12345',
                        'customer_id' => 'cust_rzp_mock_67890',
                        'plan_id' => 'plan_rzp_mock_111',
                        'notes' => [
                            'tenant_id' => (string) $this->tenant->id,
                            'plan_id' => (string) $plan->id,
                        ],
                    ]
                ]
            ]
        ]);

        $signature = hash_hmac('sha256', $payload, $webhookSecret);

        $response = $this->call(
            'POST',
            '/api/webhooks/billing/razorpay',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_RAZORPAY_SIGNATURE' => $signature,
            ],
            $payload
        );

        $response->assertStatus(200);

        $this->tenant->refresh();
        $this->assertEquals('active', $this->tenant->status);
        $this->assertEquals($plan->id, $this->tenant->plan_id);
        $this->assertEquals('sub_rzp_mock_12345', $this->tenant->razorpay_subscription_id);
        $this->assertEquals('cust_rzp_mock_67890', $this->tenant->razorpay_customer_id);
    }

    public function test_user_can_verify_razorpay_recurring_subscription()
    {
        $keySecret = 'rzp_sec_test_secret_999';

        PlatformSetting::updateOrCreate(
            ['key' => 'payment_gateways_config'],
            [
                'value' => [
                    'active_gateway' => 'razorpay',
                    'gateways' => [
                        'razorpay' => [
                            'enabled' => true,
                            'mode' => 'test',
                            'key_id' => 'rzp_test_123',
                            'key_secret' => $keySecret,
                        ]
                    ]
                ]
            ]
        );

        $plan = Plan::create([
            'name' => 'Scale Pro Plan',
            'slug' => 'scale-pro-plan',
            'is_active' => true,
        ]);

        $paymentId = 'pay_test_987654';
        $subId = 'sub_test_123456';
        $signature = hash_hmac('sha256', $paymentId . '|' . $subId, $keySecret);

        $response = $this->actingAs($this->user)->postJson('/api/billing/razorpay/verify', [
            'razorpay_payment_id' => $paymentId,
            'razorpay_subscription_id' => $subId,
            'razorpay_signature' => $signature,
            'plan_id' => $plan->id,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->tenant->refresh();
        $this->assertEquals('active', $this->tenant->status);
        $this->assertEquals($plan->id, $this->tenant->plan_id);
        $this->assertEquals($subId, $this->tenant->razorpay_subscription_id);
    }
}
