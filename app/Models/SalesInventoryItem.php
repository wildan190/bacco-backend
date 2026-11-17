<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesInventoryItem extends Model
{
    protected $fillable = ['inventory_id', 'product_id', 'quantity_carried', 'quantity_sold', 'quantity_returned'];

    protected function casts(): array
    {
        return [
            'quantity_carried' => 'integer',
            'quantity_sold' => 'integer',
            'quantity_returned' => 'integer',
        ];
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(SalesInventory::class, 'inventory_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
