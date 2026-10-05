<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('pos_order_items', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('pos_order_id')->constrained('pos_orders')->onDelete('cascade');
            
            // اطلاعات محصول
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            
            // اطلاعات لحظه‌ای فروش (برای حفظ تاریخچه)
            $table->string('product_name');
            $table->string('sku')->nullable();
            $table->bigInteger('unit_price'); // قیمت فروش واحد
            $table->integer('quantity');
            $table->bigInteger('discount_amount')->default(0); // مبلغ تخفیف این آیتم
            $table->bigInteger('total_price'); // قیمت کل پس از اعمال تخفیف
            
            $table->timestamps();
            
            $table->index(['pos_order_id']);
            $table->index(['product_id', 'created_at']);
            $table->index(['product_variant_id', 'created_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('pos_order_items');
    }
};