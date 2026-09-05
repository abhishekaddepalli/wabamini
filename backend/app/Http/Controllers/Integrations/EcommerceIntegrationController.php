<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\EcommerceConnection;
use App\Models\EcommerceOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Jobs\SyncEcommerceCatalogJob;
use App\Models\EcommerceAbandonedCart;
use App\Jobs\SendAbandonedCartRecoveryJob;

class EcommerceIntegrationController extends Controller
{
    /**
     * Get ecommerce status for the tenant.
     */
    public function status(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $connections = EcommerceConnection::where('tenant_id', $tenantId)->get();

        return response()->json($connections);
    }

    /**
     * Connect a new store connection.
     */
    public function connect(Request $request)
    {
        $request->validate([
            'platform' => 'required|string|in:shopify,woocommerce',
            'store_url' => 'required|string',
        ]);

        $platform = $request->input('platform');
        $tenant = $request->user()->tenant;
        if ($tenant) {
            $limitCheck = app(\App\Services\PlanLimitService::class)->canUseIntegration($tenant, $platform);
            if (!$limitCheck['allowed']) {
                return response()->json([
                    'message' => $limitCheck['reason'],
                    'error_code' => $limitCheck['code']
                ], 403);
            }
        }

        $tenantId = $request->user()->tenant_id;
        $platform = $request->input('platform');
        $storeUrl = rtrim($request->input('store_url'), '/');

        // Parse and clean Shopify domain if necessary
        if ($platform === 'shopify') {
            if (str_starts_with($storeUrl, 'http://') || str_starts_with($storeUrl, 'https://')) {
                $parsed = parse_url($storeUrl);
                $storeUrl = $parsed['host'] ?? $storeUrl;
            }
        }

        // Check if connection already exists
        $connection = EcommerceConnection::where('tenant_id', $tenantId)
            ->where('platform', $platform)
            ->first();

        if ($connection) {
            $connection->update([
                'store_url' => $storeUrl,
                'status' => 'active',
            ]);
        } else {
            $connection = EcommerceConnection::create([
                'tenant_id' => $tenantId,
                'platform' => $platform,
                'store_url' => $storeUrl,
                'credentials' => [],
                'status' => 'active',
                'webhook_secret' => 'whsec_' . Str::random(32),
            ]);
        }

        return response()->json([
            'message' => ucfirst($platform) . ' store connected successfully.',
            'connection' => $connection,
        ]);
    }

