<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Plan extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'stripe_product_id',
        'is_active',
        'trial_days',
        'max_team_members',
        'max_campaigns',
        'max_integrations',
        'allowed_integrations',
        'own_crm_access',
        'max_channels',
        'allowed_channels',
        'max_automations',
        'flow_credits',
        'monthly_ai_tokens',
        'has_flow_templates',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'own_crm_access' => 'boolean',
        'has_flow_templates' => 'boolean',
        'trial_days' => 'integer',
        'max_team_members' => 'integer',
        'max_campaigns' => 'integer',
        'max_integrations' => 'integer',
        'allowed_integrations' => 'array',
        'max_channels' => 'integer',
        'allowed_channels' => 'array',
        'max_automations' => 'integer',
        'flow_credits' => 'integer',
        'monthly_ai_tokens' => 'integer',
        'sort_order' => 'integer',
    ];

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class);
    }
}
