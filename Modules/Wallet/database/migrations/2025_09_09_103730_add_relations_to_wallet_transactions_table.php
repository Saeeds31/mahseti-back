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
        Schema::table('wallet_transactions', function (Blueprint $table) {

            $table->foreignId('order_id')
                ->nullable()
                ->after('wallet_id')
                ->constrained('orders')
                ->nullOnDelete();

            $table->foreignId('gateway_transaction_id')
                ->nullable()
                ->after('order_id')
                ->constrained('gateway_transactions')
                ->nullOnDelete();
            $table->bigInteger('balance_after')->nullable()->after('amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {

            $table->dropForeign(['order_id']);
            $table->dropForeign(['gateway_transaction_id']);

            $table->dropColumn([
                'order_id',
                'gateway_transaction_id',
            ]);
        });
    }
};
