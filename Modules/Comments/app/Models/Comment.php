<?php

namespace Modules\Comments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Users\Models\User;

// use Modules\Comments\Database\Factories\CommentFactory;

class Comment extends Model
{
    use HasFactory;

    protected $fillable = [
        'content',
        'user_id',
        'parent_id',
        'rating',
        'status',
        'ip',
        'commentable_id',
        'commentable_type',
    ];

    protected $casts = [
        'status' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function commentable()
    {
        return $this->morphTo();
    }

    public function replies()
    {
        return $this->hasMany(Comment::class, 'parent_id')->with('user');
    }
    public static function dashboardReport()
    {
        return [
            'total_comments'   => self::count(),
            'approved'         => self::where('status', 1)->count(),
            'pending'          => self::where('status', 0)->count(),
            'rejected'         => self::where('status', -1)->count(),
            'with_rating'      => self::whereNotNull('rating')->count(),
            'average_rating'   => self::whereNotNull('rating')->avg('rating'),
            'today_comments'   => self::whereDate('created_at', today())->count(),
            'this_month'       => self::whereMonth('created_at', now()->month)->count(),
        ];
    }
}
