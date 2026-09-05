<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGatewayInterface;
use App\Models\PlatformSetting;
use App\Services\Payments\Drivers\StripeGatewayDriver;
use App\Services\Payments\Drivers\RazorpayGatewayDriver;
use App\Services\Payments\Drivers\PaystackGatewayDriver;
use App\Services\Payments\Drivers\FlutterwaveGatewayDriver;
use InvalidArgumentException;

class PaymentGatewayManager
{
    /**
     * Registered driver instances.
     */
    protected array $drivers = [];

    /**
     * Get or create a driver instance for a payment gateway.
     */
    public function driver(?string $name = null): PaymentGatewayInterface
    {
        $name = $name ?: $this->getActiveGateway();

        if (isset($this->drivers[$name])) {
            return $this->drivers[$name];
        }

        return $this->drivers[$name] = $this->resolve($name);
    }

    /**
     * Resolve the requested gateway driver.
     */
    protected function resolve(string $name): PaymentGatewayInterface
    {
        $config = $this->getGatewayConfig($name);

        switch ($name) {
            case 'stripe':
                return new StripeGatewayDriver($config);
            case 'razorpay':
                return new RazorpayGatewayDriver($config);
            case 'paystack':
                return new PaystackGatewayDriver($config);
            case 'flutterwave':
                return new FlutterwaveGatewayDriver($config);
            default:
                throw new InvalidArgumentException("Payment gateway driver [{$name}] is not supported yet.");
        }
    }

    /**
     * Get the active primary payment gateway slug.
     */
    public function getActiveGateway(): string
    {
        $config = $this->getRawConfig();
        return $config['active_gateway'] ?? 'stripe';
    }

    /**
     * Get the settings config array for a specific gateway.
     */
    public function getGatewayConfig(string $name): array
    {
        $config = $this->getRawConfig();
        $gateways = $config['gateways'] ?? [];

        if (isset($gateways[$name]) && is_array($gateways[$name])) {
            return $gateways[$name];
        }

        return [
            'enabled' => false,
            'mode' => 'test',
            'publishable_key' => '',
            'public_key' => '',
            'secret_key' => '',
            'webhook_secret' => '',
        ];
    }

    /**
     * Get all raw configurations from PlatformSetting.
     */
    public function getRawConfig(): array
    {
        $setting = PlatformSetting::where('key', 'payment_gateways_config')->first();
        if ($setting && is_array($setting->value)) {
            return $setting->value;
        }

        return [
            'active_gateway' => 'stripe',
            'gateways' => [
                'stripe' => [
                    'enabled' => false,
                    'mode' => 'test',
                    'publishable_key' => '',
                    'secret_key' => '',
                    'webhook_secret' => '',
                ],
                'razorpay' => [
                    'enabled' => false,
                    'mode' => 'test',
                    'key_id' => '',
                    'key_secret' => '',
                    'webhook_secret' => '',
                ],
                'paystack' => [
                    'enabled' => false,
                    'mode' => 'test',
                    'public_key' => '',
                    'secret_key' => '',
                    'webhook_secret' => '',
                ],
                'flutterwave' => [
                    'enabled' => false,
                    'mode' => 'test',
                    'public_key' => '',
                    'secret_key' => '',
                    'webhook_secret' => '',
                ],
            ]
        ];
    }

