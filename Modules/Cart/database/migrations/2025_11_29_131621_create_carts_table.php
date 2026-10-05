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
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade');
            $table->foreignId('variant_id')
                ->constrained('product_variants')
                ->onDelete('cascade');
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('price_original')->default(0);
            $table->unsignedBigInteger('price_final')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'variant_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
