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
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // مثل OFF50
            $table->enum('type', ['percentage', 'fixed']); // درصدی یا ثابت
            $table->bigInteger('value'); // مقدار تخفیف
            $table->bigInteger('max_discount')->nullable(); // سقف تخفیف برای درصدی
            $table->bigInteger('min_purchase')->nullable(); // حداقل خرید
            $table->unsignedInteger('usage_limit')->nullable(); // محدودیت تعداد کل
            $table->unsignedInteger('user_usage_limit')->nullable(); // محدودیت هر کاربر
            $table->timestamp('start_date')->nullable();
            $table->timestamp('end_date')->nullable();
            $table->boolean('status')->default(true); // فعال یا غیرفعال
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
