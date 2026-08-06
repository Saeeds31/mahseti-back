<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // add fields to products Table and changes output response in all product routes
        Schema::table('products', function (Blueprint $table) {
            $table->enum('sales_channel', ['online_only', 'in_store_only', 'both'])->default('both')->after('status');
        });
    }

    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('sales_channel');
        });
    }
};
