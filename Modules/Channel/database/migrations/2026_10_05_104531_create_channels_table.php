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
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();         
            $table->string('name');                 
            $table->string('type');                   
            $table->boolean('is_connected')->default(false);  
            $table->json('credentials')->nullable(); 
            $table->decimal('commission_percent', 5, 2)->default(0); 
            $table->json('settings')->nullable();      
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
