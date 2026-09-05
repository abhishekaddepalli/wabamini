<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUsageLog extends Model
{
    use HasFactory;

    protected $table = 'ai_usage_logs';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'feature',
        'provider',
        'model',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'estimated_cost',
        'latency_ms',
        'status',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'total_tokens' => 'integer',
        'estimated_cost' => 'float',
        'latency_ms' => 'integer',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Calculate estimated USD cost based on model and token counts.
     */
    public static function calculateEstimatedCost(string $model, int $promptTokens, int $completionTokens): float
    {
        $m = strtolower($model);
        
        // Default rates per 1M tokens: [prompt_price, completion_price] in USD
        $rates = [
            'gpt-4o' => [2.50, 10.00],
            'gpt-4o-mini' => [0.15, 0.60],
            'gpt-4-turbo' => [10.00, 30.00],
            'o1' => [15.00, 60.00],
            'o1-mini' => [3.00, 12.00],
            'claude-3-5-sonnet' => [3.00, 15.00],
            'claude-3-5-haiku' => [0.80, 4.00],
            'claude-3-opus' => [15.00, 75.00],
            'gemini-1.5-pro' => [1.25, 5.00],
            'gemini-1.5-flash' => [0.075, 0.30],
            'gemini-2.0-flash' => [0.10, 0.40],
            'deepseek-chat' => [0.14, 0.28],
            'deepseek-reasoner' => [0.55, 2.19],
            'llama-3.3-70b' => [0.59, 0.79],
            'llama-3.1-8b' => [0.05, 0.08],
            'mixtral-8x7b' => [0.24, 0.24],
        ];

        $matchedRate = [0.20, 0.80]; // fallback default
        foreach ($rates as $key => $rate) {
            if (str_contains($m, $key)) {
                $matchedRate = $rate;
                break;
            }
        }

        $promptCost = ($promptTokens / 1_000_000) * $matchedRate[0];
        $completionCost = ($completionTokens / 1_000_000) * $matchedRate[1];

        return round($promptCost + $completionCost, 6);
    }
}
