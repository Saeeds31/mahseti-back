<?php

namespace Modules\Articles\Models;

use Illuminate\Database\Eloquent\Model;
use  Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\ArticleCategories\Models\ArticleCategory;
use Modules\Comments\Models\Comment;
use Modules\Users\Models\User;

// use Modules\Articles\Database\Factories\ArticleFactory;

class Article extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'image',
        'short_description',
        'description',
        'meta_title',
        'meta_description',
        'read_time',
        'author_id'
    ];

    public function categories()
    {
        return $this->belongsToMany(ArticleCategory::class, 'article_article_category', 'article_id', 'article_category_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }
    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }
    public static function latestArticles($limit = 8)
    {
        return self::orderBy('created_at', 'desc')
            ->take($limit)
            ->get();
    }
}
