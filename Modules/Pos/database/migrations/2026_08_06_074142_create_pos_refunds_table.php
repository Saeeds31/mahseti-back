<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('pos_refunds', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('pos_order_id')->constrained('pos_orders')->onDelete('cascade');
            $table->foreignId('pos_order_item_id')->nullable()->constrained('pos_order_items')->nullOnDelete();
            
            $table->bigInteger('refund_amount');
            $table->enum('refund_method', ['cash', 'card', 'store_credit']);
            
            $table->text('reason')->nullable();
            
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('refunded_at')->useCurrent();
            
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('pos_refunds');
    }
};