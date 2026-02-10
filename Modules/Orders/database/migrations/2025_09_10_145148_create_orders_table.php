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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('address_id')->constrained('addresses')->cascadeOnDelete();
            $table->foreignId('shipping_method_id')->constrained('shipping_methods')->cascadeOnDelete()->nullable();
            $table->bigInteger('subtotal');
            $table->bigInteger('discount_amount')->default(0);
            $table->bigInteger('shipping_cost')->default(0);
            $table->bigInteger('total');
            $table->string('payment_method')->nullable();
            $table->string('payment_status')->default('pending');
            $table->string('status')->default('pending'); // pending, processing, shipped, completed, canceled
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
