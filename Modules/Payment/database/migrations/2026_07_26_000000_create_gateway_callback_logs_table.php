<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_callback_logs', function (Blueprint $table) {

            $table->id();

            $table->string('gateway')->index();

            $table->unsignedBigInteger('gateway_transaction_id')->nullable()->index();

            $table->string('method', 10)->nullable();

            $table->string('url')->nullable();

            $table->ipAddress('ip')->nullable();

            $table->text('user_agent')->nullable();

            $table->json('headers')->nullable();

            $table->json('query')->nullable();

            $table->json('body')->nullable();

            $table->json('payload')->nullable();

            $table->text('exception')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_callback_logs');
    }
};