<?php

namespace App\Services\Flow;

use App\Models\FlowExecution;
use App\Models\FlowExecutionLog;
use App\Models\FlowVersion;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Deal;
use App\Models\Appointment;
use App\Models\ContactActivity;
use App\Services\AIProviderService;
use App\Services\Channels\ChannelManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Exception;

class FlowRunner
{
    protected AIProviderService $aiService;
    protected ChannelManager $channelManager;
    protected \App\Services\EmbeddingService $embeddingService;

    public function __construct(
        AIProviderService $aiService, 
        ChannelManager $channelManager,
        \App\Services\EmbeddingService $embeddingService
    ) {
        $this->aiService = $aiService;
        $this->channelManager = $channelManager;
        $this->embeddingService = $embeddingService;
    }

    /**
     * Check if an incoming message matches any node's trigger words configuration.
     */
    public function findMatchingKeywordNode(string $messageBody, array $definition): ?array
    {
        $nodes = $definition['nodes'] ?? [];
        $cleanBody = trim(strtolower($messageBody));

        if (empty($cleanBody)) {
            return null;
        }

        foreach ($nodes as $node) {
            $data = $node['data'] ?? [];
            if (empty($data['enable_trigger_words']) || empty($data['trigger_words'])) {
                continue;
            }

            $matchType = $data['trigger_words_match_type'] ?? 'contains';
            $words = array_filter(array_map('trim', explode(',', strtolower($data['trigger_words']))));

            foreach ($words as $word) {
                if (empty($word)) continue;

                if ($matchType === 'exact') {
                    if ($cleanBody === $word) {
                        return $node;
                    }
                } else { // contains
                    if (str_contains($cleanBody, $word)) {
                        return $node;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Resume or execute the flow run loop.
     */
    public function execute(FlowExecution $execution): void
    {
        $version = $execution->flowVersion;
        $definition = $version->definition;

        if (!$definition || !isset($definition['nodes'])) {
            $execution->update([
                'status' => 'failed',
                'last_error' => 'Flow definition is empty or invalid.'
            ]);
            return;
        }

        $execution->status = 'running';
        $execution->save();

        $context = $execution->context;
        if (!isset($context['loop_count'])) {
            $context['loop_count'] = [];
        }
        if (!isset($context['variables'])) {
            $context['variables'] = [];
        }
        
        $contact = $execution->contact;

        while ($execution->status === 'running') {
            $currentNodeId = $execution->current_node_id;
            $node = $this->findNode($definition, $currentNodeId);

            if (!$node) {
                $execution->update(['status' => 'completed']);
                break;
            }

            // Loop / Infinite Cycle Prevention (safety check)
            $nodeId = $node['id'];
            $context['loop_count'][$nodeId] = ($context['loop_count'][$nodeId] ?? 0) + 1;
            if ($context['loop_count'][$nodeId] > 20) {
                $nodeTitle = $node['data']['title'] ?? $nodeId;
                $execution->update([
                    'status' => 'failed',
                    'last_error' => "Infinite loop cycle detected at node: {$nodeTitle}."
                ]);
                $this->logNodeExecution($execution, $node, 'failed', 0, null, "Infinite loop safety limit exceeded.");
                break;
            }
            $execution->context = $context;
            $execution->save();

            $startTime = microtime(true);

            try {
                // Execute Node Action
                $result = $this->executeNode($node, $execution, $contact);
                $elapsedMs = round((microtime(true) - $startTime) * 1000);

                // Update context from run
                $context = $execution->context;

                // Log execution step
                $this->logNodeExecution(
                    $execution, 
                    $node, 
                    $result['status'], 
                    $elapsedMs, 
                    $result['details'] ?? null, 
                    $result['error_message'] ?? null
                );

                if ($result['status'] === 'paused' || str_starts_with($result['status'], 'paused')) {
                    $execution->update([
                        'status' => $result['execution_status'] ?? 'paused_waiting_reply', // 'paused_delay' or 'paused_waiting_reply'
                        'resume_after' => $result['resume_after'] ?? null,
                    ]);
                    break;
                }

                if ($result['status'] === 'failed') {
                    $execution->update([
                        'status' => 'failed',
                        'last_error' => $result['error_message'] ?? 'Unknown error occurred.',
                    ]);
                    break;
                }

                // Resolve Next Node based on edge connection
                $outputPort = $result['output_port'] ?? 'out';
                $nextNodeId = $this->resolveNextNodeId($definition, $currentNodeId, $outputPort);

                if (!$nextNodeId) {
                    $execution->update(['status' => 'completed']);
                    break;
                }

                $execution->current_node_id = $nextNodeId;
                $execution->save();

            } catch (Exception $e) {
                $elapsedMs = round((microtime(true) - $startTime) * 1000);
                $this->logNodeExecution($execution, $node, 'failed', $elapsedMs, null, $e->getMessage());
                $execution->update([
                    'status' => 'failed',
                    'last_error' => 'Runner Exception: ' . $e->getMessage(),
                ]);
                break;
            }
        }
    }

    /**
     * Find a node in the flow definition by ID.
     */
    protected function findNode(array $definition, string $nodeId): ?array
    {
        foreach ($definition['nodes'] as $node) {
            if ($node['id'] === $nodeId) {
                return $node;
            }
        }
        return null;
    }

    /**
     * Resolve the next node ID via edge mapping.
     */
    protected function resolveNextNodeId(array $definition, string $currentNodeId, string $outputPort): ?string
    {
        if (!isset($definition['edges']) || !is_array($definition['edges'])) {
            return null;
        }

        // 1. Exact match on sourceHandle
        foreach ($definition['edges'] as $edge) {
            if ($edge['source'] === $currentNodeId && ($edge['sourceHandle'] ?? '') === $outputPort) {
                return $edge['target'];
            }
        }

        // 2. Compatibility aliases for multi-port branching nodes
        $aliases = [$outputPort];
        if (in_array($outputPort, ['success', 'out', 'scheduled', 'resume', 'yes', 'true'])) {
            $aliases = array_merge($aliases, ['success', 'out', 'scheduled', 'resume', 'yes', 'true', '']);
        } elseif (in_array($outputPort, ['no', 'false'])) {
            $aliases = array_merge($aliases, ['no', 'false', 'error']);
        } elseif (in_array($outputPort, ['unavailable', 'error'])) {
            $aliases = array_merge($aliases, ['unavailable', 'error', 'false', 'timeout', 'fallback']);
        } elseif (preg_match('/^(item|opt)_(\d+)$/', $outputPort, $matches)) {
            $idx = intval($matches[2]);
            // Both item_0 and opt_1, item_1 and opt_2
            $aliases[] = 'item_' . $idx;
            $aliases[] = 'opt_' . ($idx + 1);
            $aliases[] = 'item_' . ($idx - 1);
            $aliases[] = 'opt_' . $idx;
        }

        foreach ($definition['edges'] as $edge) {
            if ($edge['source'] === $currentNodeId && in_array($edge['sourceHandle'] ?? '', $aliases)) {
                return $edge['target'];
            }
        }

        // 3. Fallback: If no explicit port matches, check if there is an edge with empty sourceHandle
        foreach ($definition['edges'] as $edge) {
            if ($edge['source'] === $currentNodeId && empty($edge['sourceHandle'])) {
                return $edge['target'];
            }
        }

        // 4. Single edge fallback from current node
        $nodeEdges = array_values(array_filter($definition['edges'], fn($e) => ($e['source'] ?? '') === $currentNodeId));
        if (count($nodeEdges) === 1 && !in_array($outputPort, ['error', 'false'])) {
            return $nodeEdges[0]['target'];
        }

        return null;
    }

    /**
     * Interpolate string placeholders like {{ contact.first_name }}
     */
    public function interpolate(string $text, array $context, Contact $contact): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_\.]+)\s*\}\}/', function ($matches) use ($context, $contact) {
            $key = trim($matches[1]);
            
            if (str_starts_with($key, 'contact.')) {
                $subKey = substr($key, 8);
                if (str_starts_with($subKey, 'custom_fields.')) {
                    $cfKey = substr($subKey, 14);
                    $customFields = is_array($contact->custom_fields) ? $contact->custom_fields : json_decode($contact->custom_fields ?? '[]', true);
                    return $customFields[$cfKey] ?? '';
                }
                return $contact->{$subKey} ?? '';
            }
            
            if ($key === 'system.current_date') {
                return now()->toDateString();
            }
            if ($key === 'system.current_time') {
                return now()->toTimeString();
            }
            
            if (str_starts_with($key, 'context.')) {
                $ctxKey = substr($key, 8);
                $val = $context['variables'][$ctxKey] ?? data_get($context['variables'] ?? [], $ctxKey, '');
                return is_array($val) ? json_encode($val) : (string) $val;
            }
            
            $val = $context['variables'][$key] ?? data_get($context['variables'] ?? [], $key);
            if ($val !== null) {
                return is_array($val) ? json_encode($val) : (string) $val;
            }

            return '';
        }, $text);
    }

    /**
     * Log the execution details to database.
     */
    protected function logNodeExecution(FlowExecution $execution, array $node, string $status, int $elapsedMs, ?array $details = null, ?string $errorMessage = null): void
    {
        FlowExecutionLog::create([
            'flow_execution_id' => $execution->id,
            'node_id' => $node['id'],
            'node_type' => $node['type'],
            'node_title' => $node['data']['title'] ?? $node['type'],
            'status' => $status,
            'execution_time_ms' => $elapsedMs,
            'details' => $details,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Process individual nodes action logic.
     */
    protected function executeNode(array $node, FlowExecution $execution, Contact $contact): array
    {
        $type = $node['type'];
        $data = $node['data'] ?? [];
        $context = $execution->context;
        $isSimulator = $context['is_simulator'] ?? false;

        switch ($type) {
            case 'inbound_message':
            case 'webhook_trigger':
            case 'outbound_campaign':
                return [
                    'status' => 'success',
                    'output_port' => 'out',
                    'details' => ['triggered' => true]
                ];

            case 'send_message':
                $rawBody = $data['body'] ?? '';
                $rawMediaUrl = $data['media_url'] ?? '';
                $body = $this->interpolate($rawBody, $context, $contact);
                $mediaUrl = $this->interpolate($rawMediaUrl, $context, $contact);

                if ($isSimulator) {
                    $simulatedMessages = $context['simulated_messages'] ?? [];
                    $simulatedMessages[] = [
                        'direction' => 'outbound',
                        'body' => $body,
                        'media_url' => $mediaUrl,
                        'timestamp' => now()->toIso8601String()
                    ];
                    $context['simulated_messages'] = $simulatedMessages;
                    $execution->context = $context;
                    $execution->save();

                    return [
                        'status' => 'success',
                        'output_port' => 'out',
                        'details' => ['body' => $body, 'media_url' => $mediaUrl, 'simulated' => true]
                    ];
                }

                // Live sending logic
                $conversation = $execution->conversation;
                if (!$conversation) {
                    // Try to resolve/create active conversation on the default/first channel
                    $channelConn = \App\Models\ChannelConnection::where('tenant_id', $execution->tenant_id)
                        ->where('status', 'connected')
                        ->first();
                        
                    if (!$channelConn) {
                        return [
                            'status' => 'failed',
                            'error_message' => 'No active channel connection found to send message.'
                        ];
                    }

                    $conversation = Conversation::create([
                        'tenant_id' => $execution->tenant_id,
                        'contact_id' => $contact->id,
                        'channel_connection_id' => $channelConn->id,
                        'status' => 'open',
                        'last_message_at' => Carbon::now(),
                    ]);
                    $execution->conversation_id = $conversation->id;
                    $execution->save();
                }

                try {
                    $credentials = $conversation->channelConnection->decrypted_credentials;
                    $driver = $this->channelManager->driver($conversation->channelConnection->channel_type);
                    
                    $res = $driver->sendMessage($credentials, [
                        'external_chat_id' => $conversation->external_chat_id ?? $contact->phone ?? $contact->email ?? 'test_chat',
                        'body' => $body,
                        'media_url' => $mediaUrl,
                    ]);

                    $isSuccess = ($res['delivery_status'] ?? '') === 'sent' || !empty($res['success']) || !empty($res['external_message_id']);
                    $errorMessage = $res['error_message'] ?? ($res['error'] ?? null);

                    Message::create([
                        'conversation_id' => $conversation->id,
                        'tenant_id' => $execution->tenant_id,
                        'direction' => 'outbound',
                        'message_type' => $mediaUrl ? 'image' : 'text',
                        'sender_identifier' => 'System Flow Builder',
                        'body' => $body,
                        'media_url' => $mediaUrl,
                        'provider_message_id' => $res['external_message_id'] ?? 'flow_' . uniqid(),
                        'delivery_status' => $isSuccess ? 'sent' : 'failed',
                        'error_message' => $errorMessage,
                    ]);

                    $conversation->update(['last_message_at' => Carbon::now()]);

                    if (!$isSuccess) {
                        $execution->status = 'failed';
                        $execution->last_error = $errorMessage ?: 'Message delivery failed at channel layer.';
                        $execution->save();

                        return [
                            'status' => 'failed',
                            'error_message' => $errorMessage ?: 'Message delivery failed at channel layer.',
                        ];
                    }

                    return [
                        'status' => 'success',
                        'output_port' => 'out',
                        'details' => ['body' => $body, 'message_id' => $res['external_message_id'] ?? null]
                    ];
                } catch (Exception $e) {
                    $execution->status = 'failed';
                    $execution->last_error = 'Failed outbound message: ' . $e->getMessage();
                    $execution->save();

                    return [
                        'status' => 'failed',
                        'error_message' => 'Failed outbound message: ' . $e->getMessage()
                    ];
                }

            case 'ecommerceCheckoutAbandoned':
                return [
                    'status' => 'success',
                    'output_port' => 'out',
                    'details' => ['triggered' => true]
                ];

            case 'checkCartStatus':
                $cartProperty = $data['cartProperty'] ?? 'total_price';
                $operator = $data['operator'] ?? 'gt';
                $compareValue = floatval($data['value'] ?? 100);

                $actualValue = 0;
                if ($cartProperty === 'total_price') {
                    $actualValue = floatval($context['variables']['cart_total'] ?? 0);
                } else if ($cartProperty === 'items_count') {
                    $itemsSummary = $context['variables']['items_summary'] ?? '[]';
                    $items = json_decode($itemsSummary, true);
                    if (is_array($items)) {
                        foreach ($items as $item) {
                            $actualValue += intval($item['qty'] ?? 1);
                        }
                    } else {
                        $actualValue = 0;
                    }
                }

                $matched = false;
                if ($operator === 'gt') {
                    $matched = $actualValue > $compareValue;
                } else if ($operator === 'lt') {
                    $matched = $actualValue < $compareValue;
                } else if ($operator === 'eq') {
                    $matched = abs($actualValue - $compareValue) < 0.0001;
                }

                return [
                    'status' => 'success',
                    'output_port' => $matched ? 'true' : 'false',
                    'details' => [
                        'cart_property' => $cartProperty,
                        'actual_value' => $actualValue,
                        'compare_value' => $compareValue,
                        'operator' => $operator,
                        'matched' => $matched
                    ]
                ];

            case 'generateDiscountCode':
                $discountType = $data['discountType'] ?? 'percentage';
                $discountValue = floatval($data['discountValue'] ?? 15);
                $codePrefix = trim($data['codePrefix'] ?? 'SAVE');
                if (empty($codePrefix)) {
                    $codePrefix = 'SAVE';
                }
                $expiryDays = intval($data['expiryDays'] ?? 7);

                // Generate coupon code
                $randomSuffix = strtoupper(\Illuminate\Support\Str::random(6));
                $generatedCode = "{$codePrefix}-{$randomSuffix}";
                $expiryTimestamp = now()->addDays($expiryDays)->toIso8601String();

                $details = [
                    'discount_type' => $discountType,
                    'discount_value' => $discountValue,
                    'code_prefix' => $codePrefix,
                    'expiry_days' => $expiryDays,
                    'code' => $generatedCode,
                    'expiry' => $expiryTimestamp,
                ];

                if (!$isSimulator) {
                    // Try to find the active E-commerce connection for this tenant
                    $connection = \App\Models\EcommerceConnection::where('tenant_id', $execution->tenant_id)
                        ->where('status', 'active')
                        ->first();

                    if ($connection) {
                        $platform = $connection->platform;
                        $storeUrl = $connection->store_url;
                        $creds = $connection->credentials ?? [];

                        try {
                            if ($platform === 'shopify') {
                                // Shopify connection uses Admin Access Token or custom API key
                                $accessToken = $creds['access_token'] ?? $creds['api_key'] ?? null;
                                if ($accessToken) {
                                    // 1. Create Price Rule
                                    $priceRulePayload = [
                                        'price_rule' => [
                                            'title' => $generatedCode,
                                            'target_type' => 'line_item',
                                            'target_selection' => 'all',
                                            'allocation_method' => 'across',
                                            'value_type' => $discountType === 'percentage' ? 'percentage' : 'fixed_amount',
                                            'value' => $discountType === 'percentage' ? "-{$discountValue}" : "-{$discountValue}.00",
                                            'customer_selection' => 'all',
                                            'starts_at' => now()->toIso8601String(),
                                            'ends_at' => now()->addDays($expiryDays)->toIso8601String(),
                                            'once_per_customer' => true,
                                            'usage_limit' => 1,
                                        ]
                                    ];

                                    $priceRuleResponse = Http::timeout(10)
                                        ->withHeaders([
                                            'X-Shopify-Access-Token' => $accessToken,
                                            'Content-Type' => 'application/json',
                                        ])
                                        ->post("https://{$storeUrl}/admin/api/2024-04/price_rules.json", $priceRulePayload);

                                    if ($priceRuleResponse->successful()) {
                                        $priceRuleId = $priceRuleResponse->json('price_rule.id');
                                        if ($priceRuleId) {
                                            // 2. Create Discount Code under the Price Rule
                                            $discountCodeResponse = Http::timeout(10)
                                                ->withHeaders([
                                                    'X-Shopify-Access-Token' => $accessToken,
                                                    'Content-Type' => 'application/json',
                                                ])
                                                ->post("https://{$storeUrl}/admin/api/2024-04/price_rules/{$priceRuleId}/discount_codes.json", [
                                                    'discount_code' => [
                                                        'code' => $generatedCode
                                                    ]
                                                ]);

                                            if ($discountCodeResponse->successful()) {
                                                $details['shopify_price_rule_id'] = $priceRuleId;
                                                $details['shopify_discount_code_id'] = $discountCodeResponse->json('discount_code.id');
                                                $details['api_synced'] = true;
                                            } else {
                                                Log::warning("generateDiscountCode Node: Failed to create Shopify discount code under price rule {$priceRuleId}. Response: " . $discountCodeResponse->body());
                                            }
                                        }
                                    } else {
                                        Log::warning("generateDiscountCode Node: Failed to create Shopify price rule. Response: " . $priceRuleResponse->body());
                                    }
                                }
                            } else if ($platform === 'woocommerce') {
                                // WooCommerce Rest API uses Consumer Key and Secret
                                $consumerKey = $creds['consumer_key'] ?? null;
                                $consumerSecret = $creds['consumer_secret'] ?? null;

                                if ($consumerKey && $consumerSecret) {
                                    $wcPayload = [
                                        'code' => strtolower($generatedCode),
                                        'discount_type' => $discountType === 'percentage' ? 'percent' : 'fixed_cart',
                                        'amount' => (string)$discountValue,
                                        'individual_use' => true,
                                        'usage_limit' => 1,
                                        'expiry_date' => now()->addDays($expiryDays)->toDateString(),
                                    ];

                                    $wcResponse = Http::timeout(10)
                                        ->withBasicAuth($consumerKey, $consumerSecret)
                                        ->post("https://{$storeUrl}/wp-json/wc/v3/coupons", $wcPayload);

                                    if ($wcResponse->successful()) {
                                        $details['woocommerce_coupon_id'] = $wcResponse->json('id');
                                        $details['api_synced'] = true;
                                    } else {
                                        Log::warning("generateDiscountCode Node: Failed to create WooCommerce coupon. Response: " . $wcResponse->body());
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                            Log::error("generateDiscountCode Node Exception: " . $e->getMessage());
                            $details['api_error'] = $e->getMessage();
                        }
                    }
                }

                // Update the variables in active execution context
                $context['variables']['discount.code'] = $generatedCode;
                $context['variables']['discount.expiry'] = $expiryTimestamp;
                $context['variables']['discount_code'] = $generatedCode;
                $context['variables']['discount_expiry'] = $expiryTimestamp;
                $context['variables']['discount'] = [
                    'code' => $generatedCode,
                    'expiry' => $expiryTimestamp,
                ];
                $execution->context = $context;
                $execution->save();

                return [
                    'status' => 'success',
                    'output_port' => 'out',
                    'details' => $details
                ];

            case 'end_flow':
                $rawBody = $data['body'] ?? '';
                $body = !empty($rawBody) ? $this->interpolate($rawBody, $context, $contact) : '';

                // Clear all session variables on flow completion so future sessions start completely fresh
                $simulatedMessages = $context['simulated_messages'] ?? [];
                $context['variables'] = [];
                if (!empty($simulatedMessages)) {
                    $context['simulated_messages'] = $simulatedMessages;
                }
                $execution->context = $context;
                $execution->save();

                if (empty($body)) {
                    return [
                        'status' => 'success',
                        'output_port' => 'out',
                        'details' => ['terminated' => true]
                    ];
                }

                if ($isSimulator) {
                    $simulatedMessages[] = [
                        'direction' => 'outbound',
                        'body' => $body,
                        'media_url' => '',
                        'timestamp' => now()->toIso8601String()
                    ];
                    $context['simulated_messages'] = $simulatedMessages;
                    $execution->context = $context;
                    $execution->save();

                    return [
                        'status' => 'success',
                        'output_port' => 'out',
                        'details' => ['body' => $body, 'simulated' => true, 'terminated' => true]
                    ];
                }

                // Live sending logic
                $conversation = $execution->conversation;
                if (!$conversation) {
                    $channelConn = \App\Models\ChannelConnection::where('tenant_id', $execution->tenant_id)
                        ->where('status', 'connected')
                        ->first();
                        
                    if (!$channelConn) {
                        return [
                            'status' => 'success',
                            'output_port' => 'out',
                            'details' => ['error' => 'No active channel connection found.', 'terminated' => true]
                        ];
                    }

                    $conversation = Conversation::create([
                        'tenant_id' => $execution->tenant_id,
                        'contact_id' => $contact->id,
                        'channel_connection_id' => $channelConn->id,
                        'status' => 'open',
                        'last_message_at' => Carbon::now(),
                    ]);
                    $execution->conversation_id = $conversation->id;
                    $execution->save();
                }

                try {
                    $credentials = $conversation->channelConnection->decrypted_credentials;
                    $driver = $this->channelManager->driver($conversation->channelConnection->channel_type);
                    
                    $res = $driver->sendMessage($credentials, [
                        'external_chat_id' => $conversation->external_chat_id ?? $contact->phone ?? $contact->email ?? 'test_chat',
                        'body' => $body,
                        'media_url' => '',
                    ]);

                    Message::create([
                        'conversation_id' => $conversation->id,
                        'tenant_id' => $execution->tenant_id,
                        'direction' => 'outbound',
                        'message_type' => 'text',
                        'sender_identifier' => 'System Flow Builder',
                        'body' => $body,
                        'media_url' => '',
                        'provider_message_id' => $res['external_message_id'] ?? 'flow_' . uniqid(),
                        'delivery_status' => 'sent',
                    ]);

                    $conversation->update(['last_message_at' => Carbon::now()]);

                    return [
                        'status' => 'success',
                        'output_port' => 'out',
                        'details' => ['body' => $body, 'message_id' => $res['external_message_id'] ?? null, 'terminated' => true]
                    ];
                } catch (Exception $e) {
                    return [
                        'status' => 'success',
                        'output_port' => 'out',
                        'details' => ['error' => $e->getMessage(), 'terminated' => true]
                    ];
                }

            case 'send_template':
                // Structured template dispatch
                $templateName = $data['template_name'] ?? 'N/A';
                return [
                    'status' => 'success',
                    'output_port' => 'success',
                    'details' => ['template_dispatched' => $templateName]
                ];

            case 'condition':
                $matrix = $data['conditions'] ?? [];
                $op = strtoupper($data['operator'] ?? 'AND');
                $evalMatches = [];

                foreach ($matrix as $row) {
                    $field = $row['field'] ?? '';
                    $logicalOp = $row['operator'] ?? 'equals';
                    $val = $row['value'] ?? '';

                    $actualVal = '';
                    if (str_starts_with($field, 'contact.')) {
                        $actualVal = $this->interpolate('{{ ' . $field . ' }}', $context, $contact);
                    } else {
                        $actualVal = $context['variables'][$field] ?? '';
                    }

                    $match = false;
                    switch ($logicalOp) {
                        case 'equals':
                            $match = (string)$actualVal === (string)$val;
                            break;
                        case 'contains':
                            $match = stripos((string)$actualVal, (string)$val) !== false;
                            break;
                        case 'is_empty':
                            $match = empty($actualVal);
                            break;
                        case 'starts_with':
                            $match = str_starts_with((string)$actualVal, (string)$val);
                            break;
                    }
                    $evalMatches[] = $match;
                }

                $isTrue = false;
                if ($op === 'OR') {
                    $isTrue = in_array(true, $evalMatches, true);
                } else {
                    $isTrue = !in_array(false, $evalMatches, true);
                }

                return [
                    'status' => 'success',
                    'output_port' => $isTrue ? 'true' : 'false',
                    'details' => ['evaluated' => $isTrue, 'matches' => $evalMatches]
                ];

            case 'ai_condition':
                $question = $data['question'] ?? '';
                $promptMessage = !empty($data['prompt_message']) ? $data['prompt_message'] : $question;
                $content = $data['content_key'] ?? '{{ inbound_message_body }}';

                $waitingKey = 'ai_condition_waiting_' . $node['id'];

                // 1. Always send prompt question first and wait for customer reply
                if (empty($context['variables'][$waitingKey])) {
                    $promptText = $this->interpolate($promptMessage, $context, $contact);

                    $context['variables'][$waitingKey] = true;
                    $context['variables']['current_save_variable'] = 'inbound_message_body';
                    $execution->context = $context;
                    $execution->save();

                    if ($isSimulator) {
                        $simulatedMessages = $context['simulated_messages'] ?? [];
                        $simulatedMessages[] = [
                            'direction' => 'outbound',
                            'body' => $promptText,
                            'timestamp' => now()->toIso8601String()
                        ];
                        $context['simulated_messages'] = $simulatedMessages;
                        $execution->context = $context;
                        $execution->save();

                        return [
                            'status' => 'paused',
                            'execution_status' => 'paused_waiting_reply',
                            'details' => ['prompt_sent' => $promptText]
                        ];
                    }

                    $conversation = $execution->conversation;
                    if (!$conversation) {
                        $channelConn = \App\Models\ChannelConnection::where('tenant_id', $execution->tenant_id)
                            ->where('status', 'connected')
                            ->first();
                            
                        if ($channelConn) {
                            $conversation = Conversation::create([
                                'tenant_id' => $execution->tenant_id,
                                'contact_id' => $contact->id,
                                'channel_connection_id' => $channelConn->id,
                                'status' => 'open',
                                'last_message_at' => Carbon::now(),
                            ]);
                            $execution->conversation_id = $conversation->id;
                            $execution->save();
                        }
                    }

                    if ($conversation) {
                        try {
                            $credentials = $conversation->channelConnection->decrypted_credentials;
                            $driver = $this->channelManager->driver($conversation->channelConnection->channel_type);
                            
                            $res = $driver->sendMessage($credentials, [
                                'external_chat_id' => $conversation->external_chat_id ?? $contact->phone ?? $contact->email ?? 'test_chat',
                                'body' => $promptText,
                            ]);

                            Message::create([
                                'conversation_id' => $conversation->id,
                                'tenant_id' => $execution->tenant_id,
                                'direction' => 'outbound',
                                'message_type' => 'text',
                                'sender_identifier' => 'System Flow Builder',
                                'body' => $promptText,
                                'provider_message_id' => $res['external_message_id'] ?? 'flow_' . uniqid(),
                                'delivery_status' => 'sent',
                            ]);

                            $conversation->update(['last_message_at' => Carbon::now()]);
                        } catch (Exception $e) {
                            Log::warning("AI Condition prompt message failed to send: " . $e->getMessage());
                        }
                    }

                    return [
                        'status' => 'paused',
                        'execution_status' => 'paused_waiting_reply',
                        'details' => ['prompt_sent' => $promptText]
                    ];
                }

                // 2. Evaluate LLM decision on the user's answer
                if (!empty($context['variables'][$waitingKey])) {
                    unset($context['variables'][$waitingKey]);
                    $execution->context = $context;
                    $execution->save();
                }

                $evalRule = !empty($question) ? $question : $promptMessage;
                $resolvedQuestion = $this->interpolate($evalRule, $context, $contact);
                $resolvedContent = $this->interpolate($content, $context, $contact);
                if (empty($resolvedContent) || $resolvedContent === '{{ inbound_message_body }}') {
                    $resolvedContent = $context['variables']['inbound_message_body'] ?? '';
                }

                // Resolve AI Agent associated with the flow or conversation
                $flowVersion = \App\Models\FlowVersion::find($execution->flow_version_id);
                $flowId = $flowVersion ? $flowVersion->flow_id : null;
                $agent = null;
                if ($flowId) {
                    $agent = \App\Models\AiAgent::where('tenant_id', $execution->tenant_id)
                        ->where('flow_id', $flowId)
                        ->first();
                }
                if (!$agent && !empty($execution->conversation->ai_agent_id)) {
                    $agent = \App\Models\AiAgent::find($execution->conversation->ai_agent_id);
                }

                // Resolve AI Provider & Model via unified operational model resolver
                $resolvedAI = $this->aiService->resolveAIExecution(
                    $execution->tenant_id,
                    'flow_ai_condition',
                    $node['data']['provider'] ?? ($agent && $agent->providerConfig ? $agent->providerConfig->provider_name : null),
                    $node['data']['model'] ?? ($agent ? $agent->model : null)
                );
                $provider = $resolvedAI['provider'];
                $apiKey = $resolvedAI['api_key'];
                $model = $resolvedAI['model'];

                if (!empty($resolvedAI['is_blocked'])) {
                    return [
                        'status' => 'success',
                        'output_port' => 'no',
                        'details' => ['ai_response' => $resolvedAI['fallback_message'], 'answer' => false, 'is_blocked' => true]
                    ];
                }

                $sysPrompt = "You are a binary classification assistant. Answer with yes or no in JSON format: {\"answer\": true} or {\"answer\": false}.\nQuestion to evaluate: {$resolvedQuestion}";
                
                $messages = [
                    ['role' => 'system', 'content' => $sysPrompt],
                    ['role' => 'user', 'content' => $resolvedContent]
                ];

                $completion = $this->aiService->generateCompletion($provider, $apiKey, $model, $messages, 0.7, 'flow_ai_condition', $execution->tenant_id);
                
                // Parse decision
                $isYes = false;
                if (preg_match('/true/i', $completion) || preg_match('/yes/i', $completion)) {
                    $isYes = true;
                }

                return [
                    'status' => 'success',
                    'output_port' => $isYes ? 'yes' : 'no',
                    'details' => ['ai_response' => $completion, 'answer' => $isYes]
                ];

            case 'ask_question':
                $question = $data['question'] ?? '';
                $saveVar = $data['saveVariable'] ?? 'user_answer';
                $prompt = $this->interpolate($question, $context, $contact);

                $waitingKey = 'ask_question_waiting_' . $node['id'];
                if (!empty($context['variables'][$waitingKey])) {
                    unset($context['variables'][$waitingKey]);

                    $dataType = $data['variableDataType'] ?? 'text';
                    $rawAnswer = $context['variables'][$saveVar] ?? '';

                    if (!empty($rawAnswer) && $dataType !== 'text' && !$isSimulator) {
                        try {
                            $resolvedAI = $this->aiService->resolveAIExecution(
                                $execution->tenant_id,
                                'flow_ai_prompt'
                            );
                            
                            $provider = $resolvedAI['provider'];
                            $apiKey = $resolvedAI['api_key'];
                            $model = $resolvedAI['model'];

                            $currentDate = now()->toDateString();
                            $sysPrompt = "You are a precise data formatting assistant. Extract and format the user input to strictly match the requested data type '{$dataType}'. Current date reference is {$currentDate}.\n"
                                       . "Rules:\n"
                                       . "- For 'date': return formatted YYYY-MM-DD (e.g. 2026-08-25).\n"
                                       . "- For 'time': return formatted HH:MM AM/PM (e.g. 10:00 AM).\n"
                                       . "- For 'datetime': return YYYY-MM-DD HH:MM AM/PM.\n"
                                       . "- For 'number': return plain numeric digits only (e.g. 5).\n"
                                       . "- For 'email': return clean email address string.\n"
                                       . "- For 'phone': return clean phone number string.\n"
                                       . "Return ONLY a raw string formatted value with no extra JSON or explanation.";

                            $messages = [
                                ['role' => 'system', 'content' => $sysPrompt],
                                ['role' => 'user', 'content' => $rawAnswer]
                            ];

                            $formattedValue = $this->aiService->generateCompletion($provider, $apiKey, $model, $messages, 0.7, 'flow_question_extract', $execution->tenant_id);
                            $formattedValue = trim(strip_tags($formattedValue));
                            if (!empty($formattedValue) && !str_contains($formattedValue, 'simulated AI response')) {
                                $context['variables'][$saveVar] = $formattedValue;
                            }
                        } catch (Exception $e) {
                            Log::warning("LLM question formatting failed: " . $e->getMessage());
                        }
                    }

                    $execution->context = $context;
                    $execution->save();

                    return [
                        'status' => 'success',
                        'output_port' => 'out',
                        'details' => ['question' => $prompt, 'save_variable' => $saveVar, 'answered' => true]
                    ];
                }

                $dataType = $data['variableDataType'] ?? 'text';
                $context['variables']['current_save_variable'] = $saveVar;
                $context['variables']['_meta'][$saveVar] = [
                    'type' => $dataType,
                    'label' => $data['saveVariableLabel'] ?? $saveVar
                ];
                $context['variables'][$waitingKey] = true;
                $execution->context = $context;
                $execution->save();

                if ($isSimulator) {
                    $simulatedMessages = $context['simulated_messages'] ?? [];
                    $simulatedMessages[] = [
                        'direction' => 'outbound',
                        'body' => $prompt,
                        'timestamp' => now()->toIso8601String()
                    ];
                    $context['simulated_messages'] = $simulatedMessages;
                    $context['variables'][$saveVar] = 'Simulated Answer';
                    $execution->context = $context;
                    $execution->save();

                    return [
                        'status' => 'success',
                        'output_port' => 'out',
                        'details' => ['question' => $prompt, 'save_variable' => $saveVar, 'simulated' => true]
                    ];
                }

                $conversation = $execution->conversation;
                if (!$conversation) {
                    $channelConn = \App\Models\ChannelConnection::where('tenant_id', $execution->tenant_id)
                        ->where('status', 'connected')
                        ->first();
                        
                    if (!$channelConn) {
                        return [
                            'status' => 'failed',
                            'error_message' => 'No active channel connection found to ask question.'
                        ];
                    }

                    $conversation = Conversation::create([
                        'tenant_id' => $execution->tenant_id,
                        'contact_id' => $contact->id,
                        'channel_connection_id' => $channelConn->id,
                        'status' => 'open',
                        'last_message_at' => Carbon::now(),
                    ]);
                    $execution->conversation_id = $conversation->id;
                    $execution->save();
                }

                try {
                    $credentials = $conversation->channelConnection->decrypted_credentials;
                    $driver = $this->channelManager->driver($conversation->channelConnection->channel_type);
                    
                    $res = $driver->sendMessage($credentials, [
                        'external_chat_id' => $conversation->external_chat_id ?? $contact->phone ?? $contact->email ?? 'test_chat',
                        'body' => $prompt,
                    ]);

                    Message::create([
                        'conversation_id' => $conversation->id,
                        'tenant_id' => $execution->tenant_id,
                        'direction' => 'outbound',
                        'message_type' => 'text',
                        'sender_identifier' => 'System Flow Builder',
                        'body' => $prompt,
                        'provider_message_id' => $res['external_message_id'] ?? 'flow_' . uniqid(),
                        'delivery_status' => 'sent',
                    ]);

                    $conversation->update(['last_message_at' => Carbon::now()]);

                    return [
                        'status' => 'paused',
                        'execution_status' => 'paused_waiting_reply',
                        'details' => ['question' => $prompt, 'save_variable' => $saveVar]
                    ];
                } catch (Exception $e) {
                    return [
                        'status' => 'failed',
                        'error_message' => 'Failed to send question prompt: ' . $e->getMessage()
                    ];
                }

            case 'interactive_menu':
                $items = $data['items'] ?? [];
                $saveVar = $data['saveVariable'] ?? 'selected_menu_option';
                $waitingKey = 'menu_waiting_' . $node['id'];
                $retryKey = 'menu_retries_' . $node['id'];

                // 1. If currently waiting for user reply, evaluate incoming reply
                if (!empty($context['variables'][$waitingKey])) {
                    $userInput = trim($context['variables']['inbound_message_body'] ?? $context['variables'][$saveVar] ?? '');
                    $cleanInput = strtolower($userInput);

                    $matchedItem = null;
                    $matchedIndex = 0;

                    // Match logic against configured items
                    foreach ($items as $idx => $item) {
                        $itemId = $item['id'] ?? ('opt_' . ($idx + 1));
                        $itemTitle = trim($item['title'] ?? '');
                        $cleanTitle = strtolower($itemTitle);
                        $itemVal = trim($item['value'] ?? '');
                        $cleanVal = strtolower($itemVal);

                        // 1. Exact ID match (e.g. from interactive list click payload)
                        if (!empty($itemId) && $cleanInput === strtolower($itemId)) {
                            $matchedItem = $item;
                            $matchedIndex = $idx;
                            break;
                        }

                        // 2. Custom matching keywords (comma separated)
                        if (!empty($item['keywords'])) {
                            $keywords = array_filter(array_map('trim', explode(',', strtolower($item['keywords']))));
                            foreach ($keywords as $kw) {
                                if (empty($kw)) continue;
                                if ($cleanInput === $kw || 
                                    preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $userInput) || 
                                    str_contains($cleanInput, $kw)
                                ) {
                                    $matchedItem = $item;
                                    $matchedIndex = $idx;
                                    break 2;
                                }
                            }
                        }

                        // 3. Exact title match
                        if (!empty($cleanTitle) && $cleanInput === $cleanTitle) {
                            $matchedItem = $item;
                            $matchedIndex = $idx;
                            break;
                        }

                        // 4. Number / Index match: "1", "#1", "option 1", "1."
                        $indexNum = (string)($idx + 1);
                        if ($cleanInput === $indexNum || 
                            $cleanInput === "#{$indexNum}" || 
                            $cleanInput === "option {$indexNum}" || 
                            $cleanInput === "option: {$indexNum}" ||
                            str_starts_with($cleanInput, "{$indexNum}.") ||
                            str_starts_with($cleanInput, "{$indexNum} ") ||
                            str_starts_with($cleanInput, "{$indexNum}-")
                        ) {
                            $matchedItem = $item;
                            $matchedIndex = $idx;
                            break;
                        }

                        // 5. Custom value match
                        if (!empty($cleanVal) && $cleanInput === $cleanVal) {
                            $matchedItem = $item;
                            $matchedIndex = $idx;
                            break;
                        }

                        // 6. Title substring match (if user typed part of title)
                        if (strlen($cleanTitle) > 3 && (str_contains($cleanInput, $cleanTitle) || str_contains($cleanTitle, $cleanInput))) {
                            $matchedItem = $item;
                            $matchedIndex = $idx;
                            break;
                        }
                    }

                    // MATCH FOUND!
                    if ($matchedItem) {
                        unset($context['variables'][$waitingKey]);
                        unset($context['variables'][$retryKey]);

                        $storedValue = !empty($matchedItem['value']) ? $matchedItem['value'] : ($matchedItem['title'] ?? '');
                        $context['variables'][$saveVar] = $storedValue;
                        $context['variables']['_selected_menu_item'] = $matchedItem;
                        $execution->context = $context;
                        $execution->save();

                        $portId = $matchedItem['id'] ?? ('opt_' . ($matchedIndex + 1));

                        return [
                            'status' => 'success',
                            'output_port' => $portId,
                            'details' => [
                                'selected_option' => $matchedItem['title'] ?? $storedValue,
                                'port' => $portId,
                                'user_input' => $userInput
                            ]
                        ];
                    }

                    // NO MATCH: Handle retry counter & retry prompt or fallback
                    $retries = intval($context['variables'][$retryKey] ?? 0) + 1;
                    $maxRetries = intval($data['maxRetries'] ?? 2);

                    if ($retries < $maxRetries) {
                        $context['variables'][$retryKey] = $retries;
                        $execution->context = $context;
                        $execution->save();

                        $retryMsg = !empty($data['retryMessage']) 
                            ? $this->interpolate($data['retryMessage'], $context, $contact)
                            : "Sorry, please select a valid option from the menu above:";

                        // Send retry message to contact
                        if (!$isSimulator && $execution->conversation) {
                            try {
                                $credentials = $execution->conversation->channelConnection->decrypted_credentials;
                                $driver = $this->channelManager->driver($execution->conversation->channelConnection->channel_type);
                                
                                $driver->sendMessage($credentials, [
                                    'external_chat_id' => $execution->conversation->external_chat_id ?? $contact->phone ?? $contact->email ?? 'test_chat',
                                    'body' => $retryMsg,
                                ]);

                                Message::create([
                                    'conversation_id' => $execution->conversation->id,
                                    'tenant_id' => $execution->tenant_id,
                                    'direction' => 'outbound',
                                    'message_type' => 'text',
                                    'sender_identifier' => 'System Flow Builder',
                                    'body' => $retryMsg,
                                    'delivery_status' => 'sent',
                                ]);
                            } catch (Exception $e) {
                                Log::warning("Failed to send menu retry message: " . $e->getMessage());
                            }
                        }

                        return [
                            'status' => 'paused',
                            'execution_status' => 'paused_waiting_reply',
                            'details' => ['invalid_input' => $userInput, 'retry_attempt' => $retries]
                        ];
                    }

                    // Max retries exceeded -> route to fallback branch
                    unset($context['variables'][$waitingKey]);
                    unset($context['variables'][$retryKey]);
                    $execution->context = $context;
                    $execution->save();

                    return [
                        'status' => 'success',
                        'output_port' => 'fallback',
                        'details' => ['fallback' => true, 'invalid_input' => $userInput, 'max_retries_exceeded' => true]
                    ];
                }

                // 2. Initial execution: format and dispatch the menu
                $bodyText = $this->interpolate($data['body'] ?? 'Please select an option from the menu below:', $context, $contact);
                $headerText = !empty($data['header']) ? $this->interpolate($data['header'], $context, $contact) : null;
                $footerText = !empty($data['footer']) ? $this->interpolate($data['footer'], $context, $contact) : null;

                // Build formatted text menu
                $formattedMenu = "";
                if ($headerText) {
                    $formattedMenu .= "*{$headerText}*\n\n";
                }
                $formattedMenu .= $bodyText . "\n\n";

                $numberEmojis = ['1️⃣', '2️⃣', '3️⃣', '4️⃣', '5️⃣', '6️⃣', '7️⃣', '8️⃣', '9️⃣', '🔟'];
                foreach ($items as $idx => $item) {
                    $num = $numberEmojis[$idx] ?? (($idx + 1) . ".");
                    $title = $this->interpolate($item['title'] ?? ("Option " . ($idx + 1)), $context, $contact);
                    $desc = !empty($item['description']) ? " - " . $this->interpolate($item['description'], $context, $contact) : "";
                    $formattedMenu .= "{$num} *{$title}*{$desc}\n";
                }

                if ($footerText) {
                    $formattedMenu .= "\n_{$footerText}_";
                } else {
                    $formattedMenu .= "\n_Reply with the number or title of your choice._";
                }

                $context['variables']['current_save_variable'] = $saveVar;
                $context['variables'][$waitingKey] = true;
                $context['variables'][$retryKey] = 0;
                $execution->context = $context;
                $execution->save();

                if ($isSimulator) {
                    $simulatedMessages = $context['simulated_messages'] ?? [];
                    $simulatedMessages[] = [
                        'direction' => 'outbound',
                        'body' => $formattedMenu,
                        'timestamp' => now()->toIso8601String()
                    ];
                    $context['simulated_messages'] = $simulatedMessages;

                    // Evaluate simulator input or default to first branch
                    $simInput = strtolower(trim($context['variables']['inbound_message_body'] ?? ''));
                    $selectedItem = $items[0] ?? ['id' => 'item_0', 'title' => 'Option 1', 'value' => 'opt_1'];
                    $selectedIndex = 0;

                    foreach ($items as $idx => $item) {
                        $itemId = strtolower($item['id'] ?? ('item_' . $idx));
                        $itemTitle = strtolower($item['title'] ?? '');
                        $itemVal = strtolower($item['value'] ?? '');
                        $num = (string)($idx + 1);

                        if ($simInput === $itemId || $simInput === $itemTitle || $simInput === $itemVal || $simInput === $num || ($itemTitle && str_contains($simInput, $itemTitle))) {
                            $selectedItem = $item;
                            $selectedIndex = $idx;
                            break;
                        }
                    }

                    $portId = $selectedItem['id'] ?? ('item_' . $selectedIndex);
                    $context['variables'][$saveVar] = $selectedItem['value'] ?? ($selectedItem['title'] ?? $portId);
                    $execution->context = $context;
                    $execution->save();

                    return [
                        'status' => 'success',
                        'output_port' => $portId,
                        'details' => ['menu' => $formattedMenu, 'selected_option' => $selectedItem['title'] ?? $portId, 'simulated' => true]
                    ];
                }

                $conversation = $execution->conversation;
                if (!$conversation) {
                    $channelConn = \App\Models\ChannelConnection::where('tenant_id', $execution->tenant_id)
                        ->where('status', 'connected')
                        ->first();
                        
                    if (!$channelConn) {
                        return [
                            'status' => 'failed',
                            'error_message' => 'No active channel connection found to send interactive menu.'
                        ];
                    }

                    $conversation = Conversation::create([
                        'tenant_id' => $execution->tenant_id,
                        'contact_id' => $contact->id,
                        'channel_connection_id' => $channelConn->id,
                        'status' => 'open',
                        'last_message_at' => Carbon::now(),
                    ]);
                    $execution->conversation_id = $conversation->id;
                    $execution->save();
                }

                try {
                    $credentials = $conversation->channelConnection->decrypted_credentials;
                    $driver = $this->channelManager->driver($conversation->channelConnection->channel_type);
                    
                    $res = $driver->sendMessage($credentials, [
                        'external_chat_id' => $conversation->external_chat_id ?? $contact->phone ?? $contact->email ?? 'test_chat',
                        'body' => $formattedMenu,
                    ]);

                    Message::create([
                        'conversation_id' => $conversation->id,
                        'tenant_id' => $execution->tenant_id,
                        'direction' => 'outbound',
                        'message_type' => 'text',
                        'sender_identifier' => 'System Flow Builder',
                        'body' => $formattedMenu,
                        'provider_message_id' => $res['external_message_id'] ?? 'flow_' . uniqid(),
                        'delivery_status' => 'sent',
                    ]);

                    $conversation->update(['last_message_at' => Carbon::now()]);

                    return [
                        'status' => 'paused',
                        'execution_status' => 'paused_waiting_reply',
                        'details' => ['menu' => $formattedMenu, 'save_variable' => $saveVar]
                    ];
                } catch (Exception $e) {
                    return [
                        'status' => 'failed',
                        'error_message' => 'Failed to send interactive menu: ' . $e->getMessage()
                    ];
                }

            case 'wait_delay':
                $delayType = $data['delay_type'] ?? 'duration';
                
                if ($delayType === 'duration') {
                    $val = intval($data['value'] ?? 5);
                    $unit = $data['unit'] ?? 'minutes';

                    $resumeAfter = now();
                    if ($unit === 'minutes') {
                        $resumeAfter->addMinutes($val);
                    } elseif ($unit === 'hours') {
                        $resumeAfter->addHours($val);
                    } else {
                        $resumeAfter->addDays($val);
                    }

                    return [
                        'status' => 'paused',
                        'execution_status' => 'paused_delay',
                        'resume_after' => $resumeAfter,
                        'details' => ['delay_until' => $resumeAfter->toIso8601String()]
                    ];
                } else {
                    return [
                        'status' => 'paused',
                        'execution_status' => 'paused_waiting_reply',
                        'details' => ['waiting_on_event' => true]
                    ];
                }

            case 'tag_contact':
                $tagAction = $data['tag_action'] ?? 'add_tag';
                $targetTag = $data['target_tag'] ?? '';

                if (!empty($targetTag)) {
                    $currentTags = is_array($contact->tags) ? $contact->tags : json_decode($contact->tags ?? '[]', true);
                    
                    if ($tagAction === 'add_tag') {
                        if (!in_array($targetTag, $currentTags)) {
                            $currentTags[] = $targetTag;
                        }
                    } else {
                        $currentTags = array_values(array_filter($currentTags, fn($t) => $t !== $targetTag));
                    }

                    if (!$isSimulator) {
                        $contact->update(['tags' => $currentTags]);
                    } else {
                        $contact->tags = $currentTags; // keep on model local instance
                    }
                }

                return [
                    'status' => 'success',
                    'output_port' => 'out',
                    'details' => ['action' => $tagAction, 'tag' => $targetTag]
                ];

            case 'update_contact':
                $matrix = $data['updates'] ?? [];
                $updatedFields = [];

                foreach ($matrix as $row) {
                    $field = $row['field'] ?? '';
                    $val = $this->interpolate($row['value'] ?? '', $context, $contact);

                    if (str_starts_with($field, 'custom_fields.')) {
                        $cfKey = substr($field, 14);
                        $cf = is_array($contact->custom_fields) ? $contact->custom_fields : json_decode($contact->custom_fields ?? '[]', true);
                        $cf[$cfKey] = $val;
                        $contact->custom_fields = $cf;
                        $updatedFields[$field] = $val;
                    } else {
                        $contact->{$field} = $val;
                        $updatedFields[$field] = $val;
                    }
                }

                if (!$isSimulator && !empty($updatedFields)) {
                    $contact->save();
                }

                return [
                    'status' => 'success',
                    'output_port' => 'out',
                    'details' => ['updated' => $updatedFields]
                ];

            case 'create_deal':
                $title = $this->interpolate($data['deal_name'] ?? 'Flow Deal', $context, $contact);
                $value = floatval($data['deal_value'] ?? 0.0);
                $stage = $data['stage_id'] ?? 'new';

                if (!$isSimulator) {
                    $deal = Deal::create([
                        'tenant_id' => $execution->tenant_id,
                        'contact_id' => $contact->id,
                        'title' => $title,
                        'amount' => $value,
                        'stage' => $stage,
                    ]);
                    $context['variables']['last_created_deal_id'] = $deal->id;
                } else {
                    $context['variables']['last_created_deal_id'] = 999; // mock id
                }

                $execution->context = $context;
                $execution->save();

                return [
                    'status' => 'success',
                    'output_port' => 'success',
                    'details' => ['deal_title' => $title, 'amount' => $value]
                ];

            case 'create_appointment':
                $duration = intval($data['booking_duration'] ?? 30);
                $provider = $data['conference_provider'] ?? 'google_meet';

                $rawDate = null;
                $rawTime = null;

                // 1. Scan variable metadata (_meta) for declared date/time/datetime types
                $meta = $context['variables']['_meta'] ?? [];
                foreach ($meta as $varName => $varMeta) {
                    $varType = strtolower($varMeta['type'] ?? '');
                    $val = trim(strval($context['variables'][$varName] ?? ''));
                    if (empty($val)) continue;

                    if ($varType === 'date' && !$rawDate) {
                        $rawDate = $val;
                    } elseif ($varType === 'time' && !$rawTime) {
                        $rawTime = $val;
                    } elseif ($varType === 'datetime') {
                        if (!$rawDate) $rawDate = $val;
                        if (!$rawTime) $rawTime = $val;
                    }
                }

                // 2. If not found in _meta, dynamically inspect all collected variables without hardcoding specific names
                if (empty($rawDate) || empty($rawTime)) {
                    foreach ($context['variables'] as $k => $v) {
                        if ($k === '_meta' || $k === 'inbound_message_body' || !is_string($v) || empty(trim($v))) continue;
                        $lk = strtolower($k);
                        if (empty($rawDate) && (str_contains($lk, 'date') || str_contains($lk, 'day'))) {
                            $rawDate = trim($v);
                        }
                        if (empty($rawTime) && (str_contains($lk, 'time') || str_contains($lk, 'slot') || str_contains($lk, 'hour'))) {
                            $rawTime = trim($v);
                        }
                    }
                }

                $tz = $execution->tenant->timezone ?? config('app.timezone');
                if (empty($tz) || $tz === 'UTC') {
                    $tz = 'Asia/Kolkata';
                }

                $parsedDate = null;
                $parsedTime = null;

                if (!empty($rawDate)) {
                    try {
                        $parsedDate = Carbon::parse($rawDate, $tz);
                    } catch (Exception $e) {
                        // ignore parse error
                    }
                }

                if (!empty($rawTime)) {
                    try {
                        $parsedTime = Carbon::parse($rawTime, $tz);
                    } catch (Exception $e) {
                        // ignore parse error
                    }
                }

                if ($parsedDate && $parsedTime) {
                    $startTime = Carbon::create($parsedDate->year, $parsedDate->month, $parsedDate->day, $parsedTime->hour, $parsedTime->minute, $parsedTime->second, $tz);
                } elseif ($parsedDate) {
                    $startTime = Carbon::create($parsedDate->year, $parsedDate->month, $parsedDate->day, 10, 0, 0, $tz);
                } elseif ($parsedTime) {
                    $startTime = Carbon::now($tz)->setTime($parsedTime->hour, $parsedTime->minute, $parsedTime->second);
                    if ($startTime->isPast()) {
                        $startTime->addDay();
                    }
                } else {
                    $startTime = Carbon::now($tz)->addHours(24)->roundMinute(30);
                }

                $endTime = (clone $startTime)->addMinutes($duration);

                // Resolve Target Staff / Resource Member
                $targetStaffId = $data['staff_id'] ?? null;
                $targetUserId = $data['user_id'] ?? null;
                $resourceName = $data['resource_name'] ?? null;

                // Check dynamic context variables if staff/resource was selected or mentioned via chat
                if (!$targetStaffId && empty($resourceName)) {
                    $staffCandidates = [
                        $context['variables']['staff_id'] ?? null,
                        $context['variables']['selected_staff'] ?? null,
                        $context['variables']['doctor'] ?? null,
                        $context['variables']['specialist'] ?? null,
                        $context['variables']['resource'] ?? null,
                        $context['variables']['assigned_staff'] ?? null,
                        $context['variables']['host_name'] ?? null,
                        $context['variables']['host_id'] ?? null,
                    ];

                    foreach ($staffCandidates as $candidate) {
                        if (empty($candidate)) continue;
                        if (is_numeric($candidate)) {
                            // Check if valid StaffMember id
                            $sMember = \App\Models\StaffMember::where('tenant_id', $execution->tenant_id)->find(intval($candidate));
                            if ($sMember) {
                                $targetStaffId = $sMember->id;
                                break;
                            }
                        } else {
                            $cleanCandidate = trim(strval($candidate));
                            $foundStaff = \App\Models\StaffMember::where('tenant_id', $execution->tenant_id)
                                ->where(function ($q) use ($cleanCandidate) {
                                    $q->where('name', 'like', "%{$cleanCandidate}%")
                                      ->orWhere('title', 'like', "%{$cleanCandidate}%")
                                      ->orWhere('email', 'like', "%{$cleanCandidate}%");
                                })
                                ->first();

                            if ($foundStaff) {
                                $targetStaffId = $foundStaff->id;
                                break;
                            } elseif (empty($resourceName)) {
                                $resourceName = $cleanCandidate;
                            }
                        }
                    }
                }

                // Fallback to booking link staff or user
                if (!$targetStaffId && !empty($data['booking_link_id'])) {
                    $bLink = BookingLink::find($data['booking_link_id']);
                    if ($bLink) {
                        if ($bLink->staff_id) {
                            $targetStaffId = $bLink->staff_id;
                        } elseif ($bLink->user_id) {
                            $targetUserId = $bLink->user_id;
                        }
                    }
                }

                $assignedStaff = $targetStaffId ? \App\Models\StaffMember::where('tenant_id', $execution->tenant_id)->find($targetStaffId) : null;
                $assignedUser = $targetUserId ? \App\Models\User::where('tenant_id', $execution->tenant_id)->find($targetUserId) : null;

                $staffName = $assignedStaff ? $assignedStaff->name : ($assignedUser ? ($assignedUser->first_name . ' ' . $assignedUser->last_name) : ($resourceName ?: 'Staff Member'));
                $staffEmail = $assignedStaff ? $assignedStaff->email : ($assignedUser ? $assignedUser->email : null);
                $staffTitle = $assignedStaff ? $assignedStaff->title : null;

                $meetingLink = null;
                $appointment = null;

                if (!$isSimulator) {
                    $appointment = Appointment::create([
                        'tenant_id' => $execution->tenant_id,
                        'contact_id' => $contact->id,
                        'booking_link_id' => $data['booking_link_id'] ?? null,
                        'staff_id' => $targetStaffId,
                        'user_id' => $targetUserId,
                        'resource_name' => $resourceName,
                        'start_time' => $startTime,
                        'end_time' => $endTime,
                        'status' => 'scheduled',
                        'notes' => 'Booked automatically via AI Visual Flow Builder',
                    ]);

                    ContactActivity::create([
                        'tenant_id' => $execution->tenant_id,
                        'contact_id' => $contact->id,
                        'type' => 'appointment_scheduled',
                        'description' => "Appointment scheduled with {$staffName} via AI Flow Builder for " . $startTime->format('M d, Y h:i A'),
                        'metadata' => [
                            'appointment_id' => $appointment->id,
                            'staff_id' => $targetStaffId,
                            'user_id' => $targetUserId,
                            'resource_name' => $resourceName,
                            'start_time' => $startTime->toIso8601String(),
                        ]
                    ]);

                    try {
                        $meetingService = app(\App\Services\MeetingService::class);
                        $meetingLink = $meetingService->generateMeetingLink($appointment);
                    } catch (Exception $e) {
                        Log::warning("Failed to generate meeting link via service: " . $e->getMessage());
                    }

                    if (!$meetingLink) {
                        $meetingLink = config('app.url') . "/book/meeting-" . substr(md5(uniqid()), 0, 8);
                    }

                    $appointment->update(['meeting_link' => $meetingLink]);

                    try {
                        $calendarService = app(\App\Services\GoogleCalendarService::class);
                        $calendarService->syncAppointment($appointment);
                    } catch (Exception $e) {
                        Log::warning("Failed to sync appointment with Google Calendar: " . $e->getMessage());
                    }
                } else {
                    $meetingLink = config('app.url') . "/book/meeting-" . substr(md5(uniqid()), 0, 8);
                }

                $context['variables']['appointment_time'] = $startTime->format('M d, Y h:i A');
                $context['variables']['meeting_link'] = $meetingLink;
                $context['variables']['appointment_staff_name'] = $staffName;
                $context['variables']['appointment_staff_id'] = $targetStaffId;
                $context['variables']['appointment_staff_email'] = $staffEmail;
                $context['variables']['appointment_staff_title'] = $staffTitle;

                // Clear temporary date/time variables so subsequent bookings capture fresh values
                unset($context['variables']['aapt_date']);
                unset($context['variables']['aapt_time']);
                unset($context['variables']['appointment_date']);
                unset($context['variables']['appointment_time_input']);
                
                $execution->context = $context;
                $execution->save();

                return [
                    'status' => 'success',
                    'output_port' => 'scheduled',
                    'details' => [
                        'appointment_id' => $appointment->id ?? null,
                        'scheduled_time' => $startTime->toDateTimeString(),
                        'staff_name' => $staffName,
                        'staff_id' => $targetStaffId,
                        'link' => $meetingLink
                    ]
                ];

            case 'human_handoff':
                if (!$isSimulator) {
                    $conversation = $execution->conversation;
                    if ($conversation) {
                        $conversation->update([
                            'ai_active' => false,
                            'status' => 'open'
                        ]);
                        
                        // System note details
                        Message::create([
                            'conversation_id' => $conversation->id,
                            'tenant_id' => $execution->tenant_id,
                            'direction' => 'outbound',
                            'message_type' => 'note',
                            'sender_identifier' => 'System Flow Builder',
                            'body' => $data['internal_note'] ?? 'Transferring bot chat to human support operator agent.',
                            'delivery_status' => 'read',
                        ]);
                    }
                }

                // Halt further loop processing
                return [
                    'status' => 'paused',
                    'execution_status' => 'completed',
                    'details' => ['handoff' => true]
                ];

            case 'webhook_dispatch':
            case 'n8n':
            case 'zapier':
                $url = $this->interpolate($data['url'] ?? $data['webhook_url'] ?? $data['webhookUrl'] ?? '', $context, $contact);
                $method = strtoupper($data['method'] ?? 'POST');
                $bodyText = $this->interpolate($data['body'] ?? '{}', $context, $contact);
                $defaultSaveKey = $node['type'] === 'n8n' ? 'n8n_response' : ($node['type'] === 'zapier' ? 'zapier_response' : 'webhook_response');
                $saveKey = $data['save_key'] ?? $data['saveKey'] ?? $defaultSaveKey;
                $variableMappings = $data['variable_mappings'] ?? $data['variableMappings'] ?? [];

                // Resolve headers
                $headers = [];
                if (!empty($data['headers']) && is_array($data['headers'])) {
                    foreach ($data['headers'] as $h) {
                        if (!empty($h['key'])) {
                            $headers[$h['key']] = $this->interpolate($h['value'] ?? '', $context, $contact);
                        }
                    }
                }

                // Support specific auth configurations
                $authType = $data['auth_type'] ?? $data['authType'] ?? 'none';
                if ($authType === 'bearer' && !empty($data['auth_token'] ?? $data['authToken'])) {
                    $token = $this->interpolate($data['auth_token'] ?? $data['authToken'], $context, $contact);
                    $headers['Authorization'] = 'Bearer ' . $token;
                } elseif ($authType === 'header' && !empty($data['auth_header_name'] ?? $data['authHeaderName'])) {
                    $headerName = trim($data['auth_header_name'] ?? $data['authHeaderName']);
                    $headerVal = $this->interpolate($data['auth_header_value'] ?? $data['authHeaderValue'] ?? '', $context, $contact);
                    if (!empty($headerName)) {
                        $headers[$headerName] = $headerVal;
                    }
                } elseif ($authType === 'basic' && (!empty($data['auth_user'] ?? $data['authUser']) || !empty($data['auth_password'] ?? $data['authPassword']))) {
                    $user = $this->interpolate($data['auth_user'] ?? $data['authUser'] ?? '', $context, $contact);
                    $pass = $this->interpolate($data['auth_password'] ?? $data['authPassword'] ?? '', $context, $contact);
                    $headers['Authorization'] = 'Basic ' . base64_encode("{$user}:{$pass}");
                }

                if ($isSimulator) {
                    $sampleJson = $data['example_response'] ?? $data['exampleResponse'] ?? $data['sample_response'] ?? null;
                    $simulatedData = [];
                    if (!empty($sampleJson)) {
                        $decoded = is_array($sampleJson) ? $sampleJson : json_decode((string) $sampleJson, true);
                        if (is_array($decoded)) {
                            $simulatedData = $decoded;
                        }
                    }
                    if (empty($simulatedData)) {
                        $simulatedData = ['status' => 'success', 'code' => 200, 'data' => ['success' => true, 'message' => 'Simulated API execution successful']];
                    }

                    if (!empty($saveKey)) {
                        $context['variables'][$saveKey] = $simulatedData;
                    }

                    // Map variables into context
                    if (is_array($variableMappings)) {
                        foreach ($variableMappings as $mapping) {
                            $varName = trim($mapping['variable_name'] ?? $mapping['variableName'] ?? '');
                            $jsonPath = trim($mapping['json_path'] ?? $mapping['jsonPath'] ?? '');
                            $fallback = $mapping['fallback'] ?? '';

                            if (!empty($varName) && !empty($jsonPath)) {
                                $val = data_get($simulatedData, $jsonPath, $fallback);
                                $context['variables'][$varName] = is_array($val) ? json_encode($val) : $val;
                            }
                        }
                    }

                    $execution->context = $context;
                    $execution->save();
                    return [
                        'status' => 'success',
                        'output_port' => 'success',
                        'details' => ['url' => $url, 'simulated' => true, 'mapped_variables' => $variableMappings]
                    ];
                }

                try {
                    \App\Services\Security\UrlSecurityValidator::assertSafeUrl($url);

                    $bodyArray = json_decode($bodyText, true) ?? [];
                    $client = Http::timeout(15);
                    if (!empty($headers)) {
                        $client = $client->withHeaders($headers);
                    }

                    switch ($method) {
                        case 'GET':
                            $response = $client->get($url);
                            break;
                        case 'PUT':
                            $response = $client->put($url, $bodyArray);
                            break;
                        case 'PATCH':
                            $response = $client->patch($url, $bodyArray);
                            break;
                        case 'DELETE':
                            $response = $client->delete($url, $bodyArray);
                            break;
                        case 'POST':
                        default:
                            $response = $client->post($url, $bodyArray);
                            break;
                    }

                    if ($response->successful()) {
                        $resData = $response->json();
                        if (!is_array($resData)) {
                            $resData = ['raw_response' => (string) $response->body()];
                        }

                        if (!empty($saveKey)) {
                            $context['variables'][$saveKey] = $resData;
                        }

                        // Map extracted response fields into context variables
                        if (is_array($variableMappings)) {
                            foreach ($variableMappings as $mapping) {
                                $varName = trim($mapping['variable_name'] ?? $mapping['variableName'] ?? '');
                                $jsonPath = trim($mapping['json_path'] ?? $mapping['jsonPath'] ?? '');
                                $fallback = $mapping['fallback'] ?? '';

                                if (!empty($varName) && !empty($jsonPath)) {
                                    $val = data_get($resData, $jsonPath, $fallback);
                                    $context['variables'][$varName] = is_array($val) ? json_encode($val) : $val;
                                }
                            }
                        }

                        $execution->context = $context;
                        $execution->save();

                        return [
                            'status' => 'success',
                            'output_port' => 'success',
                            'details' => ['status' => $response->status(), 'response' => $resData]
                        ];
                    }

                    return [
                        'status' => 'success',
                        'output_port' => 'error',
                        'details' => ['status' => $response->status(), 'error' => $response->body()]
                    ];
                } catch (Exception $e) {
                    return [
                        'status' => 'success',
                        'output_port' => 'error',
                        'details' => ['exception' => $e->getMessage()]
                    ];
                }

            case 'ai_prompt':
                $prompt = $data['prompt'] ?? '';
                $resolvedPrompt = $this->interpolate($prompt, $context, $contact);
                $saveKey = $data['save_key'] ?? 'ai_response';

                // Resolve AI Agent associated with the flow or conversation
                $flowVersion = \App\Models\FlowVersion::find($execution->flow_version_id);
                $flowId = $flowVersion ? $flowVersion->flow_id : null;
                $agent = null;
                if ($flowId) {
                    $agent = \App\Models\AiAgent::where('tenant_id', $execution->tenant_id)
                        ->where('flow_id', $flowId)
                        ->first();
                }
                if (!$agent && !empty($execution->conversation->ai_agent_id)) {
                    $agent = \App\Models\AiAgent::find($execution->conversation->ai_agent_id);
                }

                // Resolve AI Provider & Model via unified operational model resolver
                $resolvedAI = $this->aiService->resolveAIExecution(
                    $execution->tenant_id,
                    'flow_ai_prompt',
                    $node['data']['provider'] ?? ($agent && $agent->providerConfig ? $agent->providerConfig->provider_name : null),
                    $node['data']['model'] ?? ($agent ? $agent->model : null)
                );
                $provider = $resolvedAI['provider'];
                $apiKey = $resolvedAI['api_key'];
                $model = $resolvedAI['model'];

                if (!empty($resolvedAI['is_blocked'])) {
                    $completion = $resolvedAI['fallback_message'] ?: 'AI service temporarily unavailable due to limits.';
                    $context['variables'][$saveKey] = $completion;
                    $execution->context = $context;
                    $execution->save();

                    return [
                        'status' => 'success',
                        'output_port' => 'success',
                        'details' => ['provider' => $resolvedAI['provider'] ?? 'system', 'response' => $completion, 'is_blocked' => true]
                    ];
                }

                $sysPrompt = $data['system_instructions'] ?? ($agent && !empty($agent->system_prompt) ? $agent->system_prompt : "You are a helpful automated virtual assistant.");
                
                $messages = [
                    ['role' => 'system', 'content' => $sysPrompt],
                    ['role' => 'user', 'content' => $resolvedPrompt]
                ];

                $completion = $this->aiService->generateCompletion($provider, $apiKey, $model, $messages, 0.7, 'flow_ai_prompt', $execution->tenant_id);
                
                $context['variables'][$saveKey] = $completion;
                $execution->context = $context;
                $execution->save();

                return [
                    'status' => 'success',
                    'output_port' => 'success',
                    'details' => ['provider' => $provider, 'response' => $completion]
                ];

            case 'rag_query':
                $saveKey = $data['save_key'] ?? $data['saveKey'] ?? 'rag_results';
                $kbId = $data['knowledge_base_id'] ?? null;
                $waitingKey = 'rag_query_waiting_' . $node['id'];
                $userMsg = trim($context['variables']['inbound_message_body'] ?? '');
                
                // Mode: 'loop' (continuous loop until exit keyword) or 'single' (one-time answer then advance)
                $mode = $data['mode'] ?? (($data['continuous_loop'] ?? true) === false ? 'single' : 'loop');
                $sendAnswer = $data['send_answer'] ?? true;

                // Resolve Exit Keywords (supports comma-separated string or array)
                $exitKeywordsRaw = $data['exit_keywords'] ?? $data['exitKeywords'] ?? 'exit, bye, quit, end, stop, thanks, thank you, no, nothing';
                if (is_array($exitKeywordsRaw)) {
                    $exitKeywords = array_filter(array_map(fn($k) => strtolower(trim((string)$k)), $exitKeywordsRaw));
                } else {
                    $exitKeywords = array_filter(array_map('trim', explode(',', strtolower((string)$exitKeywordsRaw))));
                }
                if (empty($exitKeywords)) {
                    $exitKeywords = ['exit', 'bye', 'quit', 'end', 'stop', 'thanks', 'thank you', 'no', 'nothing'];
                }

                // Resolve AI Agent associated with the flow or conversation
                $flowVersion = \App\Models\FlowVersion::find($execution->flow_version_id);
                $flowId = $flowVersion ? $flowVersion->flow_id : null;
                $agent = null;
                if ($flowId) {
                    $agent = \App\Models\AiAgent::where('tenant_id', $execution->tenant_id)
                        ->where('flow_id', $flowId)
                        ->first();
                }
                if (!$agent && !empty($execution->conversation->ai_agent_id)) {
                    $agent = \App\Models\AiAgent::find($execution->conversation->ai_agent_id);
                }

                // Inherit Knowledge Base from associated AI Agent if not explicitly set in node
                if (empty($kbId) && $agent && !empty($agent->knowledge_base_id)) {
                    $kbId = $agent->knowledge_base_id;
                }

                // In Looping Mode: Check if user typed exit keyword while in active RAG Q&A session
                if ($mode === 'loop' && !empty($context['variables'][$waitingKey])) {
                    $cleanUserMsg = strtolower(trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $userMsg)));
                    $isExit = false;

                    if (in_array(strtolower(trim($userMsg)), $exitKeywords) || in_array($cleanUserMsg, $exitKeywords)) {
                        $isExit = true;
                    } else {
                        foreach ($exitKeywords as $kw) {
                            $kw = trim($kw);
                            if (!empty($kw) && (
                                $cleanUserMsg === $kw || 
                                preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $userMsg) ||
                                preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $cleanUserMsg)
                            )) {
                                $isExit = true;
                                break;
                            }
                        }
                    }

                    if ($isExit) {
                        unset($context['variables'][$waitingKey]);
                        $execution->context = $context;
                        $execution->save();
                        return [
                            'status' => 'success',
                            'output_port' => 'out',
                            'details' => ['query' => $userMsg, 'exited' => true, 'mode' => 'loop']
                        ];
                    }
                }

                $query = $this->interpolate($data['query'] ?? '', $context, $contact);
                if (empty($query)) {
                    $query = $userMsg;
                }

                // If query is an email/phone from a previous ask_question step in looping mode, prompt user for KB question first
                $isUpstreamField = str_contains($query, '@') || (is_numeric($query) && strlen($query) > 6);
                if ($mode === 'loop' && $isUpstreamField && empty($context['variables'][$waitingKey])) {
                    $kb = !empty($kbId) ? \App\Models\KnowledgeBase::find($kbId) : null;
                    $kbTitle = $kb ? $kb->name : 'our Knowledge Base';
                    $promptMsg = "How can I assist you with {$kbTitle} today? Feel free to ask any questions!";
                    $context['variables'][$waitingKey] = true;
                    $execution->context = $context;
                    $execution->save();

                    if (!$isSimulator) {
                        $conversation = $execution->conversation;
                        if ($conversation) {
                            try {
                                $credentials = $conversation->channelConnection->decrypted_credentials;
                                $driver = $this->channelManager->driver($conversation->channelConnection->channel_type);
                                $res = $driver->sendMessage($credentials, [
                                    'external_chat_id' => $conversation->external_chat_id ?? $contact->phone ?? $contact->email ?? 'test_chat',
                                    'body' => $promptMsg,
                                ]);
                                Message::create([
                                    'conversation_id' => $conversation->id,
                                    'tenant_id' => $execution->tenant_id,
                                    'direction' => 'outbound',
                                    'message_type' => 'text',
                                    'sender_identifier' => 'System Flow Builder',
                                    'body' => $promptMsg,
                                    'provider_message_id' => $res['external_message_id'] ?? 'flow_' . uniqid(),
                                    'delivery_status' => 'sent',
                                ]);
                            } catch (Exception $e) {
                                Log::warning("RAG Query node failed to send prompt: " . $e->getMessage());
                            }
                        }
                    }

                    return [
                        'status' => 'paused',
                        'execution_status' => 'paused_waiting_reply',
                        'output_port' => null,
                        'details' => ['waiting_for_kb_query' => true, 'mode' => 'loop']
                    ];
                }

                $results = null;
                if (!empty($kbId) && !$isSimulator) {
                    try {
                        $queryVector = $this->embeddingService->getEmbedding($execution->tenant_id, $query);
                        $chunks = \App\Models\KnowledgeChunk::whereHas('source', function ($q) use ($kbId) {
                            $q->where('knowledge_base_id', $kbId)->where('status', 'indexed');
                        })->get();

                        $matches = [];
                        foreach ($chunks as $chunk) {
                            $vectorSim = $this->embeddingService->cosineSimilarity($queryVector, $chunk->embedding);
                            $textSim = $this->embeddingService->textSimilarity($query, $chunk->content);
                            $similarity = max($vectorSim, $textSim);

                            $matches[] = [
                                'content' => $chunk->content,
                                'similarity' => $similarity
                            ];
                        }

                        usort($matches, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
                        
                        // Pass comprehensive context: if total chunks <= 15, supply all chunks; otherwise top 12 chunks
                        $topChunks = count($matches) <= 15 ? $matches : array_slice($matches, 0, 12);
                        
                        if (!empty($topChunks)) {
                            $chunkText = "";
                            foreach ($topChunks as $m) {
                                $chunkText .= "- " . trim($m['content']) . "\n\n";
                            }

                            // Resolve AI Provider & Model via unified operational model resolver
                            $resolvedAI = $this->aiService->resolveAIExecution(
                                $execution->tenant_id,
                                'flow_rag_query',
                                $data['provider'] ?? ($agent && $agent->providerConfig ? $agent->providerConfig->provider_name : null),
                                $data['model'] ?? ($agent ? $agent->model : null)
                            );
                            $provider = $resolvedAI['provider'];
                            $apiKey = $resolvedAI['api_key'];
                            $model = $resolvedAI['model'];

                            if (!empty($resolvedAI['is_blocked'])) {
                                $results = $resolvedAI['fallback_message'] ?: 'AI service temporarily unavailable due to limits.';
                            } else {
                                $kb = !empty($kbId) ? \App\Models\KnowledgeBase::find($kbId) : null;
                                $kbName = $kb ? $kb->name : ($execution->tenant->company_name ?? 'our organization');

                                // Build grounded prompt with Agent persona if present
                                $agentPersona = ($agent && !empty($agent->system_prompt)) ? "Agent Persona & Role Instructions:\n{$agent->system_prompt}\n\n" : "";

                                $sysPrompt = "You are the professional, friendly, and knowledgeable AI assistant representing {$kbName}.\n\n"
                                           . $agentPersona
                                           . "CORE GUIDELINES:\n"
                                           . "1. Use the provided Knowledge Base facts below as your primary source of truth to answer user inquiries accurately and concisely.\n"
                                           . "2. Answer questions naturally, politely, and helpfully. For greetings, thank-you messages, or general inquiries, respond warmly and guide the user on how you can assist them based on your offerings and details.\n"
                                           . "3. If the user asks for specific information that is genuinely absent from the Knowledge Base (e.g. unlisted custom pricing, unmentioned private records, or external facts), politely clarify that and provide relevant contact methods, consultation booking options, or support channels from the Knowledge Base.\n"
                                           . "4. Do NOT invent false claims or provide ungrounded external information.\n"
                                           . "5. Format responses neatly with bullet points where appropriate.\n\n"
                                           . "KNOWLEDGE BASE CONTEXT:\n{$chunkText}";

                                $messages = [
                                    ['role' => 'system', 'content' => $sysPrompt],
                                    ['role' => 'user', 'content' => $query]
                                ];

                                $results = $this->aiService->generateCompletion($provider, $apiKey, $model, $messages, 0.7, 'flow_rag_query', $execution->tenant_id);
                            }
                        }
                    } catch (Exception $e) {
                        Log::error("RAG Query node execution failed: " . $e->getMessage());
                    }
                }

                $kb = !empty($kbId) ? \App\Models\KnowledgeBase::find($kbId) : null;
                $kbName = $kb ? $kb->name : ($execution->tenant->company_name ?? 'our organization');

                if (empty($results) || str_contains($results, 'Simulated') || str_contains($results, 'simulated AI response')) {
                    $results = "I am here to help! Feel free to ask any question about our services, products, pricing, or how we can assist you.";
                }
                
                $context['variables'][$saveKey] = $results;

                if ($sendAnswer) {
                    if ($isSimulator) {
                        $simulatedMessages = $context['simulated_messages'] ?? [];
                        $simulatedMessages[] = [
                            'direction' => 'outbound',
                            'body' => $results,
                            'timestamp' => now()->toIso8601String()
                        ];
                        $context['simulated_messages'] = $simulatedMessages;
                    } else {
                        $conversation = $execution->conversation;
                        if ($conversation) {
                            try {
                                $credentials = $conversation->channelConnection->decrypted_credentials;
                                $driver = $this->channelManager->driver($conversation->channelConnection->channel_type);
                                
                                $res = $driver->sendMessage($credentials, [
                                    'external_chat_id' => $conversation->external_chat_id ?? $contact->phone ?? $contact->email ?? 'test_chat',
                                    'body' => $results,
                                ]);

                                Message::create([
                                    'conversation_id' => $conversation->id,
                                    'tenant_id' => $execution->tenant_id,
                                    'direction' => 'outbound',
                                    'message_type' => 'text',
                                    'sender_identifier' => 'System Flow Builder',
                                    'body' => $results,
                                    'provider_message_id' => $res['external_message_id'] ?? 'flow_' . uniqid(),
                                    'delivery_status' => 'sent',
                                ]);
                            } catch (Exception $e) {
                                Log::warning("RAG Query node failed to send outbound message: " . $e->getMessage());
                            }
                        }
                    }
                }

                // If Single Answer Mode: Advance to next node immediately!
                if ($mode === 'single') {
                    if (isset($context['variables'][$waitingKey])) {
                        unset($context['variables'][$waitingKey]);
                    }
                    $execution->context = $context;
                    $execution->save();

                    return [
                        'status' => 'success',
                        'output_port' => 'out',
                        'details' => ['query' => $query, 'results' => $results, 'mode' => 'single']
                    ];
                }

                // If Looping Mode: Pause and wait for next reply / exit keyword
                $context['variables'][$waitingKey] = true;
                $execution->context = $context;
                $execution->save();

                return [
                    'status' => 'paused',
                    'execution_status' => 'paused_waiting_reply',
                    'output_port' => null,
                    'details' => ['query' => $query, 'results' => $results, 'mode' => 'loop']
                ];

            default:
                return [
                    'status' => 'success',
                    'output_port' => 'out',
                    'details' => ['skipped' => true]
                ];
        }
    }
}
