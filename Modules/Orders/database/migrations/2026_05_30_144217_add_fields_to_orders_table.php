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
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('reservation_type', ['none', 'three_days', 'seven_days'])->default('none')->after('status');
            $table->timestamp('reserved_until')->nullable()->after('reservation_type');
            $table->bigInteger('wallet_payment')->default(0)->after('total');
            $table->bigInteger('online_payment')->default(0)->after('wallet_payment');
            $table->foreignId('parent_order_id')->nullable()->constrained('orders')->after('id'); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            
        });
    }
};
