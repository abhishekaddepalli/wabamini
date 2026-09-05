<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_name',
        'team_size',
        'industry_category',
        'status',
        'onboarding_step',
        'currency_id',
        'stripe_customer_id',
        'stripe_subscription_id',
        'razorpay_customer_id',
        'razorpay_subscription_id',
        'paystack_customer_id',
        'paystack_subscription_id',
        'paystack_email_token',
        'flutterwave_customer_id',
        'flutterwave_subscription_id',
        'plan_id',
        'used_flow_credits',
        'default_language',
        'custom_mailer_type',
        'custom_mailer_config',
    ];

    protected $casts = [
        'used_flow_credits' => 'integer',
        'custom_mailer_config' => 'encrypted:json',
    ];

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
