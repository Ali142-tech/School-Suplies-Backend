<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser
{
    protected $attributes = ['role' => 'customer', 'is_active' => true];

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
        'password',
        'role',
        'phone',
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

    /** Only active platform administrators may enter the Super Admin panel. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'superadmin' && $this->role === 'platform_admin' && $this->is_active;
    }

    /** The vendor a storefront customer registered with (null for central marketplace accounts and for vendor/admin accounts). */
    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function vendorMemberships()
    {
        return $this->hasMany(VendorMembership::class);
    }

    public function ownedVendors()
    {
        return $this->hasMany(Vendor::class, 'owner_id');
    }

    public function addresses()
    {
        return $this->hasMany(Address::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'customer_id');
    }

    public function school()
    {
        return $this->hasOne(School::class);
    }
}
