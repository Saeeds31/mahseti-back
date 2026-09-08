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
        Schema::create('woo_import_mappings', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('woo_id')->index();
            $table->string('woo_type', 50)->index(); // user, product, order, etc
            $table->bigInteger('local_id')->index();
            $table->json('extra_data')->nullable();
            $table->timestamps();

            // جلوگیری از تکرار
            $table->unique(['woo_id', 'woo_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('woo_import_mappings');
    }
};
