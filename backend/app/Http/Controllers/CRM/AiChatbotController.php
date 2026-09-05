<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\AiChatbot;
use App\Models\AiChatbotLog;
use App\Models\AIProviderConfig;
use App\Models\ChannelConnection;
use App\Models\KnowledgeBase;
use App\Models\PlatformSetting;
use App\Services\AIProviderService;
use App\Services\RAGService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class AiChatbotController extends Controller
{
    /**
     * List all chatbots for the tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $chatbots = AiChatbot::where('tenant_id', $tenantId)
            ->with(['channels', 'knowledgeBase', 'providerConfig', 'creator'])
            ->orderBy('id', 'desc')
            ->get();

        return response()->json($chatbots);
    }

    /**
     * Get available AI providers and allowed models for the tenant.
     */
    public function providerConfigs(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $aiService = app(AIProviderService::class);
        $operationalModel = $aiService->getAiOperationalModel();

        $configs = AIProviderConfig::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get();

        // Master Fixed defaults
        $platformDefault = [
            'id' => null,
            'provider_name' => 'groq',
            'model' => 'llama-3.3-70b-versatile',
            'is_default' => true,
            'operational_model' => $operationalModel,
        ];

        // Available models list by provider
        $modelsByProvider = [
            'groq' => [
                ['id' => 'llama-3.3-70b-versatile', 'name' => 'Llama 3.3 70B Versatile (Ultra Fast)'],
                ['id' => 'llama-3.1-8b-instant', 'name' => 'Llama 3.1 8B Instant'],
                ['id' => 'mixtral-8x7b-32768', 'name' => 'Mixtral 8x7B (32k Context)'],
            ],
            'openai' => [
                ['id' => 'gpt-4o-mini', 'name' => 'GPT-4o Mini (Fast & Affordable)'],
                ['id' => 'gpt-4o', 'name' => 'GPT-4o (Omni Flagship)'],
                ['id' => 'gpt-4-turbo', 'name' => 'GPT-4 Turbo'],
            ],
            'anthropic' => [
                ['id' => 'claude-3-5-sonnet-20241022', 'name' => 'Claude 3.5 Sonnet (Best Reasoning)'],
                ['id' => 'claude-3-haiku-20240307', 'name' => 'Claude 3 Haiku (Lightweight)'],
            ],
            'gemini' => [
                ['id' => 'gemini-1.5-flash', 'name' => 'Gemini 1.5 Flash (Low Latency)'],
                ['id' => 'gemini-1.5-pro', 'name' => 'Gemini 1.5 Pro (Large Context)'],
            ],
            'deepseek' => [
                ['id' => 'deepseek-chat', 'name' => 'DeepSeek V3 (Chat)'],
                ['id' => 'deepseek-reasoner', 'name' => 'DeepSeek R1 (Reasoning)'],
            ]
        ];

        return response()->json([
            'operational_model' => $operationalModel,
            'platform_default' => $platformDefault,
            'configs' => $configs,
            'models_by_provider' => $modelsByProvider,
        ]);
    }

    /**
     * Create a new AI ChatBot.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $request->validate([
            'name' => 'required|string|max:255',
            'avatar' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'system_prompt' => 'required|string|max:10000',
            'temperature' => 'nullable|numeric|min:0|max:1',
            'ai_provider_config_id' => 'nullable|integer',
            'provider' => 'nullable|string|max:50',
            'model' => 'nullable|string|max:100',
            'knowledge_base_id' => 'nullable|integer',
            'status' => 'nullable|in:active,inactive',
            'business_hours' => 'nullable|array',
            'fallback_message' => 'nullable|string|max:1000',
            'handoff_rules' => 'nullable|array',
            'channel_ids' => 'nullable|array',
            'channel_ids.*' => 'integer',
        ]);

        $status = $request->input('status', 'active');
        $channelIds = $request->input('channel_ids', []);

        // Validate channel conflicts if creating active chatbot
        if ($status === 'active' && !empty($channelIds)) {
            $conflictBot = AiChatbot::where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->whereHas('channels', function ($q) use ($channelIds) {
                    $q->whereIn('channel_connections.id', $channelIds);
                })
                ->with('channels')
                ->first();

            if ($conflictBot) {
                return response()->json([
                    'message' => "Another active AI ChatBot ('{$conflictBot->name}') is already connected to one or more of the selected channels.",
                ], 422);
            }
        }

        DB::beginTransaction();
        try {
            $chatbot = AiChatbot::create([
                'tenant_id' => $tenantId,
                'name' => trim($request->input('name')),
                'avatar' => $request->input('avatar'),
                'description' => trim($request->input('description', '')),
                'system_prompt' => trim($request->input('system_prompt')),
                'temperature' => $request->input('temperature', 0.70),
                'ai_provider_config_id' => $request->input('ai_provider_config_id'),
                'provider' => $request->input('provider', 'groq'),
                'model' => $request->input('model', 'llama-3.3-70b-versatile'),
                'knowledge_base_id' => $request->input('knowledge_base_id'),
                'status' => $status,
                'business_hours' => $request->input('business_hours'),
                'fallback_message' => $request->input('fallback_message'),
                'handoff_rules' => $request->input('handoff_rules'),
                'created_by' => $request->user()->id,
            ]);

            if (!empty($channelIds)) {
                $chatbot->channels()->sync($channelIds);
            }

            DB::commit();

            return response()->json($chatbot->load(['channels', 'knowledgeBase', 'providerConfig', 'creator']), 201);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("AiChatbotController@store error: " . $e->getMessage());
            return response()->json(['message' => 'Failed to create AI ChatBot: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Show single AI ChatBot.
     */
    public function show(Request $request, $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $chatbot = AiChatbot::where('tenant_id', $tenantId)
            ->with(['channels', 'knowledgeBase', 'providerConfig', 'creator'])
            ->findOrFail($id);

        return response()->json($chatbot);
    }

    /**
     * Update an AI ChatBot.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $chatbot = AiChatbot::where('tenant_id', $tenantId)->findOrFail($id);

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'avatar' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'system_prompt' => 'sometimes|required|string|max:10000',
            'temperature' => 'nullable|numeric|min:0|max:1',
            'ai_provider_config_id' => 'nullable|integer',
            'provider' => 'nullable|string|max:50',
            'model' => 'nullable|string|max:100',
            'knowledge_base_id' => 'nullable|integer',
            'status' => 'nullable|in:active,inactive',
            'business_hours' => 'nullable|array',
            'fallback_message' => 'nullable|string|max:1000',
            'handoff_rules' => 'nullable|array',
            'channel_ids' => 'nullable|array',
            'channel_ids.*' => 'integer',
        ]);

        $status = $request->input('status', $chatbot->status);
        $channelIds = $request->has('channel_ids') ? $request->input('channel_ids', []) : null;

        // Check channel conflicts if setting to active with channels
        if ($status === 'active' && $channelIds !== null && !empty($channelIds)) {
            $conflictBot = AiChatbot::where('tenant_id', $tenantId)
                ->where('id', '!=', $chatbot->id)
                ->where('status', 'active')
                ->whereHas('channels', function ($q) use ($channelIds) {
                    $q->whereIn('channel_connections.id', $channelIds);
                })
                ->with('channels')
                ->first();

            if ($conflictBot) {
                return response()->json([
                    'message' => "Another active AI ChatBot ('{$conflictBot->name}') is already connected to one or more of the selected channels.",
                ], 422);
            }
        }

        DB::beginTransaction();
        try {
            $chatbot->fill($request->only([
                'name',
                'avatar',
                'description',
                'system_prompt',
                'temperature',
                'ai_provider_config_id',
                'provider',
                'model',
                'knowledge_base_id',
                'status',
                'business_hours',
                'fallback_message',
                'handoff_rules',
            ]));

            $chatbot->save();

            if ($channelIds !== null) {
                $chatbot->channels()->sync($channelIds);
            }

            DB::commit();

            return response()->json($chatbot->load(['channels', 'knowledgeBase', 'providerConfig', 'creator']));
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("AiChatbotController@update error: " . $e->getMessage());
            return response()->json(['message' => 'Failed to update AI ChatBot: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Delete an AI ChatBot.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $chatbot = AiChatbot::where('tenant_id', $tenantId)->findOrFail($id);

        $chatbot->channels()->detach();
        $chatbot->delete();

        return response()->json(['message' => 'AI ChatBot deleted successfully']);
    }

    /**
     * Activity logs for this ChatBot.
     */
    public function logs(Request $request, $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $chatbot = AiChatbot::where('tenant_id', $tenantId)->findOrFail($id);

        $logs = AiChatbotLog::where('ai_chatbot_id', $chatbot->id)
            ->with(['conversation'])
            ->orderBy('id', 'desc')
            ->paginate(20);

        return response()->json($logs);
    }

    /**
     * Test query sandbox simulation.
     */
    public function test(Request $request, $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $chatbot = AiChatbot::where('tenant_id', $tenantId)->findOrFail($id);

        $request->validate([
            'query' => 'required|string|max:2000',
        ]);

        $startTime = microtime(true);
        $userQuery = trim($request->input('query'));
        $ragContext = '';
        $matchedSources = [];

        // 1. Semantic RAG retrieval if Knowledge Base is attached
        if ($chatbot->knowledge_base_id) {
            try {
                $ragService = app(RAGService::class);
                $chunks = $ragService->searchSimilarChunks($chatbot->knowledge_base_id, $userQuery, 3);
                foreach ($chunks as $chunk) {
                    $ragContext .= "Context excerpt:\n" . $chunk->chunk_text . "\n\n";
                    $matchedSources[] = [
                        'id' => $chunk->id,
                        'source' => $chunk->source->name ?? 'Knowledge Document',
                        'snippet' => substr($chunk->chunk_text, 0, 120) . '...',
                    ];
                }
            } catch (Exception $e) {
                Log::warning("Sandbox RAG search failed: " . $e->getMessage());
            }
        }

        // 2. Build system instructions
        $fullSystemPrompt = $chatbot->system_prompt;
        if (!empty($ragContext)) {
            $fullSystemPrompt .= "\n\nUse the following verified knowledge base documents to accurately answer the user's questions:\n" . $ragContext;
        }

        // 3. Call LLM
        $aiService = app(AIProviderService::class);
        $messages = [
            ['role' => 'system', 'content' => $fullSystemPrompt],
            ['role' => 'user', 'content' => $userQuery],
        ];

        try {
            $resolvedAI = $aiService->resolveAIExecution(
                $tenantId,
                'ai_chatbot',
                $chatbot->providerConfig?->provider_name ?: $chatbot->provider,
                $chatbot->model
            );

            if (!empty($resolvedAI['is_blocked'])) {
                $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
                return response()->json([
                    'success' => true,
                    'reply' => $resolvedAI['fallback_message'] ?: 'AI token limit reached for the current billing cycle.',
                    'latency_ms' => $latencyMs,
                    'matched_sources' => $matchedSources,
                    'provider' => $resolvedAI['provider'] ?? 'system',
                    'model' => $resolvedAI['model'] ?? 'default',
                    'is_safeguard_blocked' => true,
                    'block_reason' => $resolvedAI['block_reason'] ?? 'quota_exceeded',
                ]);
            }

            $provider = $resolvedAI['provider'] ?: ($chatbot->provider ?: 'groq');
            $apiKey = $resolvedAI['api_key'];
            $model = $resolvedAI['model'] ?: ($chatbot->model ?: 'llama-3.3-70b-versatile');

            $reply = $aiService->generateCompletion(
                $provider,
                $apiKey,
                $model,
                $messages,
                (float) ($chatbot->temperature ?: 0.70),
                'ai_chatbot',
                $tenantId
            );

            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

            return response()->json([
                'success' => true,
                'reply' => $reply,
                'latency_ms' => $latencyMs,
                'matched_sources' => $matchedSources,
                'provider' => $provider,
                'model' => $model,
            ]);
        } catch (Exception $e) {
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'latency_ms' => $latencyMs,
            ], 500);
        }
    }
}
