<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'role',
        'password',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Check if user is admin.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Check if user is sales.
     */
    public function isSales(): bool
    {
        return $this->role === 'sales';
    }

    /**
     * Store visits made by this user.
     */
    public function visits(): HasMany
    {
        return $this->hasMany(StoreVisit::class, 'user_id');
    }

    /**
     * Orders made by this sales user.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'user_id');
    }

    /**
     * Sales targets assigned to this user.
     */
    public function targets(): HasMany
    {
        return $this->hasMany(SalesTarget::class, 'user_id');
    }

    /**
     * Visit schedules assigned to this user.
     */
    public function visitSchedules(): HasMany
    {
        return $this->hasMany(VisitSchedule::class, 'user_id');
    }
}
