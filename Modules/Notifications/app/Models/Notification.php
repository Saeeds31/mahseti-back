<?php

namespace Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Users\Models\User;

// use Modules\Notifications\Database\Factories\NotificationFactory;

class Notification extends Model
{
    use HasFactory;


    protected $fillable = [
        'title', 'message', 'permission_key', 'created_by', 'extra_data'
    ];

    protected $casts = [
        'extra_data' => 'array'
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reads()
    {
        return $this->hasMany(NotificationRead::class);
    }
}
