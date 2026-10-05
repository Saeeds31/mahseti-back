<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gateway_transactions', function (Blueprint $table) {

            $table->dropForeign(['order_id']);
            $table->dropForeign(['wallet_id']);

            $table->dropColumn([
                'order_id',
                'wallet_id'
            ]);

            $table->nullableMorphs('payable');
            $table->string('message')->nullable();

            $table->json('request_data')->nullable()->after('message');

            $table->json('verify_data')->nullable()->after('request_data');

            $table->timestamp('paid_at')->nullable()->after('verify_data');
        });
    }

    public function down(): void {}
};
