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
        Schema::create('campaigns', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('campaign_tiers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('min_amount');
            $t->unsignedBigInteger('max_amount')->nullable(); // null = بدون سقف
            $t->boolean('free_shipping')->default(false);
            $t->decimal('discount_percent', 5, 2)->default(0);
            $t->timestamps();
        });
        Schema::create('campaign_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('campaign_id')->constrained();
            $t->foreignId('campaign_tier_id')->nullable()->constrained('campaign_tiers')->nullOnDelete();
            $t->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $t->unsignedBigInteger('order_subtotal');
            $t->unsignedBigInteger('discount_amount')->default(0);
            $t->boolean('free_shipping')->default(false);
            $t->unsignedBigInteger('order_total');
            $t->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('campaign_tiers');
        Schema::dropIfExists('campaign_orders');
    }
};
