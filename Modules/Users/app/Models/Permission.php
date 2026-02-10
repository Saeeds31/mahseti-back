<?php

namespace Modules\Users\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',  
        'label', 
    ];

    /**
     * نقش‌هایی که این دسترسی رو دارن
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'permission_role');
    }
}
