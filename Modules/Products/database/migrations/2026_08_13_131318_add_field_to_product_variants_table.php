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
        Schema::table('product_variants', function (Blueprint $table) {
            $table->bigInteger('discount_value')->nullable()->after('stock');
            $table->enum('discount_type', ['percent', 'fixed'])->nullable()->after("discount_value");
            $table->timestamp('discount_start_at')->nullable()->after("discount_type");
            $table->timestamp('discount_end_at')->nullable()->after("discount_start_at");
        });
        Schema::table('products', function (Blueprint $table) {
            $table->timestamp('discount_start_at')->nullable()->after("discount_type");
            $table->timestamp('discount_end_at')->nullable()->after("discount_start_at");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {});
    }
};
