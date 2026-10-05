<?php

namespace Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Notifications\Database\Factories\NotificationReadFactory;

class NotificationRead extends Model
{
    use HasFactory;
    protected $fillable = [
        'notification_id',
        'user_id',
        'seen_at'
    ];

    public $timestamps = false;

    public function notification()
    {
        return $this->belongsTo(Notification::class);
    }
}
