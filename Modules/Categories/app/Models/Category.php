<?php

namespace Modules\Categories\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Categories\Database\Factories\CategoryFactory;

class Category extends Model
{
    protected $fillable = ['title', 'meta_description', 'meta_title', 'slug', 'main_image', 'slug', 'description', 'icon', 'parent_id', 'show_in_home', 'show_products_in_home'];
    // رابطه با محصولات
    public function products()
    {
        return $this->belongsToMany(\Modules\Products\Models\Product::class, 'category_product', 'category_id', 'product_id');
    }

    // دسته‌بندی والد
    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    // دسته‌بندی‌های فرزند
    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
