<?php

namespace Modules\Users\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Modules\Addresses\Models\Address;
use Modules\Wallet\Models\Wallet;
use Laravel\Sanctum\HasApiTokens;
use Modules\Cart\Models\Cart;
use Modules\Products\Models\Product;

// use Modules\Users\Database\Factories\UserFactory;

class User extends Authenticatable
{
    use HasFactory, HasApiTokens, Notifiable;

    protected $fillable = [
        'full_name',
        'mobile',
        'password',
        'national_code',
        'birth_date',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'birth_date' => 'date',
    ];

    /**
     * Get all addresses for the user.
     */
    public function addresses()
    {
        return $this->hasMany(Address::class);
    }
    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }
    public function cartItems()
    {
        return $this->hasMany(Cart::class);
    }
    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }
    public function getPermissionsAttribute()
    {
        return $this->roles
            ->map->permissions
            ->flatten()
            ->pluck('name')
            ->unique()
            ->values()
            ->toArray();
    }

    public function hasPermission($permission)
    {
        return $this->permissions()->contains('name', $permission);
    }
    public static  function dashboardReport()
    {
        return [
            'total_users'     => self::count(),
            'with_addresses'  => self::has('addresses')->count(),
            'with_wallet'     => self::has('wallet')->count(),
            'without_wallet'  => self::doesntHave('wallet')->count(),
            'today_registered' => self::whereDate('created_at', today())->count(),
        ];
    }
}
