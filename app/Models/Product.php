<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'sku',
        'description',
        'price',
        'stock',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'stock' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function barcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class, 'product_id');
    }

    public function availableBarcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class, 'product_id')->where('status', 'available');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'product_id');
    }
}
