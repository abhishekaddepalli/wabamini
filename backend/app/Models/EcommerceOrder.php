<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EcommerceOrder extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'ecommerce_connection_id',
        'external_order_id',
        'order_number',
        'customer_email',
        'customer_phone',
        'total_price',
        'financial_status',
        'fulfillment_status',
        'tracking_number',
        'tracking_url',
        'items_summary',
        'external_created_at',
    ];

    protected $casts = [
        'items_summary' => 'array',
        'external_created_at' => 'datetime',
        'total_price' => 'decimal:2',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(EcommerceConnection::class, 'ecommerce_connection_id');
    }
}
