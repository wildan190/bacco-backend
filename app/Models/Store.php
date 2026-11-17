<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class Store extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'owner_name',
        'phone',
        'address',
        'latitude',
        'longitude',
        'notes',
        'photo_path',
        'assigned_sales_id',
        'schedule_frequency',
        'created_by',
        'is_active',
    ];

    protected $appends = ['photo_url'];

    protected function casts(): array
    {
        return [
            'latitude'  => 'float',
            'longitude' => 'float',
            'is_active' => 'boolean',
        ];
    }

    protected function photoUrl(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! $this->photo_path) {
                    return null;
                }

                // Already a full URL (e.g., previously migrated data)
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignedSales(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_sales_id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(StoreVisit::class, 'store_id');
    }

    public function latestVisit(): HasOne
    {
        return $this->hasOne(StoreVisit::class, 'store_id')->latestOfMany('visited_at');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'store_id');
    }

    public function visitSchedules(): HasMany
    {
        return $this->hasMany(VisitSchedule::class, 'store_id');
    }
}
