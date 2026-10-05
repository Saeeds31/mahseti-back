<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable(); // عنوان اختیاری
            $table->string('image_desktop')->nullable(); // تصویر دسکتاپ
            $table->string('image_mobile')->nullable(); // تصویر موبایل
            $table->string('link')->nullable(); // لینک کلیک
            $table->string('position')->nullable(); // لینک کلیک
            $table->boolean('status')->default(true); // فعال/غیرفعال
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};
