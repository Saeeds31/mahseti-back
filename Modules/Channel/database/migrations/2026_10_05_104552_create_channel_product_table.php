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
        Schema::create('channel_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->boolean('is_excluded')->default(false);

            $table->unsignedBigInteger('custom_price')->nullable();

            $table->string('sync_status')->nullable();
            $table->text('sync_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();

            $table->unique(['channel_id', 'product_id']);
            $table->index(['channel_id', 'is_excluded']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_product');
    }
};
