<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Mail\TestPlatformMail;
use App\Services\AIProviderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class AdminSettingsController extends Controller
{
    /**
     * Get all platform settings.
     */
    public function index(): JsonResponse
    {
        $settings = PlatformSetting::all()->pluck('value', 'key')->toArray();

        if (isset($settings['logo_url'])) {
            $settings['logo_url'] = $this->formatLogoUrl($settings['logo_url']);
        }

        return response()->json([
            'settings' => (object) $settings
        ]);
    }

    /**
     * Create or update platform settings.
     */
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'settings' => ['required', 'array'],
        ]);

        foreach ($request->settings as $key => $value) {
            PlatformSetting::updateOrCreate(
                ['key' => $key],
                ['value' => $value]
            );

            if ($key === 'default_currency' && !empty($value)) {
                $targetCode = strtoupper(trim((string)$value));
                $currency = \App\Models\Currency::where('code', $targetCode)->first();
                if ($currency) {
                    \App\Models\Currency::where('is_default', true)->update(['is_default' => false]);
                    $currency->update([
                        'is_default' => true,
                        'is_active' => true,
                    ]);
                }
            }
        }

        return response()->json([
            'message' => 'Platform settings saved successfully.',
            'settings' => (object) PlatformSetting::all()->pluck('value', 'key')->toArray()
        ]);
    }

    /**
     * Send a real test email with temporary configurations to verify settings before saving.
     */
    public function testMailer(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['required', 'string', 'in:smtp,resend'],
            'config' => ['required', 'array'],
            'recipient' => ['required', 'email'],
            'html' => ['nullable', 'string'],
        ]);

        $type = $request->type;
        $config = $request->config;
        $recipient = $request->recipient;
        $html = $request->html;

        try {
            if ($type === 'smtp') {
                $request->validate([
                    'config.host' => ['required', 'string'],
                    'config.port' => ['required', 'integer'],
                    'config.username' => ['required', 'string'],
                    'config.password' => ['required', 'string'],
                    'config.from_address' => ['required', 'email'],
                    'config.from_name' => ['required', 'string'],
                    'config.encryption' => ['nullable', 'string', 'in:none,ssl,tls'],
                ]);

                $port = (int)$config['port'];
                $encryption = $config['encryption'] ?? null;
                if ($encryption === 'none') {
                    $encryption = null;
                }
                if ($encryption === null) {
                    if ($port === 465) {
                        $encryption = 'ssl';
                    } elseif ($port === 587) {
                        $encryption = 'tls';
                    }
                }

                $scheme = null;
                if ($encryption === 'ssl' || $port === 465) {
                    $scheme = 'smtps';
                }

                // Configure dynamic SMTP mailer
                config([
                    'mail.mailers.temp_smtp' => [
                        'transport' => 'smtp',
                        'scheme' => $scheme,
                        'host' => $config['host'],
                        'port' => $config['port'],
                        'encryption' => $encryption,
                        'username' => $config['username'],
                        'password' => $config['password'],
                        'timeout' => 8,
                    ],
                    'mail.from.address' => $config['from_address'],
                    'mail.from.name' => $config['from_name'],
                ]);
                $mailer = 'temp_smtp';

            } else { // resend
                $request->validate([
                    'config.api_key' => ['required', 'string'],
                    'config.from_address' => ['required', 'email'],
                    'config.from_name' => ['required', 'string'],
                ]);

                // Configure dynamic Resend mailer
                config([
                    'mail.mailers.temp_resend' => [
                        'transport' => 'resend',
                    ],
                    'services.resend.key' => $config['api_key'],
                    'mail.from.address' => $config['from_address'],
                    'mail.from.name' => $config['from_name'],
                ]);
                $mailer = 'temp_resend';
            }

            // Attempt test email dispatch
            Mail::mailer($mailer)->to($recipient)->send(
                new TestPlatformMail(
                    $config['from_name'] ?? 'WhatsOmni',
                    $html
                )
            );

            return response()->json([
                'success' => true,
                'message' => 'Connection test passed. Test verification email has been sent successfully.',
            ]);

        } catch (\Exception $e) {
            Log::error('Mailer test failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Connection test failed: ' . $e->getMessage()
            ], 422);
        }
    }

    /**
     * Get public platform settings (branding, enabled languages).
     */
    public function publicSettings(): JsonResponse
    {
        $settings = PlatformSetting::all()->pluck('value', 'key');
        
        return response()->json([
            'branding_name' => $settings->get('branding_name', 'WhatsOmni'),
            'supported_languages' => $settings->get('supported_languages', ['en', 'hi', 'ar', 'de', 'fr', 'vi', 'id', 'he', 'tr', 'ja', 'pt', 'it', 'es']),
            'primary_color' => $settings->get('primary_color', '#4AE54A'),
            'accent_color' => $settings->get('accent_color', '#0A0A0A'),
            'logo_url' => $this->formatLogoUrl($settings->get('logo_url')),
            'channels_enabled' => $settings->get('channels_enabled', [
                'whatsapp_cloud' => true,
                'whatsapp_baileys' => true,
                'instagram' => false,
                'messenger' => false,
                'telegram' => false,
                'sms' => true,
                'email' => true,
            ]),
            'sms_providers_enabled' => collect(($settings->get('sms_providers_config') ?: [
                'providers' => [
                    'twilio' => ['enabled' => true],
                    'vonage' => ['enabled' => false],
                    'messagebird' => ['enabled' => false],
                    'plivo' => ['enabled' => false],
                    'sinch' => ['enabled' => false],
                    'telnyx' => ['enabled' => false],
                ]
            ])['providers'] ?? [])
                ->map(fn($p) => (bool)($p['enabled'] ?? false))
                ->toArray(),
            'integrations_enabled_config' => $settings->get('integrations_enabled_config', [
                'google_sheets' => true,
                'google_calendar' => true,
                'zoom' => true,
                'teams' => true,
                'hubspot' => true,
                'salesforce' => true,
                'zoho' => true,
                'shopify' => true,
                'woocommerce' => true,
            ]),
        ]);
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => [
                'required',
                'file',
                'mimes:png,jpg,jpeg,webp,svg,gif,ico,bmp,avif',
                'max:10240', // 10MB
            ],
        ]);

        try {
            $file = $request->file('logo');
            $extension = $file->getClientOriginalExtension() ?: 'png';
            $filename = 'logo_' . time() . '_' . uniqid() . '.' . $extension;

            $path = $file->storeAs('branding', $filename, 'public');
            $url = asset('storage/' . $path);

            return response()->json([
                'url' => $url,
                'message' => 'Logo uploaded successfully.',
            ]);
        } catch (\Exception $e) {
            Log::error('Logo upload failed: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to upload logo: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Format and normalize logo URL dynamically.
     */
    private function formatLogoUrl(?string $storedUrl): string
    {
        if (empty($storedUrl) || $storedUrl === '/logo.svg' || $storedUrl === '/logo.png') {
            return asset('logo.png');
        }

        if (preg_match('#/storage/(.+)#', $storedUrl, $matches)) {
            return asset('storage/' . $matches[1]);
        }

        if (str_starts_with($storedUrl, 'http://') || str_starts_with($storedUrl, 'https://')) {
            return $storedUrl;
        }

        return asset(ltrim($storedUrl, '/'));
    }

    /**
     * Get global channels active states and SMS providers configuration.
     */
    public function getChannelsConfig(): JsonResponse
    {
        $channels = PlatformSetting::where('key', 'channels_enabled')->first();
        $smsConfig = PlatformSetting::where('key', 'sms_providers_config')->first();

        $defaultChannels = [
            'whatsapp_cloud' => true,
            'whatsapp_baileys' => true,
            'instagram' => false,
            'messenger' => false,
            'telegram' => false,
            'sms' => true,
            'email' => true,
        ];

        $defaultSmsConfig = [
            'active_provider' => 'twilio',
            'providers' => [
                'twilio' => ['enabled' => true, 'twilio_account_sid' => '', 'twilio_auth_token' => '', 'twilio_phone_number' => ''],
                'vonage' => ['enabled' => false, 'vonage_api_key' => '', 'vonage_api_secret' => '', 'vonage_phone_number' => ''],
                'messagebird' => ['enabled' => false, 'messagebird_access_key' => '', 'messagebird_originator' => ''],
                'plivo' => ['enabled' => false, 'plivo_auth_id' => '', 'plivo_auth_token' => '', 'plivo_phone_number' => ''],
                'sinch' => ['enabled' => false, 'sinch_service_plan_id' => '', 'sinch_api_token' => '', 'sinch_phone_number' => ''],
                'telnyx' => ['enabled' => false, 'telnyx_api_key' => '', 'telnyx_phone_number' => ''],
            ]
        ];

        return response()->json([
            'channels_enabled' => $channels ? $channels->value : $defaultChannels,
            'sms_providers_config' => $smsConfig ? $smsConfig->value : $defaultSmsConfig,
        ]);
    }

    /**
     * Save global channels and SMS config settings.
     */
    public function updateChannelsConfig(Request $request): JsonResponse
    {
        $request->validate([
            'channels_enabled' => ['required', 'array'],
            'sms_providers_config' => ['required', 'array'],
            'sms_providers_config.active_provider' => ['required', 'string'],
            'sms_providers_config.providers' => ['required', 'array'],
        ]);

        PlatformSetting::updateOrCreate(
            ['key' => 'channels_enabled'],
            ['value' => $request->channels_enabled]
        );

        PlatformSetting::updateOrCreate(
            ['key' => 'sms_providers_config'],
            ['value' => $request->sms_providers_config]
        );

        return response()->json([
            'message' => 'Channels settings saved successfully.',
            'channels_enabled' => PlatformSetting::where('key', 'channels_enabled')->first()->value,
            'sms_providers_config' => PlatformSetting::where('key', 'sms_providers_config')->first()->value,
        ]);
    }

    /**
     * Test real SMS dispatch using unsaved credential inputs.
     */
    public function testSmsGateway(Request $request): JsonResponse
    {
        $request->validate([
            'provider' => ['required', 'string', 'in:twilio,vonage,messagebird,plivo,sinch,telnyx'],
            'credentials' => ['required', 'array'],
            'recipient' => ['required', 'string'],
            'message' => ['required', 'string'],
        ]);

        $providerName = $request->provider;
        $credentials = $request->credentials;
        $recipient = $request->recipient;
        $messageText = $request->message;

        try {
            switch ($providerName) {
                case 'twilio':
                    $provider = new \App\Services\SMS\Providers\TwilioSmsProvider();
                    break;
                case 'vonage':
                    $provider = new \App\Services\SMS\Providers\VonageSmsProvider();
                    break;
                case 'plivo':
                    $provider = new \App\Services\SMS\Providers\PlivoSmsProvider();
                    break;
                case 'messagebird':
                    $provider = new \App\Services\SMS\Providers\MessageBirdSmsProvider();
                    break;
                case 'sinch':
                    $provider = new \App\Services\SMS\Providers\SinchSmsProvider();
                    break;
                case 'telnyx':
                    $provider = new \App\Services\SMS\Providers\TelnyxSmsProvider();
                    break;
                default:
                    return response()->json([
                        'success' => false,
                        'message' => "Unsupported SMS provider: {$providerName}"
                    ], 422);
            }

            $res = $provider->sendSms($credentials, $recipient, $messageText);

            if (($res['delivery_status'] ?? '') === 'sent') {
                return response()->json([
                    'success' => true,
                    'message' => 'SMS sent successfully. External SID: ' . ($res['external_message_id'] ?? 'N/A')
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $res['error_message'] ?? 'Unknown gateway delivery error.'
                ], 422);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Exception: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get global AI providers active state and model lists.
     */
    /**
     * Get global AI providers active state, operational model, keys metadata, and feature routing matrix.
     */
    public function getAiProvidersConfig(AIProviderService $aiService): JsonResponse
    {
        $setting = PlatformSetting::where('key', 'ai_providers_config')->first();

        $defaultConfig = [
            'openai' => [
                'enabled' => true,
                'models' => [
                    ['id' => 'gpt-4o', 'name' => 'GPT-4o', 'enabled' => true, 'custom' => false],
                    ['id' => 'gpt-4o-mini', 'name' => 'GPT-4o Mini', 'enabled' => true, 'custom' => false],
                    ['id' => 'o1-mini', 'name' => 'o1 Mini', 'enabled' => true, 'custom' => false],
                ],
            ],
            'anthropic' => [
                'enabled' => true,
                'models' => [
                    ['id' => 'claude-3-5-sonnet-20241022', 'name' => 'Claude 3.5 Sonnet', 'enabled' => true, 'custom' => false],
                    ['id' => 'claude-3-5-haiku-20241022', 'name' => 'Claude 3.5 Haiku', 'enabled' => true, 'custom' => false],
                ],
            ],
            'gemini' => [
                'enabled' => true,
                'models' => [
                    ['id' => 'gemini-1.5-pro', 'name' => 'Gemini 1.5 Pro', 'enabled' => true, 'custom' => false],
                    ['id' => 'gemini-1.5-flash', 'name' => 'Gemini 1.5 Flash', 'enabled' => true, 'custom' => false],
                ],
            ],
            'groq' => [
                'enabled' => true,
                'models' => [
                    ['id' => 'openai/gpt-oss-120b', 'name' => 'GPT OSS 120B (Fast)', 'enabled' => true, 'custom' => false],
                    ['id' => 'openai/gpt-oss-20b', 'name' => 'GPT OSS 20B (Instant)', 'enabled' => true, 'custom' => false],
                    ['id' => 'qwen/qwen3.6-27b', 'name' => 'Qwen 3.6 27B', 'enabled' => true, 'custom' => false],
                    ['id' => 'groq/compound', 'name' => 'Groq Compound', 'enabled' => true, 'custom' => false],
                ],
            ],
            'deepseek' => [
                'enabled' => false,
                'models' => [
                    ['id' => 'deepseek-chat', 'name' => 'DeepSeek V3', 'enabled' => true, 'custom' => false],
                    ['id' => 'deepseek-reasoner', 'name' => 'DeepSeek R1', 'enabled' => true, 'custom' => false],
                ],
            ],
            'xai' => [
                'enabled' => false,
                'models' => [
                    ['id' => 'grok-2-1212', 'name' => 'Grok 2', 'enabled' => true, 'custom' => false],
                ],
            ],
            'mistral' => [
                'enabled' => false,
                'models' => [
                    ['id' => 'mistral-large-latest', 'name' => 'Mistral Large', 'enabled' => true, 'custom' => false],
                    ['id' => 'codestral-latest', 'name' => 'Codestral', 'enabled' => true, 'custom' => false],
                ],
            ],
            'openrouter' => [
                'enabled' => false,
                'models' => [
                    ['id' => 'meta-llama/llama-3.3-70b-instruct', 'name' => 'Llama 3.3 70B (OpenRouter)', 'enabled' => true, 'custom' => false],
                ],
            ]
        ];

        $operationalModel = $aiService->getAiOperationalModel();
        $adminKeysRaw = $aiService->getAdminProvidersKeys();
        $routing = $aiService->getAiFeatureRouting();
        $features = $aiService->getFeaturesList();

        // Mask admin keys for secure frontend delivery
        $maskedKeys = [];
        $providers = ['openai', 'anthropic', 'gemini', 'groq', 'deepseek', 'xai', 'mistral', 'openrouter'];
        foreach ($providers as $prov) {
            $key = $adminKeysRaw[$prov] ?? env(strtoupper($prov) . '_API_KEY', '');
            $hasKey = !empty($key);
            $preview = '';
            if ($hasKey && strlen($key) > 8) {
                $preview = substr($key, 0, 4) . '••••' . substr($key, -4);
            } elseif ($hasKey) {
                $preview = '••••••••';
            }
            $maskedKeys[$prov] = [
                'has_key' => $hasKey,
                'preview' => $preview,
            ];
        }

        $safeguards = $aiService->getAiSafeguardsConfig();
        
        // Month to date platform stats
        $startOfMonth = now()->startOfMonth();
        $endOfMonth = now()->endOfMonth();
        $monthTokensUsed = (int) \App\Models\AiUsageLog::whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->where('status', 'success')
            ->sum('total_tokens');
        $monthEstimatedCost = (float) \App\Models\AiUsageLog::whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->where('status', 'success')
            ->sum('estimated_cost');

        return response()->json([
            'operational_model' => $operationalModel,
            'ai_providers_config' => $setting ? $setting->value : $defaultConfig,
            'ai_providers_keys' => $maskedKeys,
            'ai_feature_routing' => $routing,
            'ai_safeguards_config' => $safeguards,
            'features_list' => $features,
            'platform_usage_stats' => [
                'month_tokens_used' => $monthTokensUsed,
                'month_estimated_cost' => round($monthEstimatedCost, 4),
            ],
        ]);
    }

    /**
     * Save AI settings, operational model, keys, feature routing, and safeguards.
     */
    public function updateAiProvidersConfig(Request $request, AIProviderService $aiService): JsonResponse
    {
        $request->validate([
            'operational_model' => ['nullable', 'string', 'in:byok,master_fixed'],
            'ai_providers_config' => ['nullable', 'array'],
            'ai_providers_keys' => ['nullable', 'array'],
            'ai_feature_routing' => ['nullable', 'array'],
            'ai_safeguards_config' => ['nullable', 'array'],
        ]);

        if ($request->has('operational_model')) {
            $aiService->setAiOperationalModel($request->operational_model);
        }

        if ($request->has('ai_providers_config')) {
            PlatformSetting::updateOrCreate(
                ['key' => 'ai_providers_config'],
                ['value' => $request->ai_providers_config]
            );
        }

        if ($request->has('ai_providers_keys')) {
            $aiService->setAdminProvidersKeys($request->ai_providers_keys);
        }

        if ($request->has('ai_feature_routing')) {
            $aiService->setAiFeatureRouting($request->ai_feature_routing);
        }

        if ($request->has('ai_safeguards_config')) {
            $aiService->setAiSafeguardsConfig($request->ai_safeguards_config);
        }

        $data = $this->getAiProvidersConfig($aiService)->getData(true);
        $data['message'] = 'AI providers and safeguards configuration saved successfully.';

        return response()->json($data);
    }

    /**
     * Test an Admin AI API Key connection.
     */
    public function testAdminAiKey(Request $request, AIProviderService $aiService): JsonResponse
    {
        $request->validate([
            'provider' => ['required', 'string'],
            'api_key' => ['nullable', 'string'],
        ]);

        $provider = strtolower($request->provider);
        $apiKey = $request->api_key;

        // If key was not sent in request or masked, use stored key
        if (empty($apiKey) || str_contains($apiKey, '••••')) {
            $adminKeys = $aiService->getAdminProvidersKeys();
            $apiKey = $adminKeys[$provider] ?? env(strtoupper($provider) . '_API_KEY', '');
        }

        if (empty($apiKey)) {
            return response()->json([
                'success' => false,
                'message' => "No API key configured for provider '{$provider}'."
            ], 422);
        }

        try {
            $aiService->validateKey($provider, $apiKey);
            return response()->json([
                'success' => true,
                'message' => "Connection test passed. Master API key for '{$provider}' is active and valid."
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Get platform wide integrations configuration.
     */
    public function getIntegrationsConfig(): JsonResponse
    {
        $setting = PlatformSetting::where('key', 'integrations_enabled_config')->first();

        $defaultConfig = [
            'google_sheets' => true,
            'google_calendar' => true,
            'zoom' => true,
            'teams' => true,
            'hubspot' => true,
            'salesforce' => true,
            'zoho' => true,
            'shopify' => true,
            'woocommerce' => true,
        ];

        $enabledConfig = $setting ? $setting->value : $defaultConfig;

        $envStatus = [
            'google_sheets' => !empty(config('services.google.client_id')) && !empty(config('services.google.client_secret')),
            'google_calendar' => !empty(config('services.google.client_id')) && !empty(config('services.google.client_secret')),
            'zoom' => !empty(config('services.zoom.client_id')) && !empty(config('services.zoom.client_secret')),
            'teams' => !empty(config('services.teams.client_id')) && !empty(config('services.teams.client_secret')),
            'hubspot' => !empty(config('services.hubspot.client_id')) && !empty(config('services.hubspot.client_secret')),
            'salesforce' => !empty(config('services.salesforce.client_id')) && !empty(config('services.salesforce.client_secret')),
            'zoho' => !empty(config('services.zoho.client_id')) && !empty(config('services.zoho.client_secret')),
            'shopify' => true,
            'woocommerce' => true,
        ];

        return response()->json([
            'integrations_enabled_config' => $enabledConfig,
            'env_status' => $envStatus,
        ]);
    }

    /**
     * Update platform wide integrations configuration.
     */
    public function updateIntegrationsConfig(Request $request): JsonResponse
    {
        $request->validate([
            'integrations_enabled_config' => ['required', 'array'],
        ]);

        PlatformSetting::updateOrCreate(
            ['key' => 'integrations_enabled_config'],
            ['value' => $request->integrations_enabled_config]
        );

        return response()->json([
            'message' => 'Integrations configuration saved successfully.',
            'integrations_enabled_config' => PlatformSetting::where('key', 'integrations_enabled_config')->first()->value,
        ]);
    }

}