    /**
     * Get masked gateway summary for the Super Admin UI.
     */
    public function getAllGatewaysSummary(): array
    {
        $rawConfig = $this->getRawConfig();
        $active = $rawConfig['active_gateway'] ?? 'stripe';

        $supported = [
            'stripe' => [
                'slug' => 'stripe',
                'name' => 'Stripe',
                'description' => 'Global credit/debit card, Apple Pay, Google Pay, and recurring subscription billing.',
                'logo' => '/payments/stripe.png',
                'is_ready' => true,
                'docs_url' => 'https://dashboard.stripe.com/apikeys',
            ],
            'razorpay' => [
                'slug' => 'razorpay',
                'name' => 'Razorpay',
                'description' => 'India & International payments: UPI, NetBanking, Credit/Debit Cards, EMI, Wallets, and Subscriptions.',
                'logo' => '/payments/razorpay.png',
                'is_ready' => true,
                'docs_url' => 'https://dashboard.razorpay.com/app/keys',
            ],
            'paystack' => [
                'slug' => 'paystack',
                'name' => 'Paystack',
                'description' => 'African & Global payments: Cards, Bank Transfer, USSD, Mobile Money, and Recurring Subscriptions.',
                'logo' => '/payments/paystack.png',
                'is_ready' => true,
                'docs_url' => 'https://dashboard.paystack.com/#/settings/developer',
            ],
            'flutterwave' => [
                'slug' => 'flutterwave',
                'name' => 'Flutterwave',
                'description' => 'Pan-African & Global payments: Cards, Mobile Money, Bank Transfers, and Recurring Subscriptions.',
                'logo' => '/payments/flutterwave.png',
                'is_ready' => true,
                'docs_url' => 'https://developer.flutterwave.com/docs',
            ],
        ];

        $gateways = [];
        foreach ($supported as $slug => $meta) {
            $gwConfig = $this->getGatewayConfig($slug);
            $isEnabled = (bool)($gwConfig['enabled'] ?? false);
            $hasSecret = !empty($gwConfig['secret_key'] ?? ($gwConfig['key_secret'] ?? ($gwConfig['client_secret'] ?? ($gwConfig['api_key'] ?? null))));

            $mode = $gwConfig['mode'] ?? 'test';
            $maskedSecret = '';
            if ($hasSecret) {
                $rawSecret = $gwConfig['secret_key'] ?? ($gwConfig['key_secret'] ?? ($gwConfig['client_secret'] ?? ($gwConfig['api_key'] ?? '')));
                $maskedSecret = $this->maskSecret($rawSecret);
            }

            $maskedWebhook = '';
            if (!empty($gwConfig['webhook_secret'])) {
                $maskedWebhook = $this->maskSecret($gwConfig['webhook_secret']);
            }

            $gateways[$slug] = [
                'slug' => $slug,
                'name' => $meta['name'],
                'description' => $meta['description'],
                'logo' => $meta['logo'],
                'is_ready' => $meta['is_ready'],
                'docs_url' => $meta['docs_url'],
                'enabled' => $isEnabled,
                'is_active' => $active === $slug,
                'mode' => $mode,
                'publishable_key' => $gwConfig['publishable_key'] ?? ($gwConfig['public_key'] ?? ($gwConfig['key_id'] ?? ($gwConfig['client_id'] ?? ''))),
                'public_key' => $gwConfig['public_key'] ?? ($gwConfig['publishable_key'] ?? ''),
                'has_secret' => $hasSecret,
                'masked_secret' => $maskedSecret,
                'has_webhook_secret' => !empty($gwConfig['webhook_secret']),
                'masked_webhook_secret' => $maskedWebhook,
                'webhook_url' => rtrim(config('app.url') ?: url('/'), '/') . "/api/webhooks/billing/{$slug}",
            ];
        }

        return [
            'active_gateway' => $active,
            'gateways' => $gateways,
        ];
    }

    /**
     * Save gateways configuration with automatic AES-256 encryption.
     */
    public function saveConfig(array $data): void
    {
        $existing = $this->getRawConfig();

        if (isset($data['active_gateway'])) {
            $existing['active_gateway'] = $data['active_gateway'];
        }

        if (isset($data['gateways']) && is_array($data['gateways'])) {
            foreach ($data['gateways'] as $slug => $newConfig) {
                $currentGw = $existing['gateways'][$slug] ?? [];
                
                // If a masked secret (e.g. ••••••••) or empty secret is passed, retain previous stored secret
                if (isset($newConfig['secret_key'])) {
                    if (str_contains($newConfig['secret_key'], '••••') || empty($newConfig['secret_key'])) {
                        $newConfig['secret_key'] = $currentGw['secret_key'] ?? ($currentGw['key_secret'] ?? '');
                    }
                }
                if (isset($newConfig['key_secret'])) {
                    if (str_contains($newConfig['key_secret'], '••••') || empty($newConfig['key_secret'])) {
                        $newConfig['key_secret'] = $currentGw['key_secret'] ?? ($currentGw['secret_key'] ?? '');
                    }
                }
                if (isset($newConfig['webhook_secret'])) {
                    if (str_contains($newConfig['webhook_secret'], '••••') || empty($newConfig['webhook_secret'])) {
                        $newConfig['webhook_secret'] = $currentGw['webhook_secret'] ?? '';
                    }
                }

                $existing['gateways'][$slug] = array_merge($currentGw, $newConfig);
            }
        }

        PlatformSetting::updateOrCreate(
            ['key' => 'payment_gateways_config'],
            ['value' => $existing]
        );

        // Clear driver cache to re-instantiate with new keys
        $this->drivers = [];
    }

    protected function maskSecret(string $secret): string
    {
        $len = strlen($secret);
        if ($len <= 8) {
            return '••••••••';
        }
        $prefix = substr($secret, 0, min(7, $len - 4));
        $suffix = substr($secret, -4);
        return $prefix . '••••••••' . $suffix;
    }
}
