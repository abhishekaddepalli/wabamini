<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Exception;

class AIProviderService
{
    /**
     * Get list of all supported AI providers and their friendly details.
     */
    public function getSupportedProviders(): array
    {
        $defaultProviders = [
            'openai' => [
                'name' => 'OpenAI',
                'description' => 'Industry standard models like GPT-4o and GPT-4o-mini.',
                'website' => 'https://platform.openai.com',
            ],
            'anthropic' => [
                'name' => 'Anthropic Claude',
                'description' => 'State-of-the-art reasoning models like Claude 3.5 Sonnet.',
                'website' => 'https://console.anthropic.com',
            ],
            'gemini' => [
                'name' => 'Google Gemini',
                'description' => 'Google\'s multimodal models, including Gemini 2.5 Flash and Pro.',
                'website' => 'https://aistudio.google.com',
            ],
            'groq' => [
                'name' => 'Groq LPU',
                'description' => 'Ultra-fast inference engine running open models like Llama 3.',
                'website' => 'https://console.groq.com',
            ],
            'deepseek' => [
                'name' => 'DeepSeek',
                'description' => 'High-efficiency open weights models including DeepSeek Chat.',
                'website' => 'https://platform.deepseek.com',
            ],
            'xai' => [
                'name' => 'xAI Grok',
                'description' => 'Elon Musk\'s xAI Grok intelligence models.',
                'website' => 'https://console.x.ai',
            ],
            'mistral' => [
                'name' => 'Mistral AI',
                'description' => 'Premium European open weight models like Mistral Large.',
                'website' => 'https://console.mistral.ai',
            ],
            'openrouter' => [
                'name' => 'OpenRouter',
                'description' => 'Aggregated access endpoint to hundreds of open/closed models.',
                'website' => 'https://openrouter.ai',
            ],
        ];

        $setting = \App\Models\PlatformSetting::where('key', 'ai_providers_config')->first();
        
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

        $config = $setting ? $setting->value : $defaultConfig;

        $supported = [];
        foreach ($defaultProviders as $slug => $details) {
            $provConfig = $config[$slug] ?? null;
            if ($provConfig && !empty($provConfig['enabled'])) {
                $enabledModels = [];
                if (isset($provConfig['models']) && is_array($provConfig['models'])) {
                    foreach ($provConfig['models'] as $m) {
                        if (!empty($m['enabled']) && isset($m['id'])) {
                            $enabledModels[] = $m['id'];
                        }
                    }
                }
                
                if (count($enabledModels) > 0) {
                    $supported[$slug] = array_merge($details, [
                        'models' => $enabledModels
                    ]);
                }
            }
        }

        return $supported;
    }

    /**
     * Get the active AI Operational Model ('byok', 'master_fixed').
     */
    public function getAiOperationalModel(): string
    {
        $setting = \App\Models\PlatformSetting::where('key', 'ai_operational_model')->first();
        return $setting ? (string)$setting->value : 'byok';
    }

    /**
     * Set the AI Operational Model.
     */
    public function setAiOperationalModel(string $model): void
    {
        if (!in_array($model, ['byok', 'master_fixed'])) {
            $model = 'byok';
        }
        \App\Models\PlatformSetting::updateOrCreate(
            ['key' => 'ai_operational_model'],
            ['value' => $model]
        );
    }

    /**
     * Get Admin global provider keys map.
     */
    public function getAdminProvidersKeys(): array
    {
        $setting = \App\Models\PlatformSetting::where('key', 'ai_providers_keys')->first();
        return $setting && is_array($setting->value) ? $setting->value : [];
    }

    /**
     * Update Admin global provider keys map.
     */
    public function setAdminProvidersKeys(array $keys): void
    {
        $current = $this->getAdminProvidersKeys();
        foreach ($keys as $provider => $key) {
            if ($key === '' || $key === '__DELETE__') {
                unset($current[strtolower($provider)]);
            } elseif ($key !== null && $key !== '••••••••' && !str_starts_with((string)$key, '••••')) {
                $current[strtolower($provider)] = trim((string)$key);
            }
        }
        \App\Models\PlatformSetting::updateOrCreate(
            ['key' => 'ai_providers_keys'],
            ['value' => $current]
        );
    }

    /**
     * Get the 10 Standard Platform AI Features definitions.
     */
    public function getFeaturesList(): array
    {
        return [
            [
                'id' => 'ai_chatbot',
                'name' => 'AI Chatbot & Inbound Auto-Reply',
                'description' => 'Automated conversational replies to customer inquiries across WhatsApp, Instagram, Telegram, SMS, and web channels.',
                'category' => 'Conversational AI',
            ],
            [
                'id' => 'flow_ai_prompt',
                'name' => 'Flow AI Action & Text Generator Node',
                'description' => 'Generates contextual LLM completions, extractions, and transformations within workflow execution.',
                'category' => 'Visual Flows',
            ],
            [
                'id' => 'flow_ai_condition',
                'name' => 'Flow AI Intent & Sentiment Node',
                'description' => 'Evaluates customer sentiment, intent classification, and logical boolean branching.',
                'category' => 'Visual Flows',
            ],
            [
                'id' => 'prompt_to_flow',
                'name' => 'Prompt to Flow Generator',
                'description' => 'Generates complete visual drag-and-drop automation workflows from natural language prompts.',
                'category' => 'Visual Flows',
            ],
            [
                'id' => 'knowledge_base_rag',
                'name' => 'Knowledge Base Ingestion & RAG',
                'description' => 'Generates vector embeddings and performs semantic search over uploaded documents and FAQs.',
                'category' => 'Knowledge Base',
            ],
        ];
    }

    /**
     * Get the AI Feature Routing Matrix configuration for Superadmin.
     */
    public function getAiFeatureRouting(): array
    {
        $setting = \App\Models\PlatformSetting::where('key', 'ai_feature_routing')->first();
        $defaultRouting = [
            'ai_chatbot' => ['provider' => 'openai', 'model' => 'gpt-4o-mini'],
            'flow_ai_prompt' => ['provider' => 'openai', 'model' => 'gpt-4o-mini'],
            'flow_ai_condition' => ['provider' => 'openai', 'model' => 'gpt-4o-mini'],
            'flow_rag_query' => ['provider' => 'openai', 'model' => 'gpt-4o-mini'],
            'prompt_to_flow' => ['provider' => 'openai', 'model' => 'gpt-4o-mini'],
            'knowledge_base_rag' => ['provider' => 'openai', 'model' => 'gpt-4o-mini'],
        ];

        if ($setting && is_array($setting->value)) {
            return array_merge($defaultRouting, $setting->value);
        }

        return $defaultRouting;
    }

