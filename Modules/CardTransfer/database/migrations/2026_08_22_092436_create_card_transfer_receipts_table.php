<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_transfer_receipts', function (Blueprint $table) {
            $table->id();

            // ارتباط با سفارش - یک به یک
            $table->foreignId('order_id')
                ->unique()
                ->constrained('orders')
                ->cascadeOnDelete();

            // مسیر فایل رسید
            $table->string('image_path');

            // کد پیگیری - اختیاری
            $table->string('tracking_code')->nullable();

            // ادمین بررسی‌کننده
            $table->foreignId('admin_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // وضعیت رسید
            $table->enum('status', ['pending', 'approved', 'rejected'])
                ->default('pending');

            // توضیحات ادمین (مثلاً دلیل رد)
            $table->text('description')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_transfer_receipts');
    }
};
