<?php

namespace Modules\Users\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Users\Database\Factories\RoleFactory;

class Role extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'is_system', 'slug'];

    public function users()
    {
        return $this->belongsToMany(User::class);
    }
    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'permission_role');
    }
}
