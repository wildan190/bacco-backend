<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_uuid',
        'invoice_number',
        'user_id',
        'store_id',
        'total_amount',
        'cash_paid',
        'change_due',
        'payment_method',
        'notes',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'float',
            'cash_paid' => 'float',
            'change_due' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }
}