    /**
     * Set the AI Feature Routing Matrix configuration for Superadmin.
     */
    public function setAiFeatureRouting(array $routing): void
    {
        \App\Models\PlatformSetting::updateOrCreate(
            ['key' => 'ai_feature_routing'],
            ['value' => $routing]
        );
    }

    /**
     * Get the tenant-specific AI Feature Routing configuration (BYOK mode).
     */
    public function getTenantAiFeatureRouting(int $tenantId): array
    {
        $setting = \App\Models\PlatformSetting::where('key', "tenant_{$tenantId}_ai_feature_routing")->first();
        
        // Find default active provider for tenant
        $activeConfig = \App\Models\AIProviderConfig::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->first();
        
        $fallbackProvider = $activeConfig ? strtolower($activeConfig->provider_name) : 'openai';
        $fallbackModel = $activeConfig ? ($activeConfig->default_model ?: ($activeConfig->enabled_models[0] ?? 'gpt-4o-mini')) : 'gpt-4o-mini';

        $defaultRouting = [
            'ai_chatbot' => ['provider' => $fallbackProvider, 'model' => $fallbackModel],
            'ai_chatbots' => ['provider' => $fallbackProvider, 'model' => $fallbackModel],
            'flow_ai_prompt' => ['provider' => $fallbackProvider, 'model' => $fallbackModel],
            'flow_ai_condition' => ['provider' => $fallbackProvider, 'model' => $fallbackModel],
            'flow_rag_query' => ['provider' => $fallbackProvider, 'model' => $fallbackModel],
            'rag_kb' => ['provider' => $fallbackProvider, 'model' => $fallbackModel],
            'prompt_to_flow' => ['provider' => $fallbackProvider, 'model' => $fallbackModel],
            'knowledge_base_rag' => ['provider' => $fallbackProvider, 'model' => $fallbackModel],
        ];

        if ($setting && is_array($setting->value)) {
            return array_merge($defaultRouting, $setting->value);
        }

        return $defaultRouting;
    }

    /**
     * Set the tenant-specific AI Feature Routing configuration (BYOK mode).
     */
    public function setTenantAiFeatureRouting(int $tenantId, array $routing): void
    {
        \App\Models\PlatformSetting::updateOrCreate(
            ['key' => "tenant_{$tenantId}_ai_feature_routing"],
            ['value' => $routing]
        );
    }

    /**
     * Get the AI Safeguards & Limit configuration for a specific Tenant (BYOK mode).
     */
    public function getTenantAiSafeguardsConfig(int $tenantId): array
    {
        $default = [
            'rate_limit_per_minute_contact' => 10,
            'max_input_tokens' => 2500,
            'max_output_tokens' => 500,
            'on_limit_breached_action' => 'block_fallback', // 'block_fallback' | 'simulate_mock'
            'fallback_message' => 'AI assistant is temporarily unavailable due to high demand. An agent will assist you shortly.',
        ];

        $setting = \App\Models\PlatformSetting::where('key', "tenant_{$tenantId}_ai_safeguards_config")->first();
        if (!$setting || empty($setting->value) || !is_array($setting->value)) {
            return $default;
        }

        return array_merge($default, $setting->value);
    }

    /**
     * Set the AI Safeguards & Limit configuration for a specific Tenant (BYOK mode).
     */
    public function setTenantAiSafeguardsConfig(int $tenantId, array $config): void
    {
        $current = $this->getTenantAiSafeguardsConfig($tenantId);
        $merged = array_merge($current, $config);

        \App\Models\PlatformSetting::updateOrCreate(
            ['key' => "tenant_{$tenantId}_ai_safeguards_config"],
            ['value' => $merged]
        );
    }

    /**
     * Get the AI Safeguards & Limit configuration for Superadmin.
     */
    public function getAiSafeguardsConfig(): array
    {
        $default = [
            'rate_limit_per_minute_tenant' => 30,
            'rate_limit_per_minute_contact' => 10,
            'max_input_tokens' => 2500,
            'max_output_tokens' => 500,
            'monthly_platform_safety_cap' => 10000000,
            'on_limit_breached_action' => 'block_fallback', // 'block_fallback' | 'simulate_mock'
            'fallback_message' => 'AI assistant is temporarily unavailable due to high demand. An agent will assist you shortly.',
        ];

        $setting = \App\Models\PlatformSetting::where('key', 'ai_safeguards_config')->first();
        if (!$setting || empty($setting->value) || !is_array($setting->value)) {
            return $default;
        }

        return array_merge($default, $setting->value);
    }

    /**
     * Set the AI Safeguards & Limit configuration for Superadmin.
     */
    public function setAiSafeguardsConfig(array $config): void
    {
        $current = $this->getAiSafeguardsConfig();
        $merged = array_merge($current, $config);

        \App\Models\PlatformSetting::updateOrCreate(
            ['key' => 'ai_safeguards_config'],
            ['value' => $merged]
        );
    }

    /**
     * Check if AI execution should be allowed or throttled based on rate limits, plan quotas, and platform cap.
     * Returns null if allowed, or an array with details if throttled.
     */
    public function checkSafeguards(int $tenantId, ?string $contactIdentifier = null, string $feature = 'general'): ?array
    {
        $mode = $this->getAiOperationalModel();

        // In BYOK mode: apply tenant-specific contact rate throttle
        if ($mode === 'byok') {
            $safeguards = $this->getTenantAiSafeguardsConfig($tenantId);

            if (!empty($contactIdentifier)) {
                $contactRateLimit = (int) ($safeguards['rate_limit_per_minute_contact'] ?? 10);
                if ($contactRateLimit > 0) {
                    $contactKey = "ai_rate_contact:{$tenantId}:" . md5($contactIdentifier);
                    if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($contactKey, $contactRateLimit)) {
                        return [
                            'blocked' => true,
                            'reason' => 'contact_rate_limit_exceeded',
                            'fallback_message' => $safeguards['fallback_message'],
                            'action' => $safeguards['on_limit_breached_action'],
                        ];
                    }
                    \Illuminate\Support\Facades\RateLimiter::hit($contactKey, 60);
                }
            }

            return null;
        }

