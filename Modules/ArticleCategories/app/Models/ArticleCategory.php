<?php

namespace Modules\ArticleCategories\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\ArticleCategories\Database\Factories\ArticleCategoryFactory;

class ArticleCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'parent_id',
        'meta_title',
        'meta_description',
        'description'
    ];

    public function articles()
    {
        return $this->belongsToMany(\Modules\Articles\Models\Article::class, 'article_article_category', 'article_category_id', 'article_id');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id')->with('children');
    }
}