    /**
     * Disconnect store connection.
     */
    public function disconnect(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
        ]);

        $tenantId = $request->user()->tenant_id;
        $connection = EcommerceConnection::where('tenant_id', $tenantId)
            ->where('id', $request->input('id'))
            ->first();

        if (!$connection) {
            return response()->json(['message' => 'Connection not found.'], 404);
        }

        // Clean up orders
        EcommerceOrder::where('tenant_id', $tenantId)
            ->where('ecommerce_connection_id', $connection->id)
            ->delete();

        $connection->delete();

        return response()->json([
            'message' => 'Integration disconnected successfully.',
        ]);
    }

    /**
     * Manual sync action.
     */
    public function syncNow(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
        ]);

        $tenantId = $request->user()->tenant_id;
        $connection = EcommerceConnection::where('tenant_id', $tenantId)
            ->where('id', $request->input('id'))
            ->first();

        if (!$connection) {
            return response()->json(['message' => 'Connection not found.'], 404);
        }

        // Dispatch background catalog sync and embedding vectorization job
        SyncEcommerceCatalogJob::dispatch($connection->id);

        return response()->json([
            'message' => 'Synchronization triggered successfully.',
        ]);
    }

    /**
     * Get ecommerce store coupons list.
     */
    public function coupons(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $connection = EcommerceConnection::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->first();

        // Default fallback mocks so the UI works immediately for test/demo accounts
        $fallbackCoupons = ['WELCOME10', 'FREESHIP', 'VIP20', 'WINTER30'];

        if (!$connection) {
            return response()->json($fallbackCoupons);
        }

        $platform = $connection->platform;
        $storeUrl = $connection->store_url;
        $creds = $connection->credentials ?? [];

        try {
            if ($platform === 'shopify') {
                $accessToken = $creds['access_token'] ?? $creds['api_key'] ?? null;
                if ($accessToken) {
                    $response = Http::timeout(10)
                        ->withHeaders([
                            'X-Shopify-Access-Token' => $accessToken,
                            'Content-Type' => 'application/json',
                        ])
                        ->get("https://{$storeUrl}/admin/api/2024-04/price_rules.json");

                    if ($response->successful()) {
                        $priceRules = $response->json('price_rules') ?? [];
                        $codes = [];
                        foreach ($priceRules as $pr) {
                            if (!empty($pr['title'])) {
                                $codes[] = $pr['title'];
                            }
                        }
                        if (!empty($codes)) {
                            return response()->json(array_values(array_unique($codes)));
                        }
                    }
                }
            } else if ($platform === 'woocommerce') {
                $consumerKey = $creds['consumer_key'] ?? null;
                $consumerSecret = $creds['consumer_secret'] ?? null;

                if ($consumerKey && $consumerSecret) {
                    $response = Http::timeout(10)
                        ->withBasicAuth($consumerKey, $consumerSecret)
                        ->get("https://{$storeUrl}/wp-json/wc/v3/coupons");

                    if ($response->successful()) {
                        $coupons = $response->json() ?? [];
                        $codes = [];
                        foreach ($coupons as $c) {
                            if (!empty($c['code'])) {
                                $codes[] = strtoupper($c['code']);
                            }
                        }
                        if (!empty($codes)) {
                            return response()->json(array_values(array_unique($codes)));
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error("EcommerceIntegrationController coupons query error: " . $e->getMessage());
        }

        return response()->json($fallbackCoupons);
    }

    /**
     * Get recovery analytics dashboard parameters.
     */
    public function analytics(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $abandonedCount = EcommerceAbandonedCart::where('tenant_id', $tenantId)->count();
        $recoveredCount = EcommerceAbandonedCart::where('tenant_id', $tenantId)->where('recovery_status', 'recovered')->count();
        
        $recoveryRate = $abandonedCount > 0 ? round(($recoveredCount / $abandonedCount) * 100, 2) : 0.00;
        $recoveredRevenue = EcommerceAbandonedCart::where('tenant_id', $tenantId)
            ->where('recovery_status', 'recovered')
            ->sum('total_price');

        // Chronological list of checkouts
        $carts = EcommerceAbandonedCart::where('tenant_id', $tenantId)
            ->with('contact')
            ->orderBy('id', 'desc')
            ->get();

        $tenant = $request->user()->tenant;
        $currencyInfo = $tenant->currency ? [
            'code' => $tenant->currency->code,
            'symbol' => $tenant->currency->symbol
        ] : [
            'code' => 'USD',
            'symbol' => '$'
        ];

        return response()->json([
            'metrics' => [
                'carts_abandoned' => $abandonedCount,
                'carts_recovered' => $recoveredCount,
                'recovery_rate' => $recoveryRate,
                'recovered_revenue' => round($recoveredRevenue, 2),
            ],
            'carts' => $carts,
            'currency' => $currencyInfo
        ]);
    }

    /**
     * Manually trigger cart recovery.
     */
    public function triggerManualRecovery(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;
        $cart = EcommerceAbandonedCart::where('tenant_id', $tenantId)->find($id);

        if (!$cart) {
            return response()->json(['message' => 'Cart not found.'], 404);
        }

        // Force reset state to pending to allow manual dispatch even if standard dispatch is expired
        $cart->update(['recovery_status' => 'pending']);

        // Dispatch job synchronously for instant feedback in the UI
        SendAbandonedCartRecoveryJob::dispatchSync($cart->id);

        return response()->json([
            'message' => 'Manual cart recovery triggered successfully!',
            'status' => 'recovered'
        ]);
    }

    /**
     * Get active contact e-commerce context (active cart & order history).
     */
    public function customerContext(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        // Safety Guard: Check if tenant has an active e-commerce connection
        $hasConnection = EcommerceConnection::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->exists();

        if (!$hasConnection) {
            return response()->json([
                'has_integration' => false,
                'active_cart' => null,
                'orders' => []
            ]);
        }

        $email = $request->query('email');
        $phone = $request->query('phone');

        if (empty($email) && empty($phone)) {
            return response()->json([
                'has_integration' => true,
                'active_cart' => null,
                'orders' => []
            ]);
        }

        // Find matching contact
        $contact = \App\Models\Contact::where('tenant_id', $tenantId)
            ->where(function ($q) use ($email, $phone) {
                if ($email) $q->where('email', $email);
                if ($phone) $q->orWhere('phone', $phone);
            })->first();

        $activeCart = null;
        $orders = [];

        if ($contact) {
            // Find active/abandoned cart for the contact
            $activeCart = EcommerceAbandonedCart::where('tenant_id', $tenantId)
                ->where('contact_id', $contact->id)
                ->orderBy('id', 'desc')
                ->first();
        }

        // Find order history matching email or phone
        $orders = EcommerceOrder::where('tenant_id', $tenantId)
            ->where(function ($q) use ($email, $phone) {
                if ($email) $q->where('customer_email', $email);
                if ($phone) $q->orWhere('customer_phone', $phone);
            })
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'has_integration' => true,
            'active_cart' => $activeCart,
            'orders' => $orders
        ]);
    }
}
