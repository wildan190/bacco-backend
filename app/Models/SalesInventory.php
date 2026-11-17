<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesInventory extends Model
{
    protected $fillable = ['user_id', 'date', 'notes', 'status', 'settled_at'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'settled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesInventoryItem::class, 'inventory_id');
    }
}
