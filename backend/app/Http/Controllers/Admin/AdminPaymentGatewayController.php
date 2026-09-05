<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Exception;

class AdminPaymentGatewayController extends Controller
{
    protected PaymentGatewayManager $paymentManager;

    public function __construct(PaymentGatewayManager $paymentManager)
    {
        $this->paymentManager = $paymentManager;
    }

    /**
     * List all supported payment gateways and masked status.
     */
    public function index(): JsonResponse
    {
        $summary = $this->paymentManager->getAllGatewaysSummary();
        return response()->json($summary);
    }

    /**
     * Save payment gateway configuration.
     */
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'active_gateway' => ['nullable', 'string', 'in:stripe,razorpay,paystack,flutterwave'],
            'gateways' => ['nullable', 'array'],
            'gateways.stripe' => ['nullable', 'array'],
            'gateways.stripe.enabled' => ['nullable', 'boolean'],
            'gateways.stripe.mode' => ['nullable', 'string', 'in:test,live'],
            'gateways.stripe.publishable_key' => ['nullable', 'string'],
            'gateways.stripe.secret_key' => ['nullable', 'string'],
            'gateways.stripe.webhook_secret' => ['nullable', 'string'],
            'gateways.razorpay' => ['nullable', 'array'],
            'gateways.razorpay.enabled' => ['nullable', 'boolean'],
            'gateways.razorpay.mode' => ['nullable', 'string', 'in:test,live'],
            'gateways.razorpay.key_id' => ['nullable', 'string'],
            'gateways.razorpay.key_secret' => ['nullable', 'string'],
            'gateways.razorpay.publishable_key' => ['nullable', 'string'],
            'gateways.razorpay.secret_key' => ['nullable', 'string'],
            'gateways.razorpay.webhook_secret' => ['nullable', 'string'],
            'gateways.paystack' => ['nullable', 'array'],
            'gateways.paystack.enabled' => ['nullable', 'boolean'],
            'gateways.paystack.mode' => ['nullable', 'string', 'in:test,live'],
            'gateways.paystack.public_key' => ['nullable', 'string'],
            'gateways.paystack.publishable_key' => ['nullable', 'string'],
            'gateways.paystack.secret_key' => ['nullable', 'string'],
            'gateways.paystack.webhook_secret' => ['nullable', 'string'],
            'gateways.flutterwave' => ['nullable', 'array'],
            'gateways.flutterwave.enabled' => ['nullable', 'boolean'],
            'gateways.flutterwave.mode' => ['nullable', 'string', 'in:test,live'],
            'gateways.flutterwave.public_key' => ['nullable', 'string'],
            'gateways.flutterwave.publishable_key' => ['nullable', 'string'],
            'gateways.flutterwave.secret_key' => ['nullable', 'string'],
            'gateways.flutterwave.webhook_secret' => ['nullable', 'string'],
        ]);

        $validated = $request->all();
        $currentConfig = $this->paymentManager->getRawConfig();

        $mergedConfig = [
            'active_gateway' => $validated['active_gateway'] ?? ($currentConfig['active_gateway'] ?? 'stripe'),
            'gateways' => $currentConfig['gateways'] ?? [],
        ];

        foreach (['stripe', 'razorpay', 'paystack', 'flutterwave'] as $gw) {
            if (isset($validated['gateways'][$gw])) {
                $incoming = $validated['gateways'][$gw];
                $existing = $mergedConfig['gateways'][$gw] ?? [];

                // Keep existing secrets if user did not re-type (masked values)
                foreach ($incoming as $k => $v) {
                    if (is_string($v) && str_contains($v, '••••••••')) {
                        $incoming[$k] = $existing[$k] ?? '';
                    }
                }

                $mergedConfig['gateways'][$gw] = array_merge($existing, $incoming);
            }
        }

        PlatformSetting::updateOrCreate(
            ['key' => 'payment_gateways_config'],
            ['value' => $mergedConfig]
        );

        return response()->json([
            'message' => 'Payment gateway settings saved successfully.',
            'summary' => $this->paymentManager->getAllGatewaysSummary(),
        ]);
    }

    /**
     * Test connection to a specific payment gateway.
     */
    public function testConnection(Request $request): JsonResponse
    {
        $request->validate([
            'gateway' => ['required', 'string', 'in:stripe,razorpay,paystack,flutterwave'],
            'credentials' => ['nullable', 'array'],
        ]);

        $gateway = $request->input('gateway');
        $credentials = $request->input('credentials', []);

        try {
            $driver = $this->paymentManager->driver($gateway);
            $result = $driver->testConnection($credentials);

            if ($result['success']) {
                return response()->json($result);
            }

            return response()->json($result, 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Connection test failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
