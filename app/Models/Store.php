<?php

namespace App\Models;

use App\Models\Concerns\HasPhotoUrl;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Store extends Model
{
    use HasFactory, HasPhotoUrl;

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
        'admin_id',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
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
