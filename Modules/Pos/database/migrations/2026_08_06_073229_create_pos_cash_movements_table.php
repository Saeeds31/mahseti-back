<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('pos_cash_movements', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('cashier_session_id')->constrained('pos_cashier_sessions')->onDelete('cascade');
            $table->foreignId('pos_order_id')->nullable()->constrained('pos_orders')->nullOnDelete();
            
            $table->enum('type', ['deposit', 'withdraw']);
            $table->bigInteger('amount');
            
            $table->enum('payment_method', ['cash', 'card', 'transfer'])->default('cash');
            
            $table->string('reason')->nullable();
            
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            
            $table->timestamp('occurred_at')->useCurrent();
            
            $table->timestamps();
            
            $table->index(['cashier_session_id', 'occurred_at']);
            $table->index(['pos_order_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('pos_cash_movements');
    }
};