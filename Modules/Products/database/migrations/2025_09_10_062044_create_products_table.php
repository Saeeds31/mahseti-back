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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('main_image')->nullable(); // تصویر اصلی شاخص
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->enum('status', ['draft', 'published', 'unpublished'])->default('draft');
            $table->bigInteger('discount_value')->nullable();
            $table->enum('discount_type', ['percent', 'fixed'])->nullable();
            $table->string('barcode')->nullable();
            $table->string('sku')->nullable();
            $table->integer('stock')->default(0);
            $table->bigInteger('price');
            $table->string('video')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
