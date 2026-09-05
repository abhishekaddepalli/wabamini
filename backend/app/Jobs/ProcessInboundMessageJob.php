<?php

namespace App\Jobs;

use App\Models\ChannelConnection;
use App\Models\Conversation;
use App\Models\Contact;
use App\Models\Message;
use App\Services\Channels\ChannelManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use Exception;

class ProcessInboundMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $connectionId;
    public array $data;

    /**
     * Create a new job instance.
     */
    public function __construct(int $connectionId, array $data)
    {
        $this->connectionId = $connectionId;
        $this->data = $data;
    }

    /**
     * Execute the job.
     */
    public function handle(ChannelManager $channelManager): void
    {
        $connection = ChannelConnection::find($this->connectionId);
        if (!$connection) {
            Log::error("ProcessInboundMessageJob: Connection ID {$this->connectionId} not found.");
            return;
        }

        $driver = $channelManager->driver($connection->channel_type);

        // Check if status update vs inbound text
        $isStatus = false;
        try {
            // A status update typically contains keys like status, delivery_status, or entry.changes.statuses
            if (isset($this->data['delivery_status']) || isset($this->data['status']) || isset($this->data['type']) && $this->data['type'] === 'status') {
                $isStatus = true;
            }
        } catch (Exception $e) {}

        if ($isStatus) {
            $this->handleStatusUpdate($driver);
        } else {
            $this->handleInboundMessage($driver, $connection);
        }
    }

    protected function handleStatusUpdate($driver): void
    {
        try {
            $statusData = $driver->normalizeStatusPayload($this->data);
            $externalId = $statusData['external_message_id'] ?? null;

            if (!$externalId) {
                return;
            }

            $message = Message::where('external_message_id', $externalId)->first();
            if ($message) {
                $message->update([
                    'delivery_status' => $statusData['delivery_status'] ?? 'delivered',
                    'error_message' => $statusData['error_message'] ?? null,
                ]);

                try {
                    broadcast(new \App\Events\MessageStatusUpdated($message))->toOthers();
                } catch (Exception $e) {
                    Log::debug("Broadcasting MessageStatusUpdated skipped: " . $e->getMessage());
                }
            }
        } catch (Exception $e) {
            Log::error("Error processing webhook status update: " . $e->getMessage());
        }
    }

    protected function handleInboundMessage($driver, ChannelConnection $connection): void
    {
        try {
            $inbound = $driver->normalizeInboundPayload($this->data);

            // Post-process Telegram or WhatsApp media to store it locally
            if (!empty($inbound['media_url']) && !str_starts_with($inbound['media_url'], 'http')) {
                if ($connection->channel_type === 'telegram') {
                    $token = $connection->decrypted_credentials['token'] ?? null;
                    if ($token) {
                        try {
                            $response = \Illuminate\Support\Facades\Http::timeout(10)->get("https://api.telegram.org/bot{$token}/getFile", [
                                'file_id' => $inbound['media_url'],
                            ]);
                            if ($response->successful() && $response->json('ok')) {
                                $filePath = $response->json('result.file_path');
                                $fileUrl = "https://api.telegram.org/file/bot{$token}/{$filePath}";
                                
                                $fileContent = \Illuminate\Support\Facades\Http::timeout(20)->get($fileUrl)->body();
                                $ext = pathinfo($filePath, PATHINFO_EXTENSION) ?: 'jpg';
                                $localPath = 'media/tg_' . uniqid() . '.' . $ext;
                                \Illuminate\Support\Facades\Storage::disk('public')->put($localPath, $fileContent);
                                
                                $inbound['media_url'] = asset('storage/' . $localPath);
                            }
                        } catch (Exception $e) {
                            Log::error("Telegram media storage failed: " . $e->getMessage());
                        }
                    }
                } elseif ($connection->channel_type === 'whatsapp_cloud_api') {
                    $accessToken = $connection->decrypted_credentials['system_user_access_token'] ?? null;
                    if ($accessToken) {
                        try {
                            $mediaId = $inbound['media_url'];
                            $response = \Illuminate\Support\Facades\Http::withToken($accessToken)->timeout(10)->get("https://graph.facebook.com/v19.0/{$mediaId}");
                            if ($response->successful() && $response->json('url')) {
                                $fbUrl = $response->json('url');
                                $mimeType = $response->json('mime_type');
                                $ext = 'jpg';
                                if ($mimeType) {
                                    $parts = explode('/', $mimeType);
                                    if (count($parts) > 1) {
                                        $ext = $parts[1];
                                    }
                                }
                                
                                $fileContent = \Illuminate\Support\Facades\Http::withToken($accessToken)->timeout(20)->get($fbUrl)->body();
                                $localPath = 'media/wa_' . uniqid() . '.' . $ext;
                                \Illuminate\Support\Facades\Storage::disk('public')->put($localPath, $fileContent);
                                
                                $inbound['media_url'] = asset('storage/' . $localPath);
                            }
                        } catch (Exception $e) {
                            Log::error("WhatsApp Cloud API media storage failed: " . $e->getMessage());
                        }
                    }
                }
            }

            $externalChatId = $inbound['external_chat_id'] ?? null;
            $externalMsgId = $inbound['external_message_id'] ?? null;

            if (!$externalChatId) {
                Log::warning("ProcessInboundMessageJob: Missing external_chat_id in payload.");
                return;
            }

            // Find or create Conversation
            $conversation = null;
            if ($connection->channel_type === 'email' && !empty($inbound['in_reply_to'])) {
                $parentMsg = Message::where('external_message_id', $inbound['in_reply_to'])->first();
                if ($parentMsg) {
                    $conversation = Conversation::find($parentMsg->conversation_id);
                }
            }

            if (!$conversation) {
                // Check if an active AI Agent is bound to this channel
                $activeAgent = \App\Models\AiAgent::where('tenant_id', $connection->tenant_id)
                    ->where('status', 'active')
                    ->whereHas('channels', function ($q) use ($connection) {
                        $q->where('channel_connections.id', $connection->id);
                    })
                    ->first();

                $conversation = Conversation::firstOrCreate(
                    [
                        'channel_connection_id' => $connection->id,
                        'external_chat_id' => $externalChatId
                    ],
                    [
                        'tenant_id' => $connection->tenant_id,
                        'ai_active' => $activeAgent ? true : false,
                        'ai_agent_id' => $activeAgent ? $activeAgent->id : null,
                    ]
                );
            }

            // Find or link Contact if not associated
            if (!$conversation->contact_id) {
                $contact = null;
                $rawIdentifier = (string) $externalChatId;
                $cleanId = explode(':', $rawIdentifier)[0];
                $cleanId = explode('@', $cleanId)[0];
                $digitsOnly = preg_replace('/[^0-9]/', '', $cleanId);
                
                // Try searching by phone first if numeric
                if (!empty($digitsOnly) && strlen($digitsOnly) >= 7) {
                    $contact = Contact::where('tenant_id', $connection->tenant_id)
                        ->where(function ($q) use ($digitsOnly) {
                            $q->where('phone', 'like', "%{$digitsOnly}%")
                              ->orWhere('phone', "+{$digitsOnly}")
                              ->orWhere('phone', $digitsOnly);
                        })
                        ->first();
                } elseif (str_contains($externalChatId, '@')) {
                    // Try searching by email
                    $contact = Contact::where('tenant_id', $connection->tenant_id)
                        ->where('email', trim($externalChatId))
                        ->first();
                }

                // If not found, provision a new Contact auto-created
                if (!$contact) {
                    $pushName = $inbound['sender_identifier'] ?? null;
                    $phoneFormatted = !empty($digitsOnly) ? (str_starts_with($cleanId, '+') ? "+{$digitsOnly}" : "+{$digitsOnly}") : null;
                    $firstName = $pushName ?: ($phoneFormatted ?: 'New Contact');

                    $contact = Contact::create([
                        'tenant_id' => $connection->tenant_id,
                        'first_name' => $firstName,
                        'last_name' => '',
                        'phone' => $phoneFormatted,
                        'email' => str_contains($externalChatId, '@') ? $externalChatId : null,
                        'lifecycle_stage' => 'lead',
                    ]);
                }

                $conversation->update(['contact_id' => $contact->id]);
            }

            // Detect opt-out / opt-in keywords
            $bodyText = trim($inbound['body'] ?? '');
            $optOutKeywords = ['STOP', 'QUIT', 'UNSUBSCRIBE', 'CANCEL', 'END'];
            $optInKeywords = ['START', 'UNSTOP', 'SUBSCRIBE'];
            
            $contact = Contact::find($conversation->contact_id);
            if ($contact && !empty($bodyText)) {
                $currentOptedOut = $contact->opted_out_channels ?? [];
                $upperBody = strtoupper($bodyText);
                
                if (in_array($upperBody, $optOutKeywords)) {
                    if (!in_array($connection->channel_type, $currentOptedOut)) {
                        $currentOptedOut[] = $connection->channel_type;
                        $contact->update(['opted_out_channels' => $currentOptedOut]);
                        Log::info("Contact ID {$contact->id} opted out of channel type {$connection->channel_type} via keyword {$upperBody}");
                    }
                    $conversation->update(['ai_active' => false]);
                } elseif (in_array($upperBody, $optInKeywords)) {
                    if (in_array($connection->channel_type, $currentOptedOut)) {
                        $currentOptedOut = array_values(array_diff($currentOptedOut, [$connection->channel_type]));
                        $contact->update(['opted_out_channels' => $currentOptedOut]);
                        Log::info("Contact ID {$contact->id} opted back into channel type {$connection->channel_type} via keyword {$upperBody}");
                    }
                }
            }

            // Check if this message was already received to prevent duplicate writes
            if ($externalMsgId && Message::where('conversation_id', $conversation->id)->where('external_message_id', $externalMsgId)->exists()) {
                return;
            }

            // Create Message
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'direction' => 'inbound',
                'message_type' => $inbound['message_type'] ?? 'text',
                'sender_identifier' => $inbound['sender_identifier'] ?? $externalChatId,
                'body' => $inbound['body'] ?? '',
                'media_url' => $inbound['media_url'] ?? null,
                'external_message_id' => $externalMsgId,
                'delivery_status' => 'delivered',
            ]);

            $conversation->update(['last_message_at' => Carbon::now()]);

            // Dispatch Broadcast event
            try {
                broadcast(new \App\Events\MessageReceived($message))->toOthers();
            } catch (Exception $e) {
                Log::debug("Broadcasting MessageReceived skipped: " . $e->getMessage());
            }

            // Trigger or resume AI Agent / Flow
            $this->triggerOrResumeFlowAndAgent($message, $conversation, $connection, $contact);

        } catch (Exception $e) {
            Log::error("Error processing inbound webhook message: " . $e->getMessage());
        }
    }

    protected function triggerOrResumeFlowAndAgent(Message $message, Conversation $conversation, ChannelConnection $connection, Contact $contact): void
    {
        try {
            // CRITICAL CONTROL: If conversation is assigned to human agent, halt automated bot executions
            if (!empty($conversation->assigned_user_id)) {
                Log::info("ProcessInboundMessageJob: Conversation {$conversation->id} is assigned to human agent. Halting automated execution.");
                return;
            }

            $flowRunner = app(\App\Services\Flow\FlowRunner::class);

            $execution = \App\Models\FlowExecution::where('contact_id', $contact->id)
                ->where('tenant_id', $connection->tenant_id)
                ->where('status', 'paused_waiting_reply')
                ->first();

            // 0. Check if incoming message matches any node's Keyword Triggers in an existing or active flow
            if ($execution && $execution->flowVersion) {
                $keywordNode = $flowRunner->findMatchingKeywordNode($message->body, $execution->flowVersion->definition);
                if ($keywordNode) {
                    Log::info("ProcessInboundMessageJob: Keyword Trigger matched in active execution! Jumping to node {$keywordNode['id']}");
                    $execution->conversation_id = $conversation->id;
                    $execution->status = 'running';
                    $execution->current_node_id = $keywordNode['id'];
                    $context = $execution->context;
                    $context['variables']['inbound_message_body'] = $message->body;
                    $execution->context = $context;
                    $execution->save();

                    $flowRunner->execute($execution);
                    return;
                }
            }

            // 1. Resume waiting flow execution if it exists (Priority 1)
            if ($execution) {
                Log::info("ProcessInboundMessageJob: Resuming FlowExecution {$execution->id} for contact {$contact->id} at node {$execution->current_node_id}");
                
                $execution->conversation_id = $conversation->id;
                $execution->status = 'running';
                $context = $execution->context;
                $saveVar = $context['variables']['current_save_variable'] ?? null;
                if ($saveVar) {
                    $context['variables'][$saveVar] = $message->body;
                }
                $context['variables']['inbound_message_body'] = $message->body;
                $execution->context = $context;
                $execution->save();

                // Run the executor loop directly
                $flowRunner->execute($execution);
                return;
            }

            // Fetch active flows for this tenant
            $inboundText = trim(strtolower($message->body ?? ''));
            $activeFlows = \App\Models\Flow::where('tenant_id', $connection->tenant_id)
                ->where('is_active', true)
                ->whereNotNull('current_published_version_id')
                ->with('channels')
                ->where(function ($q) use ($connection) {
                    $q->whereHas('channels', function ($qc) use ($connection) {
                        $qc->where('channel_connections.id', $connection->id);
                    })->orDoesntHave('channels');
                })
                ->get();

            // 2. PRIORITY 2: Check for active Flow with keyword matching attached to this channel
            foreach ($activeFlows as $candidateFlow) {
                $keywords = $candidateFlow->trigger_keywords;
                if (!empty($keywords) && is_array($keywords)) {
                    foreach ($keywords as $kw) {
                        $kwClean = trim(strtolower($kw));
                        if (!empty($kwClean) && (
                            $inboundText === $kwClean || 
                            preg_match('/\b' . preg_quote($kwClean, '/') . '\b/i', $inboundText)
                        )) {
                            $version = \App\Models\FlowVersion::find($candidateFlow->current_published_version_id);
                            if ($version) {
                                $triggerNode = null;
                                foreach ($version->definition['nodes'] ?? [] as $node) {
                                    if (in_array($node['type'] ?? '', ['inbound_message', 'keyword']) || ($node['id'] ?? '') === 'trigger_1') {
                                        $triggerNode = $node;
                                        break;
                                    }
                                }
                                if ($triggerNode) {
                                    Log::info("ProcessInboundMessageJob: Keyword matched ('{$kwClean}') for Flow {$candidateFlow->id}, starting execution for contact {$contact->id}");
                                    $execution = \App\Models\FlowExecution::create([
                                        'tenant_id' => $connection->tenant_id,
                                        'flow_version_id' => $version->id,
                                        'contact_id' => $contact->id,
                                        'conversation_id' => $conversation->id,
                                        'status' => 'running',
                                        'current_node_id' => $triggerNode['id'],
                                        'context' => [
                                            'variables' => [
                                                'inbound_message_body' => $message->body,
                                                'matched_keyword' => $kwClean,
                                            ],
                                            'loop_count' => []
                                        ]
                                    ]);

                                    $flowRunner->execute($execution);
                                    return;
                                }
                            }
                        }
                    }
                }
            }

            // 3. PRIORITY 3: Active Channel-Bound Flow with trigger_type = 'inbound_message'
            $inboundFlow = $activeFlows->first(function ($f) use ($connection) {
                return $f->trigger_type === 'inbound_message' && $f->channels->contains('id', $connection->id);
            });
            if (!$inboundFlow) {
                $inboundFlow = $activeFlows->first(function ($f) {
                    return $f->trigger_type === 'inbound_message' && $f->channels->isEmpty();
                });
            }

            if ($inboundFlow && $inboundFlow->current_published_version_id) {
                $version = \App\Models\FlowVersion::find($inboundFlow->current_published_version_id);
                if ($version) {
                    $triggerNode = null;
                    foreach ($version->definition['nodes'] ?? [] as $node) {
                        if (($node['type'] ?? '') === 'inbound_message' || ($node['id'] ?? '') === 'trigger_1') {
                            $triggerNode = $node;
                            break;
                        }
                    }
                    if ($triggerNode) {
                        Log::info("ProcessInboundMessageJob: Inbound message trigger for Flow {$inboundFlow->id}, starting execution for contact {$contact->id}");
                        $execution = \App\Models\FlowExecution::create([
                            'tenant_id' => $connection->tenant_id,
                            'flow_version_id' => $version->id,
                            'contact_id' => $contact->id,
                            'conversation_id' => $conversation->id,
                            'status' => 'running',
                            'current_node_id' => $triggerNode['id'],
                            'context' => [
                                'variables' => [
                                    'inbound_message_body' => $message->body,
                                ],
                                'loop_count' => []
                            ]
                        ]);

                        $flowRunner->execute($execution);
                        return;
                    }
                }
            }

            // 4. PRIORITY 4: Check if an active AI ChatBot is bound to this channel
            $chatbot = \App\Models\AiChatbot::where('tenant_id', $connection->tenant_id)
                ->where('status', 'active')
                ->whereHas('channels', function ($q) use ($connection) {
                    $q->where('channel_connections.id', $connection->id);
                })
                ->first();

            if ($chatbot) {
                if (empty($conversation->assigned_user_id) && !$conversation->ai_active) {
                    $conversation->update(['ai_active' => true]);
                    $conversation->refresh();
                }

                if ($conversation->ai_active) {
                    Log::info("ProcessInboundMessageJob: Executing Direct AI ChatBot {$chatbot->id} for contact {$contact->id}");
                    $this->handleDirectChatbotResponse($chatbot, $message, $conversation, $connection, $contact);
                    return;
                }
            }

            // 5. PRIORITY 5: Fallback check for legacy active AI Agent bound to this channel
            $agent = \App\Models\AiAgent::where('tenant_id', $connection->tenant_id)
                ->where('status', 'active')
                ->whereHas('channels', function ($q) use ($connection) {
                    $q->where('channel_connections.id', $connection->id);
                })
                ->first();

            if (!$agent) {
                return;
            }

            // Update conversation to map this agent without overwriting ai_active
            if (empty($conversation->ai_agent_id)) {
                $conversation->update([
                    'ai_agent_id' => $agent->id,
                ]);
            }

            // Check if this agent has an attached flow
            if ($agent->flow_id) {
                $flow = \App\Models\Flow::where('tenant_id', $connection->tenant_id)
                    ->where('is_active', true)
                    ->find($agent->flow_id);

                if ($flow && $flow->current_published_version_id) {
                    $version = \App\Models\FlowVersion::find($flow->current_published_version_id);
                    if ($version) {
                        $definition = $version->definition;
                        $triggerNode = null;
                        foreach ($definition['nodes'] ?? [] as $node) {
                            if ($node['type'] === 'inbound_message') {
                                $triggerNode = $node;
                                break;
                            }
                        }

                        if ($triggerNode) {
                            Log::info("ProcessInboundMessageJob: Triggering Flow {$flow->id} via Agent {$agent->id} for contact {$contact->id}");
                            
                            $execution = \App\Models\FlowExecution::create([
                                'tenant_id' => $connection->tenant_id,
                                'flow_version_id' => $version->id,
                                'contact_id' => $contact->id,
                                'conversation_id' => $conversation->id,
                                'status' => 'running',
                                'current_node_id' => $triggerNode['id'],
                                'context' => [
                                    'variables' => [
                                        'inbound_message_body' => $message->body,
                                    ],
                                    'loop_count' => []
                                ]
                            ]);

                            $flowRunner->execute($execution);
                            return;
                        }
                    }
                }
            }

            // Direct AI Agent fallback
            if ($conversation->ai_active) {
                Log::info("ProcessInboundMessageJob: Direct AI Agent {$agent->id} response for contact {$contact->id}");
                $this->handleDirectAgentResponse($agent, $message, $conversation, $connection, $contact);
            }

        } catch (Exception $e) {
            Log::error("ProcessInboundMessageJob Flow/Agent check error: " . $e->getMessage());
        }
    }

    protected function handleDirectChatbotResponse(
        \App\Models\AiChatbot $chatbot, 
        Message $message, 
        Conversation $conversation, 
        ChannelConnection $connection, 
        Contact $contact
    ): void {
        $startTime = microtime(true);
        $aiService = app(\App\Services\AIProviderService::class);
        $embeddingService = app(\App\Services\EmbeddingService::class);
        
        $resolvedAI = $aiService->resolveAIExecution(
            $connection->tenant_id,
            'ai_chatbot',
            $chatbot->providerConfig?->provider_name ?: $chatbot->provider,
            $chatbot->model,
            (string) ($contact->phone_number ?: $contact->id)
        );

        if (!empty($resolvedAI['is_blocked'])) {
            $fallback = $resolvedAI['fallback_message'] ?: ($chatbot->fallback_message ?: 'AI assistant is temporarily unavailable. An agent will follow up shortly.');
            $this->sendOutboundDirect($conversation, $connection, $fallback);
            return;
        }

        $provider = $resolvedAI['provider'] ?: ($chatbot->provider ?: 'groq');
        $apiKey = $resolvedAI['api_key'];
        $model = $resolvedAI['model'] ?: ($chatbot->model ?: 'llama-3.3-70b-versatile');
        if (empty($model) || str_contains($model, 'mixtral')) {
            $model = 'llama-3.3-70b-versatile';
        }

        // A. Run similarity search matching on knowledge base (RAG)
        $ragContext = '';
        $matchedSources = [];
        if ($chatbot->knowledge_base_id) {
            try {
                $queryVector = $embeddingService->getEmbedding($connection->tenant_id, $message->body);
                $chunks = \App\Models\KnowledgeChunk::whereHas('source', function ($q) use ($chatbot) {
                    $q->where('knowledge_base_id', $chatbot->knowledge_base_id)->where('status', 'indexed');
                })->get();

                $matches = [];
                foreach ($chunks as $chunk) {
                    $vectorSim = $embeddingService->cosineSimilarity($queryVector, $chunk->embedding);
                    $textSim = $embeddingService->textSimilarity($message->body, $chunk->content);
                    $similarity = max($vectorSim, $textSim);
                    if ($similarity >= 0.05) {
                        $matches[] = [
                            'chunk_id' => $chunk->id,
                            'content' => $chunk->content,
                            'similarity' => $similarity
                        ];
                    }
                }

                if (empty($matches) && count($chunks) > 0) {
                    foreach ($chunks as $chunk) {
                        $matches[] = [
                            'chunk_id' => $chunk->id,
                            'content' => $chunk->content,
                            'similarity' => 0.1
                        ];
                    }
                }

                usort($matches, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
                $topMatches = array_slice($matches, 0, 4);

                if (!empty($topMatches)) {
                    $ragContext = "KNOWLEDGE BASE CONTEXT:\n";
                    foreach ($topMatches as $match) {
                        $ragContext .= "- " . $match['content'] . "\n";
                        $matchedSources[] = $match['chunk_id'];
                    }
                    $ragContext .= "\nUse the above context to answer the user's question accurately. If you don't know the answer, say you don't know.";
                }
            } catch (Exception $e) {
                Log::error("Direct Chatbot response RAG search error: " . $e->getMessage());
            }
        }

        // B. Check Business Hours
        if (!empty($chatbot->business_hours)) {
            $nowDay = strtolower(now()->format('l'));
            $nowTime = now()->format('H:i');
            $dayConfig = $chatbot->business_hours[$nowDay] ?? null;
            if ($dayConfig && isset($dayConfig['is_open']) && !$dayConfig['is_open']) {
                if (!empty($chatbot->fallback_message)) {
                    $this->sendOutboundDirect($conversation, $connection, $chatbot->fallback_message);
                    return;
                }
            }
        }

        // C. Fetch conversation history
        $historyMessages = Message::where('conversation_id', $conversation->id)
            ->where('id', '!=', $message->id)
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get()
            ->reverse();

        $systemPrompt = $chatbot->system_prompt ?: "You are a helpful customer service AI assistant.";
        if (!empty($ragContext)) {
            $systemPrompt .= "\n\n" . $ragContext;
        }

        $formattedMessages = [
            ['role' => 'system', 'content' => $systemPrompt]
        ];

        foreach ($historyMessages as $histMsg) {
            $role = ($histMsg->direction === 'inbound') ? 'user' : 'assistant';
            $formattedMessages[] = [
                'role' => $role,
                'content' => $histMsg->body ?: '[Empty]'
            ];
        }

        $formattedMessages[] = [
            'role' => 'user',
            'content' => $message->body
        ];

        // D. Call LLM
        try {
            $responseBody = $aiService->generateCompletion(
                $provider,
                $apiKey,
                $model,
                $formattedMessages,
                (float) ($chatbot->temperature ?: 0.70),
                'ai_chatbot',
                $connection->tenant_id
            );

            $elapsedMs = (int) round((microtime(true) - $startTime) * 1000);
            $reqTokens = (int) (strlen(json_encode($formattedMessages)) / 4);
            $resTokens = (int) (strlen($responseBody) / 4);

            // Log AI Chatbot turn
            \App\Models\AiChatbotLog::create([
                'ai_chatbot_id' => $chatbot->id,
                'conversation_id' => $conversation->id,
                'channel_type' => $connection->channel_type,
                'inbound_text' => $message->body,
                'outbound_text' => $responseBody,
                'rag_sources' => $matchedSources,
                'tokens_used' => $reqTokens + $resTokens,
                'latency_ms' => $elapsedMs,
                'status' => 'success',
            ]);

            // E. Send response back
            $this->sendOutboundDirect($conversation, $connection, $responseBody);

        } catch (Exception $e) {
            Log::error("Direct AI ChatBot completion execution failed: " . $e->getMessage());
            \App\Models\AiChatbotLog::create([
                'ai_chatbot_id' => $chatbot->id,
                'conversation_id' => $conversation->id,
                'channel_type' => $connection->channel_type,
                'inbound_text' => $message->body,
                'outbound_text' => null,
                'rag_sources' => $matchedSources,
                'tokens_used' => 0,
                'latency_ms' => (int) round((microtime(true) - $startTime) * 1000),
                'status' => 'error',
                'error_details' => $e->getMessage(),
            ]);
        }
    }

    protected function handleDirectAgentResponse(
        \App\Models\AiAgent $agent, 
        Message $message, 
        Conversation $conversation, 
        ChannelConnection $connection, 
        Contact $contact
    ): void {
        $startTime = microtime(true);
        $aiService = app(\App\Services\AIProviderService::class);
        $embeddingService = app(\App\Services\EmbeddingService::class);
        
        $resolvedAI = $aiService->resolveAIExecution(
            $connection->tenant_id,
            'ai_agents',
            $agent->providerConfig?->provider_name,
            $agent->model,
            (string) ($contact->phone_number ?: $contact->id)
        );

        if (!empty($resolvedAI['is_blocked'])) {
            $fallback = $resolvedAI['fallback_message'] ?: ($agent->fallback_message ?: 'AI agent is temporarily unavailable. An operator will follow up shortly.');
            $this->sendOutboundDirect($conversation, $connection, $fallback);
            return;
        }

        $provider = $resolvedAI['provider'];
        $apiKey = $resolvedAI['api_key'];
        $model = $resolvedAI['model'];
        if (empty($model) || str_contains($model, 'mixtral')) {
            $model = 'llama-3.3-70b-versatile';
        }

        // A. Run similarity search matching on knowledge base (RAG)
        $ragContext = '';
        if ($agent->knowledge_base_id) {
            try {
                $queryVector = $embeddingService->getEmbedding($connection->tenant_id, $message->body);
                $chunks = \App\Models\KnowledgeChunk::whereHas('source', function ($q) use ($agent) {
                    $q->where('knowledge_base_id', $agent->knowledge_base_id)->where('status', 'indexed');
                })->get();

                $matches = [];
                foreach ($chunks as $chunk) {
                    $vectorSim = $embeddingService->cosineSimilarity($queryVector, $chunk->embedding);
                    $textSim = $embeddingService->textSimilarity($message->body, $chunk->content);
                    $similarity = max($vectorSim, $textSim);
                    if ($similarity >= 0.05) { // Similarity threshold
                        $matches[] = [
                            'content' => $chunk->content,
                            'similarity' => $similarity
                        ];
                    }
                }

                if (empty($matches) && count($chunks) > 0) {
                    foreach ($chunks as $chunk) {
                        $matches[] = [
                            'content' => $chunk->content,
                            'similarity' => 0.1
                        ];
                    }
                }

                usort($matches, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
                $topMatches = array_slice($matches, 0, 4);

                if (!empty($topMatches)) {
                    $ragContext = "KNOWLEDGE BASE CONTEXT:\n";
                    foreach ($topMatches as $match) {
                        $ragContext .= "- " . $match['content'] . "\n";
                    }
                    $ragContext .= "\nUse the above context to answer the user's question accurately. If you don't know the answer, say you don't know.";
                }
            } catch (Exception $e) {
                Log::error("Direct Agent response RAG search error: " . $e->getMessage());
            }
        }

        // B. Handoff rules check
        $inboundBody = trim($message->body);
        $handoffKeywords = $agent->handoff_rules['keywords'] ?? ['human', 'talk to agent', 'support', 'operator'];
        $shouldHandoff = false;
        foreach ($handoffKeywords as $keyword) {
            if (stripos($inboundBody, trim($keyword)) !== false) {
                $shouldHandoff = true;
                break;
            }
        }

        if ($shouldHandoff) {
            $conversation->update([
                'ai_active' => false,
                'status' => 'open'
            ]);

            // Add internal note and stop
            Message::create([
                'conversation_id' => $conversation->id,
                'tenant_id' => $conversation->tenant_id,
                'direction' => 'outbound',
                'message_type' => 'note',
                'sender_identifier' => 'System AI Agent',
                'body' => 'Customer requested human assistance. Handing over conversation to human operators.',
                'delivery_status' => 'read',
            ]);

            try {
                broadcast(new \App\Events\MessageReceived($conversation->messages()->latest()->first()))->toOthers();
            } catch (Exception $e) {}
            return;
        }

        // C. Business hours check
        $isOutsideHours = false;
        if (!empty($agent->business_hours) && ($agent->business_hours['enabled'] ?? false)) {
            try {
                $timezone = $agent->business_hours['timezone'] ?? 'UTC';
                $nowTime = now()->setTimezone($timezone);
                $dayOfWeek = strtolower($nowTime->format('l'));
                
                $hours = $agent->business_hours['days'][$dayOfWeek] ?? null;
                if ($hours && isset($hours['open'], $hours['close'])) {
                    $currentTimeStr = $nowTime->format('H:i');
                    if ($currentTimeStr < $hours['open'] || $currentTimeStr > $hours['close']) {
                        $isOutsideHours = true;
                    }
                } else {
                    $isOutsideHours = true;
                }
            } catch (Exception $e) {
                Log::error("Business hours evaluation failed: " . $e->getMessage());
            }
        }

        if ($isOutsideHours && !empty($agent->fallback_message)) {
            $this->sendOutboundDirect($conversation, $connection, $agent->fallback_message);
            return;
        }

        // D. Build completion prompt messages
        $systemPrompt = $agent->system_prompt ?: "You are a helpful automated virtual assistant.";
        if (!empty($ragContext)) {
            $systemPrompt .= "\n\n" . $ragContext;
        }

        // Get conversation history (last 10 messages)
        $history = $conversation->messages()
            ->where('message_type', 'text')
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get()
            ->reverse();

        $messages = [];
        $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        foreach ($history as $historyMsg) {
            $messages[] = [
                'role' => $historyMsg->direction === 'inbound' ? 'user' : 'assistant',
                'content' => $historyMsg->body
            ];
        }

        // Generate response with e-commerce support tools
        try {
            $tools = [
                [
                    'name' => 'check_product_availability',
                    'description' => 'Checks the active inventory count for a specific item in the store catalog.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'product_id' => [
                                'type' => 'string',
                                'description' => 'Shopify or WooCommerce product database primary key.'
                            ]
                        ],
                        'required' => ['product_id']
                    ]
                ],
                [
                    'name' => 'check_order_status',
                    'description' => 'Retrieves order status, shipping details, and tracking updates for a customer.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'email' => [
                                'type' => 'string',
                                'description' => 'Customer purchase email address.'
                            ],
                            'order_number' => [
                                'type' => 'string',
                                'description' => 'E-commerce order code.'
                            ]
                        ],
                        'required' => ['email', 'order_number']
                    ]
                ]
            ];

            $loopCount = 0;
            $responseBody = '';
            $toolCallsFound = false;

            while ($loopCount < 3) {
                $loopCount++;
                $res = $aiService->generateCompletionWithTools($provider, $apiKey, $model, $messages, $tools, 0.7, 'agent', $connection->tenant_id);

                if (!empty($res['tool_calls'])) {
                    $toolCallsFound = true;
                    // Format system/assistant tool calls messages
                    $messages[] = [
                        'role' => 'assistant',
                        'content' => $res['content'] ?? null,
                        'tool_calls' => $res['tool_calls']
                    ];

                    foreach ($res['tool_calls'] as $tc) {
                        $toolName = $tc['name'];
                        $args = $tc['arguments'] ?? [];

                        $resultText = '';
                        if ($toolName === 'check_product_availability') {
                            $productId = $args['product_id'] ?? '';
                            $resultText = $this->executeProductAvailabilityTool($connection->tenant_id, $productId);
                        } elseif ($toolName === 'check_order_status') {
                            $email = $args['email'] ?? '';
                            $orderNumber = $args['order_number'] ?? '';
                            $resultText = $this->executeOrderStatusTool($connection->tenant_id, $email, $orderNumber);
                        }

                        $messages[] = [
                            'role' => 'tool',
                            'tool_call_id' => $tc['id'] ?? '',
                            'name' => $toolName,
                            'content' => $resultText
                        ];
                    }
                } else {
                    $responseBody = $res['content'] ?? 'Hello! How can I assist you with our catalog or your orders today?';
                    break;
                }
            }

            $elapsedMs = round((microtime(true) - $startTime) * 1000);

            // Estimate tokens
            $promptChars = strlen(json_encode($messages));
            $responseChars = strlen($responseBody);
            $reqTokens = round($promptChars / 4);
            $resTokens = round($responseChars / 4);

            $multiplierInput = 0.00000015;
            $multiplierOutput = 0.00000060;
            if (str_contains($model, 'gpt-4o') && !str_contains($model, 'mini')) {
                $multiplierInput = 0.0000025;
                $multiplierOutput = 0.000010;
            } elseif (str_contains($model, 'claude-3-5-sonnet')) {
                $multiplierInput = 0.0000030;
                $multiplierOutput = 0.000015;
            }
            $estimatedCost = ($reqTokens * $multiplierInput) + ($resTokens * $multiplierOutput);

            // Log AI turn
            \App\Models\AiAgentLog::create([
                'ai_agent_id' => $agent->id,
                'conversation_id' => $conversation->id,
                'request_tokens' => $reqTokens,
                'response_tokens' => $resTokens,
                'estimated_cost' => $estimatedCost,
                'model_used' => $model,
                'latency_ms' => $elapsedMs,
            ]);

            // E. Send response back
            $this->sendOutboundDirect($conversation, $connection, $responseBody);

        } catch (Exception $e) {
            Log::error("Direct AI agent completion execution failed: " . $e->getMessage());
        }
    }

    protected function executeProductAvailabilityTool(int $tenantId, string $productId): string
    {
        // Try database similarity match on vector catalog chunks first
        try {
            $chunk = \App\Models\KnowledgeChunk::whereHas('source', function ($q) use ($tenantId) {
                $q->where('source_type', 'ecommerce_catalog')
                  ->whereHas('knowledgeBase', function ($qb) use ($tenantId) {
                      $qb->where('tenant_id', $tenantId);
                  });
            })->where('content', 'LIKE', "%Product ID: {$productId}%")->first();

            if ($chunk) {
                return $chunk->content;
            }
        } catch (Exception $ex) {
            Log::warning("Product Availability vector retrieval failed: " . $ex->getMessage());
        }

        // Fallback to static mock products mapping if not vectorized yet
        $productIdClean = strtolower(trim($productId));
        $mocks = [
            'shopify_prod_1' => "Premium Leather Jacket - 15 items in stock. Price: $199.99. Status: In Stock",
            'shopify_prod_2' => "Wireless Noise-Canceling Headphones - 42 items in stock. Price: $149.50. Status: In Stock",
            'shopify_prod_3' => "Minimalist Quartz Watch - 8 items in stock. Price: $89.00. Status: Low Stock",
            'shopify_prod_4' => "Ergonomic Office Chair - 20 items in stock. Price: $249.00. Status: In Stock",
            'shopify_prod_5' => "Portable Bluetooth Speaker - Out of Stock. Price: $45.00. Status: Out of Stock",
        ];

        return $mocks[$productIdClean] ?? "Product ID '{$productId}' was not found in our catalog inventory database.";
    }

    protected function executeOrderStatusTool(int $tenantId, string $email, string $orderNumber): string
    {
        try {
            $cleanOrderNum = ltrim($orderNumber, '#');
            $order = \App\Models\EcommerceOrder::where('tenant_id', $tenantId)
                ->where(function ($q) use ($orderNumber, $cleanOrderNum) {
                    $q->where('order_number', $orderNumber)
                      ->orWhere('order_number', $cleanOrderNum)
                      ->orWhere('order_number', '#' . $cleanOrderNum);
                })
                ->where('customer_email', $email)
                ->first();

            if ($order) {
                return "Order details:\n" .
                       "- Order Number: {$order->order_number}\n" .
                       "- Total Price: {$order->total_price}\n" .
                       "- Financial Status: {$order->financial_status}\n" .
                       "- Shipping Fulfillment Status: {$order->fulfillment_status}\n" .
                       "- Courier tracking: " . ($order->tracking_number ?: 'Not shipped yet') . "\n" .
                       "- Courier URL: " . ($order->tracking_url ?: 'N/A');
            }
        } catch (Exception $ex) {
            Log::warning("Order status database query failed: " . $ex->getMessage());
        }

        // Fallback mock order response
        return "Order details (Mock Sandbox):\n" .
               "- Order Number: {$orderNumber}\n" .
               "- Customer: {$email}\n" .
               "- Shipping Fulfillment Status: shipped\n" .
               "- Financial Status: paid\n" .
               "- Courier tracking: FX-129482 (FedEx)\n" .
               "- Courier URL: https://www.fedex.com/tracking?num=FX-129482";
    }

    protected function sendOutboundDirect(Conversation $conversation, ChannelConnection $connection, string $body): void
    {
        $channelManager = app(\App\Services\Channels\ChannelManager::class);
        $credentials = $connection->decrypted_credentials;
        $driver = $channelManager->driver($connection->channel_type);

        $res = $driver->sendMessage($credentials, [
            'connection_id' => $connection->id,
            'external_chat_id' => $conversation->external_chat_id,
            'to' => $conversation->external_chat_id,
            'body' => $body,
            'media_url' => '',
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'tenant_id' => $conversation->tenant_id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'sender_identifier' => 'AI Agent',
            'body' => $body,
            'media_url' => '',
            'provider_message_id' => $res['external_message_id'] ?? 'agent_' . uniqid(),
            'delivery_status' => 'sent',
        ]);

        $conversation->update(['last_message_at' => Carbon::now()]);

        try {
            broadcast(new \App\Events\MessageReceived($message))->toOthers();
        } catch (Exception $e) {}
    }
}
