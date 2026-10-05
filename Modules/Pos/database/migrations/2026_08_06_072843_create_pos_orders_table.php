<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('pos_orders', function (Blueprint $table) {
            $table->id();
            // کاربر الزامی است
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');

            // اطلاعات مالی (با bigInteger به جای decimal)
            $table->bigInteger('subtotal'); // جمع مبلغ قبل از تخفیف
            $table->bigInteger('discount_amount')->default(0);
            $table->bigInteger('total_amount'); // مبلغ نهایی
            $table->bigInteger('paid_amount'); // مبلغ پرداختی

            // وضعیت‌ها
            $table->enum('status', ['pending', 'paid', 'cancelled', 'returned'])->default('paid');

            // اطلاعات جلسه فروشنده
            $table->foreignId('cashier_session_id')->nullable()->constrained('pos_cashier_sessions')->nullOnDelete();
            $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();

            // اطلاعات تکمیلی
            $table->text('notes')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // ایندکس‌ها
            $table->index(['user_id', 'created_at']);
            $table->index(['cashier_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('pos_orders');
    }
};
