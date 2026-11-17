<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class StoreVisit extends Model
{
    use HasFactory;

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

    protected function photoUrl(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! $this->photo_path) {
                    return null;
                }

                if (str_starts_with($this->photo_path, 'http://') || str_starts_with($this->photo_path, 'https://')) {
                    return $this->photo_path;
                }

                $disk = config('filesystems.default', 'public');
                if ($disk === 's3') {
                    return Storage::disk('s3')->url($this->photo_path);
                }

                if (request()) {
                    return request()->schemeAndHttpHost() . '/storage/' . ltrim($this->photo_path, '/');
                }

                return Storage::disk('public')->url($this->photo_path);
            }
        );
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
