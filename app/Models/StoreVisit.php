<?php

namespace App\Models;

use App\Models\Concerns\HasPhotoUrl;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreVisit extends Model
{
    use HasFactory, HasPhotoUrl;

    protected $fillable = [
        'user_id',
        'store_id',
        'visited_at',
        'photo_path',
        'notes',
        'latitude',
        'longitude',
    ];

    protected $appends = [
        'photo_url',
    ];

    protected function casts(): array
    {
        return [
            'visited_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
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
}
