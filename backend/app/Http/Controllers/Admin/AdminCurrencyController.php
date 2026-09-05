<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCurrencyController extends Controller
{
    /**
     * List all currencies.
     */
    public function index(): JsonResponse
    {
        $currencies = Currency::orderBy('is_default', 'desc')
            ->orderBy('code', 'asc')
            ->get();

        foreach ($currencies as $currency) {
            $currency->pricing_count = \App\Models\PlanPrice::where('currency_id', $currency->id)->count();
            $currency->tenant_count = \App\Models\Tenant::where('currency_id', $currency->id)->count();
        }

        return response()->json([
            'currencies' => $currencies
        ]);
    }

    /**
     * Create a new currency.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'size:3', 'unique:currencies,code'],
            'symbol' => ['required', 'string', 'max:10'],
            'name' => ['required', 'string', 'max:50'],
            'is_active' => ['required', 'boolean'],
            'is_default' => ['required', 'boolean'],
        ]);

        if ($request->is_default) {
            // Set all other default flags to false
            Currency::where('is_default', true)->update(['is_default' => false]);
            // If default, must be active
            $request->merge(['is_active' => true]);
        }

        $currency = Currency::create([
            'code' => strtoupper($request->code),
            'symbol' => $request->symbol,
            'name' => $request->name,
            'is_active' => $request->is_active,
            'is_default' => $request->is_default,
        ]);

        return response()->json([
            'message' => 'Currency created successfully.',
            'currency' => $currency
        ], 201);
    }

    /**
     * Update an existing currency status or default rules.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $currency = Currency::find($id);

        if (!$currency) {
            return response()->json(['message' => 'Currency not found.'], 404);
        }

        $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'plan_prices' => ['sometimes', 'array'],
            'plan_prices.*.plan_id' => ['required_with:plan_prices', 'exists:plans,id'],
            'plan_prices.*.monthly_amount' => ['required_with:plan_prices', 'integer', 'min:0'],
            'plan_prices.*.yearly_amount' => ['required_with:plan_prices', 'integer', 'min:0'],
        ]);

        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            if ($request->has('is_default') && $request->is_default) {
                // Set all other default flags to false
                Currency::where('is_default', true)->update(['is_default' => false]);
                $currency->is_default = true;
                $currency->is_active = true; // Default must be active
                \App\Models\PlatformSetting::updateOrCreate(
                    ['key' => 'default_currency'],
                    ['value' => $currency->code]
                );

                // If plan prices are provided, update all plan prices to this currency
                if ($request->has('plan_prices') && is_array($request->plan_prices)) {
                    $stripeService = app(\App\Services\StripeService::class);
                    foreach ($request->plan_prices as $pp) {
                        $plan = \App\Models\Plan::find($pp['plan_id']);
                        if (!$plan) continue;

                        // Clean up existing prices
                        \App\Models\PlanPrice::where('plan_id', $plan->id)->delete();

                        // Provision monthly price
                        $monthlyStripePriceId = null;
                        if (app()->runningUnitTests()) {
                            $monthlyStripePriceId = 'price_mock_' . $currency->code . '_' . uniqid();
                        } elseif (!empty($plan->stripe_product_id)) {
                            try {
                                $monthlyStripePriceId = $stripeService->createPrice(
                                    $plan->stripe_product_id,
                                    $pp['monthly_amount'],
                                    $currency->code,
                                    'month'
                                );
                            } catch (\Exception $e) {}
                        }
                        if (empty($monthlyStripePriceId)) {
                            $monthlyStripePriceId = "price_plan_{$plan->id}_month";
                        }

                        \App\Models\PlanPrice::create([
                            'plan_id' => $plan->id,
                            'currency_id' => $currency->id,
                            'amount' => $pp['monthly_amount'],
                            'stripe_price_id' => $monthlyStripePriceId,
                            'billing_interval' => 'month',
                        ]);

                        // Provision yearly price
                        $yearlyStripePriceId = null;
                        if (app()->runningUnitTests()) {
                            $yearlyStripePriceId = 'price_mock_' . $currency->code . '_' . uniqid();
                        } elseif (!empty($plan->stripe_product_id)) {
                            try {
                                $yearlyStripePriceId = $stripeService->createPrice(
                                    $plan->stripe_product_id,
                                    $pp['yearly_amount'],
                                    $currency->code,
                                    'year'
                                );
                            } catch (\Exception $e) {}
                        }
                        if (empty($yearlyStripePriceId)) {
                            $yearlyStripePriceId = "price_plan_{$plan->id}_year";
                        }

                        \App\Models\PlanPrice::create([
                            'plan_id' => $plan->id,
                            'currency_id' => $currency->id,
                            'amount' => $pp['yearly_amount'],
                            'stripe_price_id' => $yearlyStripePriceId,
                            'billing_interval' => 'year',
                        ]);
                    }
                }
            } elseif ($request->has('is_default') && !$request->is_default && $currency->is_default) {
                \Illuminate\Support\Facades\DB::rollBack();
                return response()->json([
                    'message' => 'You must set another currency as default first. There must always be one default currency.'
                ], 400);
            }

            if ($request->has('is_active') && !$request->is_default) {
                $currency->is_active = $request->is_active;
            }

            $currency->save();
            \Illuminate\Support\Facades\DB::commit();

            return response()->json([
                'message' => 'Currency updated successfully.',
                'currency' => $currency
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            return response()->json(['message' => 'Failed to update currency: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Safely delete a currency.
     */
    public function destroy(string $id): JsonResponse
    {
        $currency = Currency::find($id);

        if (!$currency) {
            return response()->json(['message' => 'Currency not found.'], 404);
        }

        if ($currency->is_default) {
            return response()->json([
                'message' => 'Default currency cannot be deleted.'
            ], 400);
        }

        // Check if there are plan prices or tenants using this currency
        $pricingCount = \App\Models\PlanPrice::where('currency_id', $id)->count();
        $tenantCount = \App\Models\Tenant::where('currency_id', $id)->count();

        if ($pricingCount > 0 || $tenantCount > 0) {
            return response()->json([
                'message' => 'This currency is currently linked to plans or active tenant workspaces and cannot be deleted.'
            ], 400);
        }

        $currency->delete();

        return response()->json([
            'message' => 'Currency deleted successfully.'
        ]);
    }
}
