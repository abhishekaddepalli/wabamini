<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BillingController extends Controller
{
    protected StripeService $stripeService;
    protected PaymentGatewayManager $paymentManager;

    public function __construct(StripeService $stripeService, PaymentGatewayManager $paymentManager)
    {
        $this->stripeService = $stripeService;
        $this->paymentManager = $paymentManager;
    }

    /**
     * Get the tenant's current subscription details, status, and caps.
     */
    public function subscription(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        
        if (!$tenant) {
            return response()->json(['message' => 'Workspace context not resolved.'], 404);
        }

        // 1. If Paystack callback parameters are provided, verify with Paystack driver
        $paystackRef = $request->query('paystack_reference') ?? ($request->query('reference') ?? $request->query('trxref'));
        if (!empty($paystackRef)) {
            try {
                $paystackDriver = $this->paymentManager->driver('paystack');
                $verifyRes = $paystackDriver->verifyTransaction($paystackRef);
                if (!empty($verifyRes['status']) && ($verifyRes['data']['status'] ?? '') === 'success') {
                    $txData = $verifyRes['data'];
                    $subCode = $txData['subscription']['subscription_code'] ?? ($txData['subscription_code'] ?? null);
                    $custCode = $txData['customer']['customer_code'] ?? null;
                    $emailToken = $txData['subscription']['email_token'] ?? ($txData['email_token'] ?? null);
                    $metaPlanId = $txData['metadata']['plan_id'] ?? null;
                    $planCode = $txData['plan']['plan_code'] ?? ($txData['plan'] ?? null);

                    if (empty($subCode) && !empty($custCode) && !empty($planCode)) {
                        try {
                            $authCode = $txData['authorization']['authorization_code'] ?? null;
                            $subData = $paystackDriver->createSubscription($custCode, $planCode, $authCode);
                            if ($subData) {
                                $subCode = $subData['subscription_code'] ?? null;
                                $emailToken = $subData['email_token'] ?? null;
                            }
                        } catch (\Exception $e) {
                            Log::warning('Auto-provisioning Paystack subscription error: ' . $e->getMessage());
                        }
                    }

                    $updateData = [
                        'status' => 'active',
                        'onboarding_step' => 'complete',
                    ];
                    if ($subCode) $updateData['paystack_subscription_id'] = $subCode;
                    if ($custCode) $updateData['paystack_customer_id'] = $custCode;
                    if ($emailToken) $updateData['paystack_email_token'] = $emailToken;
                    if (!empty($metaPlanId)) {
                        $updateData['plan_id'] = (int) $metaPlanId;
                    } elseif ($planCode) {
                        $priceObj = PlanPrice::where('paystack_plan_code', $planCode)->first();
                        if ($priceObj) {
                            $updateData['plan_id'] = $priceObj->plan_id;
                        }
                    }
                    $tenant->update($updateData);
                    Log::info("Paystack transaction {$paystackRef} verified and activated for Tenant {$tenant->id}");
                }
            } catch (\Exception $e) {
                Log::warning("Could not verify Paystack reference on subscription fetch: " . $e->getMessage());
            }
        }

        // 2. If Flutterwave callback parameters are provided, verify with Flutterwave driver
        $flwTxId = $request->query('transaction_id') ?? ($request->query('tx_ref') ?? $request->query('flutterwave_reference'));
        $flwStatus = $request->query('status');
        if (!empty($flwTxId) && ($flwStatus === 'successful' || empty($flwStatus))) {
            try {
                $flwDriver = $this->paymentManager->driver('flutterwave');
                $verifyRes = $flwDriver->verifyTransaction($flwTxId);
                if (!empty($verifyRes['status']) && ($verifyRes['status'] === 'success' || ($verifyRes['data']['status'] ?? '') === 'successful')) {
                    $txData = $verifyRes['data'] ?? [];
                    $customerId = isset($txData['customer']['id']) ? (string) $txData['customer']['id'] : null;
                    $planId = $txData['payment_plan'] ?? ($txData['plan'] ?? null);
                    $subId = isset($txData['subscription_id']) ? (string)$txData['subscription_id'] : ($planId ? (string)$planId : null);
                    $metaPlanId = $txData['meta']['plan_id'] ?? null;

                    $updateData = [
                        'status' => 'active',
                        'onboarding_step' => 'complete',
                    ];
                    if ($customerId) $updateData['flutterwave_customer_id'] = $customerId;
                    if ($subId) $updateData['flutterwave_subscription_id'] = $subId;
                    if (!empty($metaPlanId)) {
                        $updateData['plan_id'] = (int) $metaPlanId;
                    } elseif ($planId) {
                        $priceObj = PlanPrice::where('flutterwave_plan_id', (string)$planId)->first();
                        if ($priceObj) {
                            $updateData['plan_id'] = $priceObj->plan_id;
                        }
                    }
                    $tenant->update($updateData);
                    Log::info("Flutterwave transaction {$flwTxId} verified and activated for Tenant {$tenant->id}");
                }
            } catch (\Exception $e) {
                Log::warning("Could not verify Flutterwave transaction on subscription fetch: " . $e->getMessage());
            }
        }

        // 3. If Razorpay callback parameters are provided, verify with Razorpay driver
        $razorpaySubId = $request->query('razorpay_subscription_id') ?? ($request->query('razorpay_payment_id') ? $request->query('razorpay_payment_link_id') : null);
        $rawSessionId = $request->query('session_id');

        if (!empty($razorpaySubId) || (!empty($rawSessionId) && (str_starts_with($rawSessionId, 'sub_') || str_starts_with($rawSessionId, 'plink_'))) || $request->has('razorpay_payment_id')) {
            $lookupId = $razorpaySubId ?: $rawSessionId;
            try {
                $razorpayDriver = $this->paymentManager->driver('razorpay');
                $rzpClient = $razorpayDriver->getClient();
                if ($rzpClient) {
                    $subEntity = null;
                    if (!empty($lookupId) && str_starts_with($lookupId, 'sub_')) {
                        $subEntity = $rzpClient->subscription->fetch($lookupId);
                    }

                    if ($subEntity) {
                        $updateData = [
                            'status' => 'active',
                            'onboarding_step' => 'complete',
                            'razorpay_subscription_id' => $subEntity->id,
                        ];
                        if (!empty($subEntity->customer_id)) {
                            $updateData['razorpay_customer_id'] = $subEntity->customer_id;
                        }
                        if (!empty($subEntity->notes['plan_id'])) {
                            $updateData['plan_id'] = (int) $subEntity->notes['plan_id'];
                        } elseif (!empty($subEntity->plan_id)) {
                            $priceObj = PlanPrice::where('razorpay_plan_id', $subEntity->plan_id)->first();
                            if ($priceObj) {
                                $updateData['plan_id'] = $priceObj->plan_id;
                            }
                        }

                        $tenant->update($updateData);
                        Log::info("Razorpay subscription {$lookupId} verified and activated for Tenant {$tenant->id}");
                    }

                    // Check Payment Link if returned from Payment Link
                    $paymentLinkId = $request->query('razorpay_payment_link_id') ?: (!empty($lookupId) && str_starts_with($lookupId, 'plink_') ? $lookupId : null);
                    if ($paymentLinkId || $request->query('razorpay_payment_link_status') === 'paid') {
                        try {
                            $plinkEntity = $paymentLinkId ? $rzpClient->paymentLink->fetch($paymentLinkId) : null;
                            if (($plinkEntity && $plinkEntity->status === 'paid') || $request->query('razorpay_payment_link_status') === 'paid') {
                                $updateData = [
                                    'status' => 'active',
                                    'onboarding_step' => 'complete',
                                ];
                                if ($plinkEntity && !empty($plinkEntity->notes['plan_id'])) {
                                    $updateData['plan_id'] = (int) $plinkEntity->notes['plan_id'];
                                }
                                if ($plinkEntity && !empty($plinkEntity->notes['subscription_id'])) {
                                    $updateData['razorpay_subscription_id'] = $plinkEntity->notes['subscription_id'];
                                }
                                $tenant->update($updateData);
                                Log::info("Razorpay payment link {$paymentLinkId} verified and activated for Tenant {$tenant->id}");
                            }
                        } catch (\Exception $plEx) {
                            Log::warning("Could not verify Razorpay payment link: " . $plEx->getMessage());
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::warning("Could not verify Razorpay session on subscription fetch: " . $e->getMessage());
            }
        }

        // 3. If Stripe session_id is provided, verify and update local subscription status
        if ($request->has('session_id') && !empty($request->query('session_id')) && str_starts_with($request->query('session_id'), 'cs_')) {
            $sessionId = $request->query('session_id');
            $stripe = $this->stripeService->getClient();
            if ($stripe) {
                try {
                    $session = $stripe->checkout->sessions->retrieve($sessionId, [
                        'expand' => ['subscription', 'line_items']
                    ]);
                    
                    if ($session && ($session->status === 'complete' || $session->payment_status === 'paid' || $session->payment_status === 'no_payment_required')) {
                        $subscriptionId = is_string($session->subscription) ? $session->subscription : ($session->subscription?->id ?? null);
                        $customerId = is_string($session->customer) ? $session->customer : ($session->customer?->id ?? null);
                        
                        $updateData = [
                            'status' => 'active',
                            'onboarding_step' => 'complete',
                        ];
                        
                        if ($subscriptionId) {
                            $updateData['stripe_subscription_id'] = $subscriptionId;
                        }
                        if ($customerId) {
                            $updateData['stripe_customer_id'] = $customerId;
                        }

                        // 1. Resolve from session metadata
                        if (!empty($session->metadata->plan_id)) {
                            $updateData['plan_id'] = (int) $session->metadata->plan_id;
                        }

                        // 2. Resolve from subscription object
                        if (empty($updateData['plan_id']) && $session->subscription) {
                            $subObj = is_object($session->subscription) ? $session->subscription : $stripe->subscriptions->retrieve($subscriptionId);
                            if (!empty($subObj->metadata->plan_id)) {
                                $updateData['plan_id'] = (int) $subObj->metadata->plan_id;
                            } else {
                                $priceId = $subObj->items->data[0]->price->id ?? null;
                                if ($priceId) {
                                    $priceObj = PlanPrice::where('stripe_price_id', $priceId)->first();
                                    if ($priceObj) {
                                        $updateData['plan_id'] = $priceObj->plan_id;
                                    }
                                }
                            }
                        }

                        // 3. Fallback to line_items
                        if (empty($updateData['plan_id']) && !empty($session->line_items->data[0]->price->id)) {
                            $priceId = $session->line_items->data[0]->price->id;
                            $priceObj = PlanPrice::where('stripe_price_id', $priceId)->first();
                            if ($priceObj) {
                                $updateData['plan_id'] = $priceObj->plan_id;
                            }
                        }
                        
                        $tenant->update($updateData);
                        Log::info("Session {$sessionId} successfully verified and activated subscription for Tenant {$tenant->id}, Plan ID: " . ($updateData['plan_id'] ?? 'none'));
                    }
                } catch (\Exception $e) {
                    Log::warning("Could not verify session_id on subscription fetch: " . $e->getMessage());
                }
            }
        }

        // Resolve platform default base currency for billing subscriptions strictly from Admin panel settings
        $platformCurrency = $this->getPlatformDefaultCurrency();
        $platformCurrencyCode = $platformCurrency->code;

        $tenant->load(['plan']);

        // Find the active price for the plan in the platform base currency
        $price = null;
        if ($tenant->plan) {
            $price = PlanPrice::where('plan_id', $tenant->plan_id)
                ->where('currency_id', $platformCurrency->id)
                ->first();

            if (!$price) {
                $price = PlanPrice::where('plan_id', $tenant->plan_id)->first();
            }
        }

        return response()->json([
            'status' => $tenant->status,
            'stripe_subscription_id' => $tenant->stripe_subscription_id,
            'stripe_customer_id' => $tenant->stripe_customer_id,
            'razorpay_subscription_id' => $tenant->razorpay_subscription_id,
            'razorpay_customer_id' => $tenant->razorpay_customer_id,
            'paystack_subscription_id' => $tenant->paystack_subscription_id,
            'paystack_customer_id' => $tenant->paystack_customer_id,
            'flutterwave_subscription_id' => $tenant->flutterwave_subscription_id,
            'flutterwave_customer_id' => $tenant->flutterwave_customer_id,
            'active_gateway' => $this->paymentManager->getActiveGateway(),
            'plan' => $tenant->plan ? [
                'id' => $tenant->plan->id,
                'name' => $tenant->plan->name,
                'description' => $tenant->plan->description,
                'max_team_members' => $tenant->plan->max_team_members,
                'max_campaigns' => $tenant->plan->max_campaigns,
                'max_integrations' => $tenant->plan->max_integrations,
                'allowed_integrations' => $tenant->plan->allowed_integrations ?? [],
                'own_crm_access' => (bool) $tenant->plan->own_crm_access,
                'max_channels' => $tenant->plan->max_channels,
                'allowed_channels' => $tenant->plan->allowed_channels ?? [],
                'max_automations' => $tenant->plan->max_automations,
                'flow_credits' => $tenant->plan->flow_credits ?? 50,
                'monthly_ai_tokens' => $tenant->plan->monthly_ai_tokens ?? 100000,
                'has_flow_templates' => (bool) $tenant->plan->has_flow_templates,
            ] : null,
            'flow_credits' => [
                'max_credits' => $tenant->plan ? ($tenant->plan->flow_credits ?? 50) : 5,
                'used_credits' => $tenant->used_flow_credits ?? 0,
                'remaining_credits' => max(0, ($tenant->plan ? ($tenant->plan->flow_credits ?? 50) : 5) - ($tenant->used_flow_credits ?? 0)),
            ],
            'price' => $price ? [
                'amount' => $price->amount,
                'currency' => $price->currency ? $price->currency->code : $platformCurrency->code,
                'symbol' => $price->currency ? $price->currency->symbol : $platformCurrency->symbol,
                'interval' => $price->billing_interval,
            ] : null,
            'platform_currency' => [
                'code' => $platformCurrency->code,
                'symbol' => $platformCurrency->symbol,
            ],
            'ai_operational_model' => app(\App\Services\AIProviderService::class)->getAiOperationalModel(),
        ]);
    }

    /**
     * Get all active subscription plans matching the platform base currency.
     */
    public function plans(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        
        if (!$tenant) {
            return response()->json(['message' => 'Workspace context not resolved.'], 404);
        }

        $platformCurrency = $this->getPlatformDefaultCurrency();

        $plans = Plan::where('is_active', true)->orderBy('sort_order')->get();
        $payload = [];

        foreach ($plans as $plan) {
            // Find prices in matching platform base currency
            $prices = PlanPrice::where('plan_id', $plan->id)
                ->where('currency_id', $platformCurrency->id)
                ->get();

            // Fallback if no matching currency prices exist yet for this plan
            if ($prices->isEmpty()) {
                $prices = PlanPrice::where('plan_id', $plan->id)->get();
            }

            if ($prices->isEmpty()) {
                continue;
            }

            $pricePayload = [];
            foreach ($prices as $price) {
                if (empty($price->stripe_price_id)) {
                    $generatedId = "price_plan_{$price->plan_id}_{$price->id}";
                    $price->update(['stripe_price_id' => $generatedId]);
                    $price->stripe_price_id = $generatedId;
                }

                $pricePayload[] = [
                    'id' => $price->id,
                    'stripe_price_id' => $price->stripe_price_id,
                    'amount' => $price->amount,
                    'interval' => $price->billing_interval,
                    'currency_code' => $price->currency ? $price->currency->code : $platformCurrency->code,
                    'currency_symbol' => $price->currency ? $price->currency->symbol : $platformCurrency->symbol,
                ];
            }

            $payload[] = [
                'id' => $plan->id,
                'name' => $plan->name,
                'description' => $plan->description,
                'trial_days' => $plan->trial_days ?? 0,
                'max_team_members' => $plan->max_team_members,
                'max_campaigns' => $plan->max_campaigns,
                'max_integrations' => $plan->max_integrations,
                'allowed_integrations' => $plan->allowed_integrations ?? [],
                'own_crm_access' => (bool) $plan->own_crm_access,
                'max_channels' => $plan->max_channels,
                'allowed_channels' => $plan->allowed_channels ?? [],
                'max_automations' => $plan->max_automations,
                'flow_credits' => $plan->flow_credits ?? 50,
                'monthly_ai_tokens' => $plan->monthly_ai_tokens ?? 100000,
                'has_flow_templates' => (bool) $plan->has_flow_templates,
                'prices' => $pricePayload,
            ];
        }

        return response()->json([
            'plans' => $payload,
            'ai_operational_model' => app(\App\Services\AIProviderService::class)->getAiOperationalModel(),
        ]);
    }

    /**
     * Get all active subscription plans for public landing pages (does not require authentication).
     */
    public function publicPlans(Request $request): JsonResponse
    {
        $platformCurrency = $this->getPlatformDefaultCurrency();

        $plans = Plan::where('is_active', true)->orderBy('sort_order')->get();
        $payload = [];

        foreach ($plans as $plan) {
            $prices = PlanPrice::where('plan_id', $plan->id)
                ->where('currency_id', $platformCurrency->id)
                ->get();

            if ($prices->isEmpty()) {
                $prices = PlanPrice::where('plan_id', $plan->id)->get();
            }

            if ($prices->isEmpty()) {
                continue;
            }

            $pricePayload = [];
            foreach ($prices as $price) {
                if (empty($price->stripe_price_id)) {
                    $generatedId = "price_plan_{$price->plan_id}_{$price->id}";
                    $price->update(['stripe_price_id' => $generatedId]);
                    $price->stripe_price_id = $generatedId;
                }

                $pricePayload[] = [
                    'id' => $price->id,
                    'stripe_price_id' => $price->stripe_price_id,
                    'amount' => $price->amount,
                    'interval' => $price->billing_interval,
                    'currency_code' => $price->currency ? $price->currency->code : $platformCurrency->code,
                    'currency_symbol' => $price->currency ? $price->currency->symbol : $platformCurrency->symbol,
                ];
            }

            $payload[] = [
                'id' => $plan->id,
                'name' => $plan->name,
                'description' => $plan->description,
                'trial_days' => $plan->trial_days ?? 0,
                'max_team_members' => $plan->max_team_members,
                'max_campaigns' => $plan->max_campaigns,
                'max_integrations' => $plan->max_integrations,
                'allowed_integrations' => $plan->allowed_integrations ?? [],
                'own_crm_access' => (bool) $plan->own_crm_access,
                'max_channels' => $plan->max_channels,
                'allowed_channels' => $plan->allowed_channels ?? [],
                'max_automations' => $plan->max_automations,
                'flow_credits' => $plan->flow_credits ?? 50,
                'monthly_ai_tokens' => $plan->monthly_ai_tokens ?? 100000,
                'has_flow_templates' => (bool) $plan->has_flow_templates,
                'prices' => $pricePayload,
            ];
        }

        return response()->json([
            'plans' => $payload,
            'ai_operational_model' => app(\App\Services\AIProviderService::class)->getAiOperationalModel(),
        ]);
    }


    /**
     * Start a Stripe Checkout Session to subscribe to a price tier.
     */
    public function checkout(Request $request): JsonResponse
    {
        $request->validate([
            'stripe_price_id' => ['nullable', 'string'],
            'price_id' => ['nullable'],
            'plan_id' => ['nullable'],
        ]);

        $tenant = $request->user()->tenant;
        if (!$tenant) {
            return response()->json(['message' => 'Workspace context not resolved.'], 404);
        }

        $price = null;
        if (!empty($request->stripe_price_id)) {
            $price = PlanPrice::where('stripe_price_id', $request->stripe_price_id)->first();
        }

        if (!$price && !empty($request->price_id)) {
            $price = PlanPrice::find($request->price_id);
        }

        if (!$price && !empty($request->plan_id)) {
            $price = PlanPrice::where('plan_id', $request->plan_id)->first();
        }

        if (!$price) {
            return response()->json(['message' => 'Selected plan price tier is invalid.'], 422);
        }

        $activeGateway = $this->paymentManager->getActiveGateway();
        $gatewayDriver = $this->paymentManager->driver($activeGateway);

        $gatewayPriceId = match ($activeGateway) {
            'razorpay' => ($price->razorpay_plan_id ?: ($price->stripe_price_id ?: (string)$price->id)),
            'paystack' => ($price->paystack_plan_code ?: ($price->stripe_price_id ?: (string)$price->id)),
            default => ($price->stripe_price_id ?: (string)$price->id),
        };

        if (empty($price->stripe_price_id)) {
            $generatedId = "price_plan_{$price->plan_id}_{$price->id}";
            $price->update(['stripe_price_id' => $generatedId]);
            $price->stripe_price_id = $generatedId;
        }

        // Setup base callback URLs dynamically based on onboarding vs dashboard settings state
        $baseUrl = \App\Providers\AppServiceProvider::getFrontendUrl($request->user());
        $origin = $request->header('Origin') ?: $request->header('Referer');
        if ($origin) {
            $parsed = parse_url($origin);
            if (!empty($parsed['scheme']) && !empty($parsed['host'])) {
                $baseUrl = $parsed['scheme'] . '://' . $parsed['host'] . (!empty($parsed['port']) ? ':' . $parsed['port'] : '');
            }
        }

        // Determine destination page based on current context
        $refererPath = $request->header('Referer') ? (parse_url($request->header('Referer'), PHP_URL_PATH) ?? '') : '';
        $targetRoute = '/settings/billing';
        if (str_contains($refererPath, '/billing/plans') || empty($tenant->plan_id)) {
            $targetRoute = '/billing/plans';
        }

        $successUrl = ($activeGateway === 'paystack')
            ? "{$baseUrl}{$targetRoute}?paystack_reference={reference}"
            : "{$baseUrl}{$targetRoute}?session_id={CHECKOUT_SESSION_ID}";
        $cancelUrl = "{$baseUrl}{$targetRoute}?checkout=cancelled";

        try {
            // If the selected plan price is $0 (Free plan), update DB immediately and cancel active subscriptions if any
            if ($price->amount === 0) {
                try {
                    $gatewayDriver->cancelSubscription($tenant);
                } catch (\Exception $e) {
                    Log::error('Gateway cancel failed on downgrade to Free: ' . $e->getMessage());
                }

                $tenant->update([
                    'plan_id' => $price->plan_id,
                    'status' => 'active',
                    'stripe_subscription_id' => null,
                    'razorpay_subscription_id' => null,
                    'paystack_subscription_id' => null,
                    'paystack_email_token' => null,
                ]);

                return response()->json([
                    'immediate' => true,
                    'message' => 'Subscribed to Free Plan successfully.'
                ]);
            }

            // Check if tenant has subscription already on active gateway. If yes, update it directly via API
            $hasActiveSub = match ($activeGateway) {
                'razorpay' => !empty($tenant->razorpay_subscription_id),
                'paystack' => !empty($tenant->paystack_subscription_id),
                'flutterwave' => !empty($tenant->flutterwave_subscription_id),
                default => !empty($tenant->stripe_subscription_id),
            };

            if ($hasActiveSub) {
                $gatewayDriver->changeSubscriptionPlan($tenant, $gatewayPriceId);
                
                // Update local plan immediately
                $tenant->update([
                    'plan_id' => $price->plan_id,
                    'status' => 'active',
                ]);

                return response()->json([
                    'immediate' => true,
                    'message' => 'Subscription upgraded successfully.'
                ]);
            }

            // Otherwise, create checkout session via active gateway driver
            if ($activeGateway === 'stripe') {
                $checkoutUrl = $this->stripeService->createCheckoutSession($tenant, $gatewayPriceId, $successUrl, $cancelUrl);
                return response()->json([
                    'immediate' => false,
                    'gateway' => 'stripe',
                    'checkout_url' => $checkoutUrl
                ]);
            }

            if ($activeGateway === 'paystack') {
                $sessionData = $gatewayDriver->createCheckoutSession($tenant, $gatewayPriceId, $successUrl, $cancelUrl);
                $parsed = json_decode($sessionData, true);

                if (is_array($parsed) && isset($parsed['access_code'])) {
                    return response()->json(array_merge([
                        'immediate' => false,
                        'plan_id' => $price->plan_id,
                    ], $parsed));
                }

                return response()->json([
                    'immediate' => false,
                    'gateway' => 'paystack',
                    'checkout_url' => is_string($sessionData) ? $sessionData : null,
                ]);
            }

            if ($activeGateway === 'flutterwave') {
                $sessionData = $gatewayDriver->createCheckoutSession($tenant, $gatewayPriceId, $successUrl, $cancelUrl);
                $parsed = json_decode($sessionData, true);

                if (is_array($parsed) && (isset($parsed['checkout_url']) || isset($parsed['authorization_url']))) {
                    return response()->json(array_merge([
                        'immediate' => false,
                        'plan_id' => $price->plan_id,
                    ], $parsed));
                }

                return response()->json([
                    'immediate' => false,
                    'gateway' => 'flutterwave',
                    'checkout_url' => is_string($sessionData) ? $sessionData : null,
                ]);
            }

            // Razorpay Recurring Subscription Checkout
            $sessionData = $gatewayDriver->createCheckoutSession($tenant, $gatewayPriceId, $successUrl, $cancelUrl);
            $parsed = json_decode($sessionData, true);

            if (is_array($parsed) && isset($parsed['subscription_id'])) {
                return response()->json(array_merge([
                    'immediate' => false,
                    'plan_id' => $price->plan_id,
                ], $parsed));
            }

            return response()->json([
                'immediate' => false,
                'gateway' => 'razorpay',
                'checkout_url' => $sessionData,
            ]);

        } catch (\Exception $e) {
            Log::error('Checkout generation failed: ' . $e->getMessage());
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Verify Razorpay recurring subscription client-side authorization.
     */
    public function verifyRazorpay(Request $request): JsonResponse
    {
        $request->validate([
            'razorpay_payment_id' => ['required', 'string'],
            'razorpay_subscription_id' => ['required', 'string'],
            'razorpay_signature' => ['required', 'string'],
            'plan_id' => ['nullable'],
        ]);

        $tenant = $request->user()->tenant;
        if (!$tenant) {
            return response()->json(['message' => 'Workspace context not resolved.'], 404);
        }

        /** @var \App\Services\Payments\Drivers\RazorpayGatewayDriver $driver */
        $driver = $this->paymentManager->driver('razorpay');

        if (!$driver->verifySubscriptionSignature($request->all())) {
            return response()->json(['message' => 'Invalid Razorpay payment signature.'], 400);
        }

        $subId = $request->input('razorpay_subscription_id');
        $planId = $request->input('plan_id');

        // Fetch subscription from Razorpay to confirm plan ID
        $rzpClient = $driver->getClient();
        $rzpPlanId = null;
        if ($rzpClient) {
            try {
                $sub = $rzpClient->subscription->fetch($subId);
                if ($sub && !empty($sub->plan_id)) {
                    $rzpPlanId = $sub->plan_id;
                }
            } catch (\Exception $e) {
                Log::warning('Failed fetching subscription on verify: ' . $e->getMessage());
            }
        }

        $updateData = [
            'status' => 'active',
            'onboarding_step' => 'complete',
            'razorpay_subscription_id' => $subId,
        ];

        if ($rzpPlanId) {
            $priceObj = PlanPrice::where('razorpay_plan_id', $rzpPlanId)->first();
            if ($priceObj) {
                $updateData['plan_id'] = $priceObj->plan_id;
            }
        }
        if (empty($updateData['plan_id']) && !empty($planId)) {
            $updateData['plan_id'] = (int) $planId;
        }

        $tenant->update($updateData);
        Log::info("Tenant {$tenant->id} activated via Razorpay recurring subscription verification ({$subId}).");

        return response()->json([
            'success' => true,
            'message' => 'Recurring subscription activated successfully.',
            'tenant' => $tenant->fresh(),
        ]);
    }

    /**
     * Verify Paystack recurring subscription authorization.
     */
    public function verifyPaystack(Request $request): JsonResponse
    {
        $request->validate([
            'reference' => ['required', 'string'],
            'plan_id' => ['nullable'],
        ]);

        $tenant = $request->user()->tenant;
        if (!$tenant) {
            return response()->json(['message' => 'Workspace context not resolved.'], 404);
        }

        /** @var \App\Services\Payments\Drivers\PaystackGatewayDriver $driver */
        $driver = $this->paymentManager->driver('paystack');

        $reference = $request->input('reference');
        $planId = $request->input('plan_id');

        try {
            $verifyRes = $driver->verifyTransaction($reference);
            if (empty($verifyRes['status']) || ($verifyRes['data']['status'] ?? '') !== 'success') {
                return response()->json(['message' => $verifyRes['message'] ?? 'Transaction was not successful.'], 400);
            }

            $txData = $verifyRes['data'];
            $subCode = $txData['subscription']['subscription_code'] ?? ($txData['subscription_code'] ?? null);
            $custCode = $txData['customer']['customer_code'] ?? null;
            $emailToken = $txData['subscription']['email_token'] ?? ($txData['email_token'] ?? null);
            $metaPlanId = $txData['metadata']['plan_id'] ?? $planId;
            $planCode = $txData['plan']['plan_code'] ?? ($txData['plan'] ?? null);

            if (empty($subCode) && !empty($custCode) && !empty($planCode)) {
                try {
                    $authCode = $txData['authorization']['authorization_code'] ?? null;
                    $subData = $driver->createSubscription($custCode, $planCode, $authCode);
                    if ($subData) {
                        $subCode = $subData['subscription_code'] ?? null;
                        $emailToken = $subData['email_token'] ?? null;
                    }
                } catch (\Exception $e) {
                    Log::warning('Auto-provisioning Paystack subscription error on verify: ' . $e->getMessage());
                }
            }

            $updateData = [
                'status' => 'active',
                'onboarding_step' => 'complete',
            ];
            if ($subCode) $updateData['paystack_subscription_id'] = $subCode;
            if ($custCode) $updateData['paystack_customer_id'] = $custCode;
            if ($emailToken) $updateData['paystack_email_token'] = $emailToken;
            if (!empty($metaPlanId)) {
                $updateData['plan_id'] = (int) $metaPlanId;
            } elseif ($planCode) {
                $priceObj = PlanPrice::where('paystack_plan_code', $planCode)->first();
                if ($priceObj) {
                    $updateData['plan_id'] = $priceObj->plan_id;
                }
            }

            $tenant->update($updateData);
            Log::info("Tenant {$tenant->id} activated via Paystack recurring subscription verify ({$reference}).");

            return response()->json([
                'success' => true,
                'message' => 'Recurring subscription activated successfully.',
                'tenant' => $tenant->fresh(),
            ]);
        } catch (\Exception $e) {
            Log::error('Paystack verification error: ' . $e->getMessage());
            return response()->json(['message' => 'Verification failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Verify Flutterwave recurring subscription authorization.
     */
    public function verifyFlutterwave(Request $request): JsonResponse
    {
        $request->validate([
            'transaction_id' => ['nullable', 'string'],
            'tx_ref' => ['nullable', 'string'],
            'plan_id' => ['nullable'],
        ]);

        $tenant = $request->user()->tenant;
        if (!$tenant) {
            return response()->json(['message' => 'Workspace context not resolved.'], 404);
        }

        /** @var \App\Services\Payments\Drivers\FlutterwaveGatewayDriver $driver */
        $driver = $this->paymentManager->driver('flutterwave');

        $txId = $request->input('transaction_id') ?: $request->input('tx_ref');
        $planId = $request->input('plan_id');

        if (empty($txId)) {
            return response()->json(['message' => 'Transaction ID or reference is required.'], 400);
        }

        try {
            $verifyRes = $driver->verifyTransaction($txId);
            if (empty($verifyRes['status']) || ($verifyRes['data']['status'] ?? '') !== 'successful') {
                return response()->json(['message' => $verifyRes['message'] ?? 'Transaction was not successful.'], 400);
            }

            $txData = $verifyRes['data'] ?? [];
            $customerId = isset($txData['customer']['id']) ? (string) $txData['customer']['id'] : null;
            $paymentPlan = $txData['payment_plan'] ?? null;
            $subId = isset($txData['subscription_id']) ? (string)$txData['subscription_id'] : ($paymentPlan ? (string)$paymentPlan : null);
            $metaPlanId = $txData['meta']['plan_id'] ?? $planId;

            $updateData = [
                'status' => 'active',
                'onboarding_step' => 'complete',
            ];
            if ($customerId) $updateData['flutterwave_customer_id'] = $customerId;
            if ($subId) $updateData['flutterwave_subscription_id'] = $subId;
            if (!empty($metaPlanId)) {
                $updateData['plan_id'] = (int) $metaPlanId;
            } elseif ($paymentPlan) {
                $priceObj = PlanPrice::where('flutterwave_plan_id', (string)$paymentPlan)->first();
                if ($priceObj) {
                    $updateData['plan_id'] = $priceObj->plan_id;
                }
            }

            $tenant->update($updateData);
            Log::info("Tenant {$tenant->id} activated via Flutterwave verify ({$txId}).");

            return response()->json([
                'success' => true,
                'message' => 'Recurring subscription activated successfully.',
                'tenant' => $tenant->fresh(),
            ]);
        } catch (\Exception $e) {
            Log::error('Flutterwave verification error: ' . $e->getMessage());
            return response()->json(['message' => 'Verification failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Create a Customer Billing Portal session url.
     */
    public function portal(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        if (!$tenant) {
            return response()->json(['message' => 'Workspace context not resolved.'], 404);
        }

        $baseUrl = \App\Providers\AppServiceProvider::getFrontendUrl($request->user());
        $returnUrl = "{$baseUrl}/settings/billing";

        try {
            $activeGateway = $this->paymentManager->getActiveGateway();
            $portalUrl = ($activeGateway === 'stripe')
                ? $this->stripeService->createPortalSession($tenant, $returnUrl)
                : $this->paymentManager->driver($activeGateway)->createPortalSession($tenant, $returnUrl);

            return response()->json([
                'portal_url' => $portalUrl
            ]);
        } catch (\Exception $e) {
            Log::error('Portal session generation failed: ' . $e->getMessage());
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Cancel the subscription (stops renewal at cycle end).
     */
    public function cancel(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        if (!$tenant) {
            return response()->json(['message' => 'Workspace context not resolved.'], 404);
        }

        try {
            $activeGateway = $this->paymentManager->getActiveGateway();
            if ($activeGateway === 'stripe') {
                $this->stripeService->cancelSubscription($tenant);
            } else {
                $this->paymentManager->driver($activeGateway)->cancelSubscription($tenant);
            }

            return response()->json([
                'message' => 'Subscription set to cancel at the end of the billing period.'
            ]);
        } catch (\Exception $e) {
            Log::error('Subscription cancellation failed: ' . $e->getMessage());
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Resolve platform default primary currency strictly as set in Admin Panel.
     */
    protected function getPlatformDefaultCurrency(): \App\Models\Currency
    {
        $platformCurrency = \App\Models\Currency::where('is_default', true)->first();
        if (!$platformCurrency) {
            $code = \App\Models\PlatformSetting::where('key', 'default_currency')->first()?->value;
            if ($code) {
                $platformCurrency = \App\Models\Currency::where('code', $code)->first();
            }
        }
        if (!$platformCurrency) {
            $platformCurrency = \App\Models\Currency::first();
        }
        if (!$platformCurrency) {
            $platformCurrency = \App\Models\Currency::create([
                'code' => 'NGN',
                'name' => 'Nigerian Naira',
                'symbol' => '₦',
                'is_active' => true,
                'is_default' => true,
            ]);
        }

        return $platformCurrency;
    }
}

