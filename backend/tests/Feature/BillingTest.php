<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Currency;
use App\Services\PlanLimitService;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Tenant $tenant;
    protected Currency $currency;
    protected Plan $freePlan;
    protected Plan $paidPlan;
    protected PlanPrice $usdPrice;

    protected function setUp(): void
    {
        parent::setUp();

        // Create currency
        $this->currency = Currency::create([
            'name' => 'US Dollar',
            'code' => 'USD',
            'symbol' => '$',
            'is_active' => true,
        ]);

        // Create tenant
        $this->tenant = Tenant::create([
            'company_name' => 'Subscription Test Labs',
            'status' => 'trial',
            'currency_id' => $this->currency->id,
        ]);

        // Create user
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Bill',
            'last_name' => 'Gates',
            'email' => 'bill@microsoft.com',
            'password' => Hash::make('Secret123!'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        // Create plans
        $this->freePlan = Plan::create([
            'name' => 'Free Trial Tier',
            'max_team_members' => 3,
            'max_campaigns' => 2,
            'max_integrations' => 1,
            'own_crm_access' => false,
            'max_channels' => 2,
            'max_automations' => 2,
            'is_active' => true,
        ]);

        $this->paidPlan = Plan::create([
            'name' => 'Enterprise Pro',
            'max_team_members' => 10,
            'max_campaigns' => 10,
            'max_integrations' => 5,
            'own_crm_access' => true,
            'max_channels' => 10,
            'max_automations' => 10,
            'is_active' => true,
        ]);

        // Create plan prices
        $this->usdPrice = PlanPrice::create([
            'plan_id' => $this->paidPlan->id,
            'currency_id' => $this->currency->id,
            'amount' => 9900,
            'stripe_price_id' => 'price_enterprise_mock_123',
            'billing_interval' => 'month',
        ]);
    }

    /**
     * Test getting current subscription status.
     */
    public function test_user_can_get_current_subscription(): void
    {
        $this->actingAs($this->user);

        $response = $this->getJson('/api/billing/subscription');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'trial')
            ->assertJsonPath('plan', null);
    }

    /**
     * Test list currency-scoped plans.
     */
    public function test_user_can_view_currency_scoped_pricing_plans(): void
    {
        $this->actingAs($this->user);

        $response = $this->getJson('/api/billing/plans');

        $response->assertStatus(200)
            ->assertJsonStructure(['plans'])
            ->assertJsonCount(1, 'plans')
            ->assertJsonPath('plans.0.name', 'Enterprise Pro')
            ->assertJsonPath('plans.0.prices.0.currency_code', 'USD')
            ->assertJsonPath('plans.0.prices.0.amount', 9900);
    }

    /**
     * Test generating checkout session.
     */
    public function test_user_can_initialize_stripe_checkout(): void
    {
        $this->actingAs($this->user);

        // Mock StripeService calls
        $mock = $this->mock(StripeService::class);
        $mock->shouldReceive('createCheckoutSession')
            ->once()
            ->with(\Mockery::any(), 'price_enterprise_mock_123', \Mockery::any(), \Mockery::any())
            ->andReturn('https://checkout.stripe.com/pay/session_mock_123');

        $response = $this->postJson('/api/billing/checkout', [
            'stripe_price_id' => 'price_enterprise_mock_123',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('checkout_url', 'https://checkout.stripe.com/pay/session_mock_123');
    }

    /**
     * Test generating portal session.
     */
    public function test_user_can_initialize_stripe_portal(): void
    {
        $this->actingAs($this->user);

        $this->tenant->update(['stripe_customer_id' => 'cus_mock_123']);

        $mock = $this->mock(StripeService::class);
        $mock->shouldReceive('createPortalSession')
            ->once()
            ->with(\Mockery::any(), \Mockery::any())
            ->andReturn('https://billing.stripe.com/p/portal_mock_123');

        $response = $this->postJson('/api/billing/portal');

        $response->assertStatus(200)
            ->assertJsonPath('portal_url', 'https://billing.stripe.com/p/portal_mock_123');
    }

    /**
     * Test webhook event checkout session complete updates tenant plan and status.
     */
    public function test_webhook_checkout_session_completed_configures_tenant(): void
    {
        $payload = [
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'customer' => 'cus_customer_xyz',
                    'subscription' => 'sub_subscription_xyz',
                    'metadata' => [
                        'tenant_id' => $this->tenant->id,
                    ]
                ]
            ]
        ];

        // Dispatch post
        $response = $this->postJson('/api/billing/webhook', $payload);

        $response->assertStatus(200);

        $this->tenant->refresh();
        $this->assertEquals('sub_subscription_xyz', $this->tenant->stripe_subscription_id);
        $this->assertEquals('cus_customer_xyz', $this->tenant->stripe_customer_id);
        $this->assertEquals('active', $this->tenant->status);
    }

    /**
     * Test webhook event customer subscription deleted suspends tenant.
     */
    public function test_webhook_subscription_deleted_suspends_tenant(): void
    {
        $this->tenant->update([
            'stripe_subscription_id' => 'sub_cancel_xyz',
            'plan_id' => $this->paidPlan->id,
            'status' => 'active',
        ]);

        $payload = [
            'type' => 'customer.subscription.deleted',
            'data' => [
                'object' => [
                    'id' => 'sub_cancel_xyz',
                ]
            ]
        ];

        $response = $this->postJson('/api/billing/webhook', $payload);

        $response->assertStatus(200);

        $this->tenant->refresh();
        $this->assertNull($this->tenant->stripe_subscription_id);
        $this->assertNull($this->tenant->plan_id);
        $this->assertEquals('suspended', $this->tenant->status);
    }

    /**
     * Test limit enforcement logic.
     */
    public function test_plan_limit_service_enforces_limits(): void
    {
        $limitService = new PlanLimitService();

        // 1. Under free trial limits
        $this->tenant->update(['plan_id' => null, 'status' => 'trial']);
        $this->tenant->refresh();
        $this->assertTrue($limitService->canUseFeature($this->tenant, 'team_members', 2));
        $this->assertFalse($limitService->canUseFeature($this->tenant, 'team_members', 3));
        $this->assertFalse($limitService->canUseFeature($this->tenant, 'crm_access'));

        // 2. Paid plan active limits
        $this->tenant->update(['plan_id' => $this->paidPlan->id, 'status' => 'active']);
        $this->tenant->refresh();
        $this->assertTrue($limitService->canUseFeature($this->tenant, 'team_members', 8));
        $this->assertFalse($limitService->canUseFeature($this->tenant, 'team_members', 11));
        $this->assertTrue($limitService->canUseFeature($this->tenant, 'crm_access'));

        // 3. Suspended account block
        $this->tenant->update(['status' => 'suspended']);
        $this->tenant->refresh();
        $this->assertFalse($limitService->canUseFeature($this->tenant, 'team_members', 1));
    }
}