        // Master Fixed mode: checks central safeguards
        $safeguards = $this->getAiSafeguardsConfig();

        // 1. Check Rate Limiter for Tenant
        $tenantRateLimit = (int) ($safeguards['rate_limit_per_minute_tenant'] ?? 30);
        if ($tenantRateLimit > 0) {
            $tenantKey = "ai_rate_tenant:{$tenantId}";
            if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($tenantKey, $tenantRateLimit)) {
                return [
                    'blocked' => true,
                    'reason' => 'tenant_rate_limit_exceeded',
                    'fallback_message' => $safeguards['fallback_message'],
                    'action' => $safeguards['on_limit_breached_action'],
                ];
            }
            \Illuminate\Support\Facades\RateLimiter::hit($tenantKey, 60);
        }

        // 2. Check Rate Limiter for Contact/Session
        if (!empty($contactIdentifier)) {
            $contactRateLimit = (int) ($safeguards['rate_limit_per_minute_contact'] ?? 10);
            if ($contactRateLimit > 0) {
                $contactKey = "ai_rate_contact:{$tenantId}:" . md5($contactIdentifier);
                if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($contactKey, $contactRateLimit)) {
                    return [
                        'blocked' => true,
                        'reason' => 'contact_rate_limit_exceeded',
                        'fallback_message' => $safeguards['fallback_message'],
                        'action' => $safeguards['on_limit_breached_action'],
                    ];
                }
                \Illuminate\Support\Facades\RateLimiter::hit($contactKey, 60);
            }
        }

        // 3. Check Monthly Plan Quota for Tenant in Master Fixed mode
        $tenant = \App\Models\Tenant::with('plan')->find($tenantId);
        if ($tenant && $tenant->plan) {
            $monthlyAllowance = (int) ($tenant->plan->monthly_ai_tokens ?? 100000);
            // 0 means unlimited
            if ($monthlyAllowance > 0) {
                $startOfMonth = now()->startOfMonth();
                $endOfMonth = now()->endOfMonth();
                
                $usedTokens = (int) \App\Models\AiUsageLog::where('tenant_id', $tenantId)
                    ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                    ->where('status', 'success')
                    ->sum('total_tokens');

                if ($usedTokens >= $monthlyAllowance) {
                    return [
                        'blocked' => true,
                        'reason' => 'monthly_tenant_quota_exceeded',
                        'fallback_message' => $safeguards['fallback_message'],
                        'action' => $safeguards['on_limit_breached_action'],
                        'used_tokens' => $usedTokens,
                        'allowance' => $monthlyAllowance,
                    ];
                }
            }
        }

        // 4. Check Global Platform Monthly Safety Cap
        $globalSafetyCap = (int) ($safeguards['monthly_platform_safety_cap'] ?? 10000000);
        if ($globalSafetyCap > 0) {
            $startOfMonth = now()->startOfMonth();
            $endOfMonth = now()->endOfMonth();

            $totalPlatformTokens = (int) \App\Models\AiUsageLog::whereBetween('created_at', [$startOfMonth, $endOfMonth])
                ->where('status', 'success')
                ->sum('total_tokens');

            if ($totalPlatformTokens >= $globalSafetyCap) {
                return [
                    'blocked' => true,
                    'reason' => 'platform_global_cap_exceeded',
                    'fallback_message' => $safeguards['fallback_message'],
                    'action' => $safeguards['on_limit_breached_action'],
                    'total_platform_tokens' => $totalPlatformTokens,
                    'global_cap' => $globalSafetyCap,
                ];
            }
        }

        return null;
    }

    /**
     * Truncate prompt context if it exceeds max input tokens.
     */
    public function clampMessagesToTokenLimit(array $messages, int $maxInputTokens = 2500): array
    {
        $currentTokens = self::estimateTokens($messages);
        if ($currentTokens <= $maxInputTokens || empty($messages)) {
            return $messages;
        }

        // Preserve system prompt if present, truncate earliest user/assistant message turns
        $systemMsg = null;
        $otherMsgs = [];
        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') === 'system') {
                $systemMsg = $msg;
            } else {
                $otherMsgs[] = $msg;
            }
        }

        // If system prompt itself is huge, truncate it
        if ($systemMsg) {
            $sysTokens = self::estimateTokens($systemMsg['content'] ?? '');
            $maxSysTokens = (int) round($maxInputTokens * 0.6);
            if ($sysTokens > $maxSysTokens) {
                $maxChars = $maxSysTokens * 4;
                $systemMsg['content'] = mb_substr((string)$systemMsg['content'], 0, $maxChars) . "\n[Context truncated for token limits]";
            }
        }

        // Keep the most recent messages that fit the remaining token budget
        $budget = $maxInputTokens - ($systemMsg ? self::estimateTokens($systemMsg['content'] ?? '') : 0);
        $clampedOthers = [];
        for ($i = count($otherMsgs) - 1; $i >= 0; $i--) {
            $msgTokens = self::estimateTokens($otherMsgs[$i]['content'] ?? '');
            if ($budget - $msgTokens >= 0 || empty($clampedOthers)) {
                array_unshift($clampedOthers, $otherMsgs[$i]);
                $budget -= $msgTokens;
            } else {
                break;
            }
        }

        $result = [];
        if ($systemMsg) {
            $result[] = $systemMsg;
        }
        foreach ($clampedOthers as $m) {
            $result[] = $m;
        }

        return $result;
    }

    /**
     * Unified resolver for AI executions across BYOK, Master Fixed, and Master User Selectable.
     */
    public function resolveAIExecution(
        int $tenantId,
        string $featureKey,
        ?string $userPreferredProvider = null,
        ?string $userPreferredModel = null,
        ?string $contactIdentifier = null
    ): array {
        $aliases = [
            'ai_chatbots' => 'ai_chatbot',
            'ai_agents' => 'ai_chatbot',
            'inbox_smart_reply' => 'ai_chatbot',
            'inbox_conversation_summary' => 'ai_chatbot',
            'contact_sentiment_analysis' => 'ai_chatbot',
            'campaign_copywriter' => 'ai_chatbot',
            'rag_kb' => 'knowledge_base_rag',
            'flow_rag_query' => 'knowledge_base_rag',
            'knowledge_base_rag' => 'rag_kb',
            'ai_chatbot' => 'ai_chatbots',
        ];
        $altKey = $aliases[$featureKey] ?? null;

        $mode = $this->getAiOperationalModel();
        $adminKeys = $this->getAdminProvidersKeys();
        $safeguards = $mode === 'byok' ? $this->getTenantAiSafeguardsConfig($tenantId) : $this->getAiSafeguardsConfig();

        // Check safeguards
        $safeguardCheck = $this->checkSafeguards($tenantId, $contactIdentifier, $featureKey);
        $isBlocked = $safeguardCheck !== null;
        $fallbackMessage = $safeguardCheck['fallback_message'] ?? $safeguards['fallback_message'];
        $blockReason = $safeguardCheck['reason'] ?? null;
        $action = $safeguardCheck['action'] ?? $safeguards['on_limit_breached_action'];

        // 1. MASTER FIXED MODE: Centrally routed via Admin matrix
        if ($mode === 'master_fixed') {
            $routing = $this->getAiFeatureRouting();
            $featureRoute = $routing[$featureKey] ?? ($altKey ? ($routing[$altKey] ?? null) : null);
            $provider = strtolower($featureRoute['provider'] ?? '');

            // Ensure provider has a master key configured by superadmin
            if (empty($provider) || empty($adminKeys[$provider])) {
                $configuredAdminProviders = array_keys(array_filter($adminKeys, fn($k) => !empty($k)));
                $provider = $configuredAdminProviders[0] ?? ($provider ?: 'groq');
            }

            $model = $this->resolveModel($provider, $featureRoute['model'] ?? null);
            $apiKey = $adminKeys[$provider] ?? env(strtoupper($provider) . '_API_KEY', '');

            return [
                'provider' => $provider,
                'model' => $model,
                'api_key' => $apiKey ?: 'mock_admin_key',
                'mode' => 'master_fixed',
                'is_simulated' => empty($apiKey) || ($isBlocked && $action === 'simulate_mock'),
                'is_blocked' => $isBlocked && $action === 'block_fallback',
                'fallback_message' => $fallbackMessage,
                'block_reason' => $blockReason,
                'safeguards' => $safeguards,
            ];
        }

        // 2. BYOK MODE: User provides their own key via AIProviderConfig, centralized via tenant routing
        $tenantRouting = $this->getTenantAiFeatureRouting($tenantId);
        $featureRoute = $tenantRouting[$featureKey] ?? ($altKey ? ($tenantRouting[$altKey] ?? null) : null);

        $targetProvider = !empty($featureRoute['provider']) ? strtolower($featureRoute['provider']) : strtolower((string)$userPreferredProvider);
        $targetModel = !empty($featureRoute['model']) ? $featureRoute['model'] : $userPreferredModel;

        $config = null;
        if (!empty($targetProvider)) {
            $config = \App\Models\AIProviderConfig::where('tenant_id', $tenantId)
                ->where('provider_name', $targetProvider)
                ->where('is_active', true)
                ->first();
        }

        // Fallback to any active provider config for tenant if chosen provider is inactive or not found
        if (!$config) {
            $config = \App\Models\AIProviderConfig::where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->first();
        }

        if ($config) {
            $provider = strtolower($config->provider_name);
            $model = $this->resolveModel($provider, $targetModel ?: $config->default_model);
            $apiKey = $config->api_key;

            return [
                'provider' => $provider,
                'model' => $model,
                'api_key' => $apiKey,
                'mode' => 'byok',
                'is_simulated' => empty($apiKey) || ($isBlocked && $action === 'simulate_mock'),
                'is_blocked' => $isBlocked && $action === 'block_fallback',
                'fallback_message' => $fallbackMessage,
                'block_reason' => $blockReason,
                'safeguards' => $safeguards,
            ];
        }

        // Fallback if tenant has no key in BYOK: simulated response
        $provider = 'groq';
        $model = 'openai/gpt-oss-120b';
        $apiKey = '';

        return [
            'provider' => $provider,
            'model' => $model,
            'api_key' => 'mock_simulated_key',
            'mode' => 'byok',
            'is_simulated' => true,
            'is_blocked' => $isBlocked && $action === 'block_fallback',
            'fallback_message' => $fallbackMessage,
            'block_reason' => $blockReason,
            'safeguards' => $safeguards,
        ];
    }

    /**
     * Resolve and validate provider model names to prevent runtime mismatches.
     */
    public function resolveModel(string $provider, ?string $model): string
    {
        $provider = strtolower($provider);
        $cleanModel = trim((string)$model);

        $defaultModels = [
            'openai' => 'gpt-4o-mini',
            'anthropic' => 'claude-3-5-haiku-20241022',
            'gemini' => 'gemini-1.5-flash',
            'groq' => 'openai/gpt-oss-120b',
            'deepseek' => 'deepseek-chat',
            'xai' => 'grok-2-1212',
            'mistral' => 'mistral-large-latest',
            'openrouter' => 'meta-llama/llama-3.3-70b-instruct',
        ];

        if ($provider === 'groq') {
            if (empty($cleanModel) || in_array($cleanModel, ['mixtral-8x7b-32768', 'llama-3.3-70b-versatile', 'llama3-70b-8192', 'llama3-8b-8192'])) {
                return 'openai/gpt-oss-120b';
            }
        }

        if (empty($cleanModel)) {
            return $defaultModels[$provider] ?? 'gpt-4o-mini';
        }

        return $cleanModel;
    }

    /**
     * Clean raw completion text (e.g. remove thinking tokens).
     */
    private function cleanCompletionText(string $text): string
    {
        $cleaned = preg_replace('/<think>[\s\S]*?<\/think>/i', '', $text);
        return trim($cleaned);
    }

    /**
     * Validate the API key against the provider by making a real connection call.
     * Returns true if valid, or throws an Exception with the failure reason.
     */
    public function validateKey(string $provider, string $apiKey): bool
    {
        if (empty($apiKey)) {
            throw new Exception("API Key cannot be empty.");
        }

        try {
            switch ($provider) {
                case 'openai':
                    $response = Http::withToken($apiKey)
                        ->timeout(6)
                        ->get('https://api.openai.com/v1/models');
                    break;

                case 'anthropic':
                    $response = Http::withHeaders([
                        'x-api-key' => $apiKey,
                        'anthropic-version' => '2023-06-01',
                    ])
                    ->timeout(6)
                    ->get('https://api.anthropic.com/v1/models');
                    break;

                case 'gemini':
                    $response = Http::timeout(6)
                        ->get("https://generativelanguage.googleapis.com/v1beta/models?key={$apiKey}");
                    break;

                case 'groq':
                    $response = Http::withToken($apiKey)
                        ->timeout(6)
                        ->get('https://api.groq.com/openai/v1/models');
                    break;

                case 'deepseek':
                    $response = Http::withToken($apiKey)
                        ->timeout(6)
                        ->get('https://api.deepseek.com/models');
                    break;

                case 'xai':
                    $response = Http::withToken($apiKey)
                        ->timeout(6)
                        ->get('https://api.x.ai/v1/models');
                    break;

                case 'mistral':
                    $response = Http::withToken($apiKey)
                        ->timeout(6)
                        ->get('https://api.mistral.ai/v1/models');
                    break;

                case 'openrouter':
                    $response = Http::withToken($apiKey)
                        ->timeout(6)
                        ->get('https://openrouter.ai/api/v1/models');
                    break;

                default:
                    throw new Exception("Unsupported AI provider: {$provider}");
            }

            if ($response->successful()) {
                return true;
            }

            $errorData = $response->json();
            $errorMessage = $errorData['error']['message'] ?? $errorData['error'] ?? 'Unauthorized or invalid credentials.';
            throw new Exception("Verification failed: " . $errorMessage);

        } catch (Exception $e) {
            throw new Exception("Connection check failed: " . $e->getMessage());
        }
    }

    /**
     * Record AI usage telemetry log to database.
     */
    public static function recordUsage(
        ?int $tenantId,
        ?int $userId,
        string $feature,
        string $provider,
        string $model,
        int $promptTokens,
        int $completionTokens,
        int $latencyMs = 0,
        string $status = 'success',
        ?string $errorMessage = null,
        ?array $metadata = null
    ): void {
        try {
            if (!$tenantId) {
                $tenantId = auth()->check() ? (int) auth()->user()->tenant_id : 1;
            }
            if (!$userId && auth()->check()) {
                $userId = auth()->id();
            }

            $aliasMap = [
                'chatbot' => 'ai_chatbot',
                'ai_chatbots' => 'ai_chatbot',
                'agent' => 'ai_chatbot',
                'ai_agents' => 'ai_chatbot',
                'smart_reply' => 'ai_chatbot',
                'campaign' => 'flow_ai_prompt',
                'flow_condition' => 'flow_ai_condition',
                'flow_prompt' => 'flow_ai_prompt',
                'prompt_flow' => 'prompt_to_flow',
                'knowledge_rag' => 'knowledge_base_rag',
                'rag_kb' => 'knowledge_base_rag',
                'general' => 'ai_chatbot',
            ];
            $normalizedFeature = $aliasMap[$feature] ?? ($feature ?: 'ai_chatbot');

            $totalTokens = $promptTokens + $completionTokens;
            $estimatedCost = \App\Models\AiUsageLog::calculateEstimatedCost($model, $promptTokens, $completionTokens);

            \App\Models\AiUsageLog::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'feature' => $normalizedFeature,
                'provider' => strtolower($provider ?: 'openai'),
                'model' => $model ?: 'gpt-4o-mini',
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
                'estimated_cost' => $estimatedCost,
                'latency_ms' => $latencyMs,
                'status' => $status,
                'error_message' => $errorMessage,
                'metadata' => $metadata,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Failed to record AI usage log: " . $e->getMessage());
        }
    }

    /**
     * Accurately estimate token count for strings or message arrays.
     */
    public static function estimateTokens(string|array|null $input): int
    {
        if (empty($input)) return 0;
        
        if (is_array($input)) {
            $text = '';
            foreach ($input as $msg) {
                $text .= ' ' . ($msg['content'] ?? '');
            }
        } else {
            $text = (string) $input;
        }

        $wordCount = str_word_count($text);
        if ($wordCount > 0) {
            return (int) max(1, ceil($wordCount * 1.33));
        }
        return (int) max(1, ceil(mb_strlen($text) / 4));
    }

    /**
     * Unified Chat Completion wrapper for backwards compatibility and direct invocation.
     */
    public function chatCompletion(
        array $messages,
        ?string $provider = null,
        ?string $model = null,
        $providerConfig = null,
        float $temperature = 0.7
    ): string {
        $apiKey = '';
        $resolvedProvider = strtolower($provider ?: 'groq');
        $resolvedModel = $model ?: 'llama-3.3-70b-versatile';

        if ($providerConfig instanceof \App\Models\AIProviderConfig) {
            $apiKey = $providerConfig->api_key;
            $resolvedProvider = strtolower($providerConfig->provider_name ?: $resolvedProvider);
            $resolvedModel = $resolvedModel ?: $providerConfig->default_model;
        } elseif (is_string($providerConfig) && !empty($providerConfig)) {
            $apiKey = $providerConfig;
        }

        if (empty($apiKey)) {
            $tenantId = auth()->check() ? (int) auth()->user()->tenant_id : 1;
            $resolved = $this->resolveAIExecution($tenantId, 'ai_chatbots', $resolvedProvider, $resolvedModel);
            $resolvedProvider = $resolved['provider'];
            $resolvedModel = $resolved['model'];
            $apiKey = $resolved['api_key'];
        }

        return $this->generateCompletion(
            $resolvedProvider,
            $apiKey,
            $resolvedModel,
            $messages,
            $temperature,
            'chatbot'
        );
    }

    /**
     * Generate text completion using selected provider, fallback to mock responses if key is missing/unusable.
     */
    public function generateCompletion(
        string $provider, 
        string $apiKey, 
        string $model, 
        array $messages, 
        float $temperature = 0.7, 
        string $feature = 'general',
        ?int $tenantId = null
    ): string
    {
        $startTime = microtime(true);
        $mode = $this->getAiOperationalModel();
        if ($mode === 'byok' && $tenantId) {
            $safeguards = $this->getTenantAiSafeguardsConfig($tenantId);
        } else {
            $safeguards = $this->getAiSafeguardsConfig();
        }
        $maxInputTokens = (int) ($safeguards['max_input_tokens'] ?? 2500);
        $maxOutputTokens = (int) ($safeguards['max_output_tokens'] ?? 500);

        // Strict input token clamp
        $messages = $this->clampMessagesToTokenLimit($messages, $maxInputTokens);
        $promptTokensEst = self::estimateTokens($messages);

        if (empty($apiKey) || $apiKey === 'mock_key' || str_starts_with($apiKey, 'mock')) {
            $mockRes = $this->getMockCompletion($messages);
            $completionTokensEst = self::estimateTokens($mockRes);
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
            self::recordUsage(null, null, $feature, $provider, $model, $promptTokensEst, $completionTokensEst, $latencyMs, 'success');
            return $mockRes;
        }

        try {
            $provider = strtolower($provider);
            $model = $this->resolveModel($provider, $model);
            
            // 1. OpenAI-compatible endpoints (OpenAI, Groq, DeepSeek, xAI, Mistral, OpenRouter)
            if (in_array($provider, ['openai', 'groq', 'deepseek', 'xai', 'mistral', 'openrouter'])) {
                $urls = [
                    'openai' => 'https://api.openai.com/v1/chat/completions',
                    'groq' => 'https://api.groq.com/openai/v1/chat/completions',
                    'deepseek' => 'https://api.deepseek.com/v1/chat/completions',
                    'xai' => 'https://api.x.ai/v1/chat/completions',
                    'mistral' => 'https://api.mistral.ai/v1/chat/completions',
                    'openrouter' => 'https://openrouter.ai/api/v1/chat/completions',
                ];

                $response = Http::withToken($apiKey)
                    ->timeout(15)
                    ->post($urls[$provider], [
                        'model' => $model,
                        'messages' => $messages,
                        'temperature' => $temperature,
                        'max_tokens' => $maxOutputTokens,
                    ]);

                $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

                if ($response->successful()) {
                    $json = $response->json();
                    $cleaned = $this->cleanCompletionText($json['choices'][0]['message']['content'] ?? '');
                    
                    $promptTokens = $json['usage']['prompt_tokens'] ?? $promptTokensEst;
                    $completionTokens = $json['usage']['completion_tokens'] ?? self::estimateTokens($cleaned);
                    
                    self::recordUsage(null, null, $feature, $provider, $model, $promptTokens, $completionTokens, $latencyMs, 'success');

                    return $cleaned;
                }

                $err = "Provider error: " . $response->body();
                self::recordUsage(null, null, $feature, $provider, $model, $promptTokensEst, 0, $latencyMs, 'failed', $err);
                throw new Exception($err);
            }

            // 2. Anthropic Claude messages API
            if ($provider === 'anthropic') {
                $system = '';
                $claudeMessages = [];

                foreach ($messages as $msg) {
                    if ($msg['role'] === 'system') {
                        $system = $msg['content'];
                    } else {
                        $claudeMessages[] = [
                            'role' => $msg['role'] === 'assistant' ? 'assistant' : 'user',
                            'content' => $msg['content']
                        ];
                    }
                }

                $body = [
                    'model' => $model,
                    'messages' => $claudeMessages,
                    'max_tokens' => $maxOutputTokens,
                    'temperature' => $temperature,
                ];

                if (!empty($system)) {
                    $body['system'] = $system;
                }

                $response = Http::withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                ->timeout(15)
                ->post('https://api.anthropic.com/v1/messages', $body);

                $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

                if ($response->successful()) {
                    $json = $response->json();
                    $text = $json['content'][0]['text'] ?? '';
                    
                    $promptTokens = $json['usage']['input_tokens'] ?? $promptTokensEst;
                    $completionTokens = $json['usage']['output_tokens'] ?? self::estimateTokens($text);

                    self::recordUsage(null, null, $feature, $provider, $model, $promptTokens, $completionTokens, $latencyMs, 'success');

                    return $text;
                }

                $err = "Anthropic error: " . $response->body();
                self::recordUsage(null, null, $feature, $provider, $model, $promptTokensEst, 0, $latencyMs, 'failed', $err);
                throw new Exception($err);
            }

            // 3. Google Gemini generateContent API
            if ($provider === 'gemini') {
                $contents = [];
                $systemInstruction = null;

                foreach ($messages as $msg) {
                    if ($msg['role'] === 'system') {
                        $systemInstruction = [
                            'parts' => [
                                ['text' => $msg['content']]
                            ]
                        ];
                    } else {
                        $contents[] = [
                            'role' => $msg['role'] === 'assistant' ? 'model' : 'user',
                            'parts' => [
                                ['text' => $msg['content']]
                            ]
                        ];
                    }
                }

                $body = ['contents' => $contents];
                if ($systemInstruction) {
                    $body['systemInstruction'] = $systemInstruction;
                }

                $body['generationConfig'] = [
                    'temperature' => $temperature,
                    'maxOutputTokens' => $maxOutputTokens,
                ];

                $response = Http::timeout(15)
                    ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}", $body);

                $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

                if ($response->successful()) {
                    $json = $response->json();
                    $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
                    
                    $promptTokens = $json['usageMetadata']['promptTokenCount'] ?? $promptTokensEst;
                    $completionTokens = $json['usageMetadata']['candidatesTokenCount'] ?? self::estimateTokens($text);

                    self::recordUsage(null, null, $feature, $provider, $model, $promptTokens, $completionTokens, $latencyMs, 'success');

                    return $text;
                }

                $err = "Gemini error: " . $response->body();
                self::recordUsage(null, null, $feature, $provider, $model, $promptTokensEst, 0, $latencyMs, 'failed', $err);
                throw new Exception($err);
            }

            throw new Exception("Unsupported AI provider: {$provider}");

        } catch (Exception $e) {
            \Illuminate\Support\Facades\Log::warning("AI completion generation failed: " . $e->getMessage() . ". Falling back to mock completion.");
            $mockRes = $this->getMockCompletion($messages);
            $completionTokensEst = self::estimateTokens($mockRes);
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
            self::recordUsage(null, null, $feature, $provider, $model, $promptTokensEst, $completionTokensEst, $latencyMs, 'success');
            return $mockRes;
        }
    }

    /**
     * Generate text completion with tool calling using selected provider, fallback to mock tool calling if key is missing.
     */
    public function generateCompletionWithTools(
        string $provider, 
        string $apiKey, 
        string $model, 
        array $messages, 
        array $tools, 
        float $temperature = 0.7, 
        string $feature = 'agent',
        ?int $tenantId = null
    ): array
    {
        $startTime = microtime(true);
        $mode = $this->getAiOperationalModel();
        if ($mode === 'byok' && $tenantId) {
            $safeguards = $this->getTenantAiSafeguardsConfig($tenantId);
        } else {
            $safeguards = $this->getAiSafeguardsConfig();
        }
        $maxInputTokens = (int) ($safeguards['max_input_tokens'] ?? 2500);
        $maxOutputTokens = (int) ($safeguards['max_output_tokens'] ?? 500);

        // Strict input context clamp
        $messages = $this->clampMessagesToTokenLimit($messages, $maxInputTokens);
        $promptTokensEst = self::estimateTokens($messages) + count($tools) * 35;

        if (empty($apiKey) || $apiKey === 'mock_key' || str_starts_with($apiKey, 'mock')) {
            $mockRes = $this->getMockToolCompletion($messages);
            $completionTokensEst = self::estimateTokens($mockRes['content'] ?? '') + 30;
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
            self::recordUsage(null, null, $feature, $provider, $model, $promptTokensEst, $completionTokensEst, $latencyMs, 'success');
            return $mockRes;
        }

        try {
            $provider = strtolower($provider);
            $model = $this->resolveModel($provider, $model);

            // 1. OpenAI-compatible endpoints
            if (in_array($provider, ['openai', 'groq', 'deepseek', 'xai', 'mistral', 'openrouter'])) {
                $urls = [
                    'openai' => 'https://api.openai.com/v1/chat/completions',
                    'groq' => 'https://api.groq.com/openai/v1/chat/completions',
                    'deepseek' => 'https://api.deepseek.com/v1/chat/completions',
                    'xai' => 'https://api.x.ai/v1/chat/completions',
                    'mistral' => 'https://api.mistral.ai/v1/chat/completions',
                    'openrouter' => 'https://openrouter.ai/api/v1/chat/completions',
                ];

                $body = [
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => $temperature,
                    'max_tokens' => $maxOutputTokens,
                ];

                if (!empty($tools)) {
                    $body['tools'] = array_map(fn($t) => [
                        'type' => 'function',
                        'function' => $t
                    ], $tools);
                }

                $response = Http::withToken($apiKey)->timeout(15)->post($urls[$provider], $body);
                $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

                if ($response->successful()) {
                    $json = $response->json();
                    $choice = $json['choices'][0]['message'] ?? [];
                    $rawContent = $choice['content'] ?? null;
                    
                    $promptTokens = $json['usage']['prompt_tokens'] ?? $promptTokensEst;
                    $completionTokens = $json['usage']['completion_tokens'] ?? self::estimateTokens($rawContent);
                    self::recordUsage(null, null, $feature, $provider, $model, $promptTokens, $completionTokens, $latencyMs, 'success');

                    return [
                        'content' => $rawContent !== null ? $this->cleanCompletionText($rawContent) : null,
                        'tool_calls' => isset($choice['tool_calls']) ? array_map(fn($tc) => [
                            'id' => $tc['id'] ?? '',
                            'name' => $tc['function']['name'] ?? '',
                            'arguments' => json_decode($tc['function']['arguments'] ?? '{}', true) ?: []
                        ], $choice['tool_calls']) : null
                    ];
                }

                $err = "Provider error: " . $response->body();
                self::recordUsage(null, null, $feature, $provider, $model, $promptTokensEst, 0, $latencyMs, 'failed', $err);
                throw new Exception($err);
            }

            // 2. Anthropic Claude messages API
            if ($provider === 'anthropic') {
                $system = '';
                $claudeMessages = [];

                foreach ($messages as $msg) {
                    if ($msg['role'] === 'system') {
                        $system = $msg['content'];
                    } else {
                        $claudeMessages[] = [
                            'role' => $msg['role'] === 'assistant' ? 'assistant' : 'user',
                            'content' => $msg['content']
                        ];
                    }
                }

                $body = [
                    'model' => $model,
                    'messages' => $claudeMessages,
                    'max_tokens' => $maxOutputTokens,
                    'temperature' => $temperature,
                ];

                if (!empty($system)) {
                    $body['system'] = $system;
                }

                if (!empty($tools)) {
                    $body['tools'] = array_map(fn($t) => [
                        'name' => $t['name'],
                        'description' => $t['description'],
                        'input_schema' => $t['parameters']
                    ], $tools);
                }

                $response = Http::withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                ->timeout(15)
                ->post('https://api.anthropic.com/v1/messages', $body);

                $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

                if ($response->successful()) {
                    $json = $response->json();
                    $content = $json['content'] ?? [];
                    $text = null;
                    $toolCalls = null;

                    foreach ($content as $block) {
                        if ($block['type'] === 'text') {
                            $text = $block['text'];
                        } elseif ($block['type'] === 'tool_use') {
                            $toolCalls[] = [
                                'id' => $block['id'] ?? '',
                                'name' => $block['name'] ?? '',
                                'arguments' => $block['input'] ?? []
                            ];
                        }
                    }

                    $promptTokens = $json['usage']['input_tokens'] ?? $promptTokensEst;
                    $completionTokens = $json['usage']['output_tokens'] ?? self::estimateTokens($text);
                    self::recordUsage(null, null, $feature, $provider, $model, $promptTokens, $completionTokens, $latencyMs, 'success');

                    return [
                        'content' => $text,
                        'tool_calls' => $toolCalls
                    ];
                }

                $err = "Anthropic error: " . $response->body();
                self::recordUsage(null, null, $feature, $provider, $model, $promptTokensEst, 0, $latencyMs, 'failed', $err);
                throw new Exception($err);
            }

            // 3. Google Gemini generateContent API
            if ($provider === 'gemini') {
                $contents = [];
                $systemInstruction = null;

                foreach ($messages as $msg) {
                    if ($msg['role'] === 'system') {
                        $systemInstruction = [
                            'parts' => [['text' => $msg['content']]]
                        ];
                    } else {
                        $contents[] = [
                            'role' => $msg['role'] === 'assistant' ? 'model' : 'user',
                            'parts' => [['text' => $msg['content']]]
                        ];
                    }
                }

                $body = ['contents' => $contents];
                if ($systemInstruction) {
                    $body['systemInstruction'] = $systemInstruction;
                }

                $body['generationConfig'] = [
                    'temperature' => $temperature,
                    'maxOutputTokens' => $maxOutputTokens,
                ];

                if (!empty($tools)) {
                    $body['tools'] = [
                        [
                            'functionDeclarations' => array_map(fn($t) => [
                                'name' => $t['name'],
                                'description' => $t['description'],
                                'parameters' => $t['parameters']
                            ], $tools)
                        ]
                    ];
                }

                $response = Http::timeout(15)
                    ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}", $body);

                $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

                if ($response->successful()) {
                    $json = $response->json();
                    $parts = $json['candidates'][0]['content']['parts'] ?? [];
                    $text = null;
                    $toolCalls = null;

                    foreach ($parts as $part) {
                        if (isset($part['text'])) {
                            $text = $part['text'];
                        } elseif (isset($part['functionCall'])) {
                            $fc = $part['functionCall'];
                            $toolCalls[] = [
                                'id' => 'gemini_call_' . uniqid(),
                                'name' => $fc['name'] ?? '',
                                'arguments' => $fc['args'] ?? []
                            ];
                        }
                    }

                    $promptTokens = $json['usageMetadata']['promptTokenCount'] ?? $promptTokensEst;
                    $completionTokens = $json['usageMetadata']['candidatesTokenCount'] ?? self::estimateTokens($text);
                    self::recordUsage(null, null, $feature, $provider, $model, $promptTokens, $completionTokens, $latencyMs, 'success');

                    return [
                        'content' => $text,
                        'tool_calls' => $toolCalls
                    ];
                }

                $err = "Gemini error: " . $response->body();
                self::recordUsage(null, null, $feature, $provider, $model, $promptTokensEst, 0, $latencyMs, 'failed', $err);
                throw new Exception($err);
            }

            throw new Exception("Unsupported AI provider: {$provider}");

        } catch (Exception $e) {
            \Illuminate\Support\Facades\Log::warning("AI tool completion generation failed: " . $e->getMessage() . ". Falling back to mock tool completion.");
            $mockRes = $this->getMockToolCompletion($messages);
            $completionTokensEst = self::estimateTokens($mockRes['content'] ?? '') + 30;
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
            self::recordUsage(null, null, $feature, $provider, $model, $promptTokensEst, $completionTokensEst, $latencyMs, 'success');
            return $mockRes;
        }
    }

    /**
     * Fallback mock handler for simulation or missing keys.
     */
    private function getMockCompletion(array $messages): string
    {
        $lastUserMessage = '';
        $systemInstructions = '';

        foreach (array_reverse($messages) as $msg) {
            if ($msg['role'] === 'user' && empty($lastUserMessage)) {
                $lastUserMessage = $msg['content'];
            }
            if ($msg['role'] === 'system') {
                $systemInstructions = $msg['content'];
            }
        }

        // Standard classification checks (Decision Nodes)
        if (stripos($systemInstructions, 'yes') !== false && stripos($systemInstructions, 'no') !== false) {
            // Check if user is asking for price or saying yes/no
            if (preg_match('/(price|pricing|cost|how much|yes|ok|sure|booking|appointment|deal)/i', $lastUserMessage)) {
                return '{"answer": true}';
            }
            return '{"answer": false}';
        }

        // Generic mock response
        return "This is a simulated AI response. You sent: '{$lastUserMessage}'";
    }

    /**
     * Fallback mock handler with tool calling simulation.
     */
    private function getMockToolCompletion(array $messages): array
    {
        // Check if a tool has already been executed in the conversation messages
        $lastToolMsg = null;
        foreach (array_reverse($messages) as $msg) {
            if ($msg['role'] === 'tool') {
                $lastToolMsg = $msg;
                break;
            }
        }

        if ($lastToolMsg) {
            // Return text using the tool output
            return [
                'content' => "Based on the store records:\n" . $lastToolMsg['content'],
                'tool_calls' => null
            ];
        }

        $lastUserMessage = '';
        foreach (array_reverse($messages) as $msg) {
            if ($msg['role'] === 'user') {
                $lastUserMessage = $msg['content'];
                break;
            }
        }

        // 1. Check if user is asking about product stock/availability
        if (preg_match('/(stock|avail|buy|premium leather|headphones|watch|chair|speaker|shopify_prod)/i', $lastUserMessage)) {
            // Extract product ID if present, else default
            $productId = 'shopify_prod_1';
            if (preg_match('/shopify_prod_\d/i', $lastUserMessage, $m)) {
                $productId = strtolower($m[0]);
            }
            return [
                'content' => null,
                'tool_calls' => [
                    [
                        'id' => 'mock_call_' . uniqid(),
                        'name' => 'check_product_availability',
                        'arguments' => ['product_id' => $productId]
                    ]
                ]
            ];
        }

        // 2. Check if user is asking about order status
        if (preg_match('/(order|track|status|deliver|where is|receipt|#\d+)/i', $lastUserMessage)) {
            // Extract order number if present
            $orderNum = '#1001';
            if (preg_match('/#\d+/i', $lastUserMessage, $m)) {
                $orderNum = $m[0];
            }
            return [
                'content' => null,
                'tool_calls' => [
                    [
                        'id' => 'mock_call_' . uniqid(),
                        'name' => 'check_order_status',
                        'arguments' => [
                            'email' => 'jane@example.com',
                            'order_number' => $orderNum
                        ]
                    ]
                ]
            ];
        }

        // 3. Plain text response fallback
        return [
            'content' => "Hello! How can I assist you with our catalog or your orders today?",
            'tool_calls' => null
        ];
    }
}
