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
        Schema::create('gateway_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
            $table->foreignId('wallet_id')->nullable()->constrained('wallets')->nullOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->string('gateway')->default('zarinpal');
            $table->string('authority')->nullable();
            $table->string('ref_id')->nullable();
            $table->integer('amount');
            $table->enum('status', ['pending', 'paid', 'failed'])->default('pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gateway_transactions');
    }
};
