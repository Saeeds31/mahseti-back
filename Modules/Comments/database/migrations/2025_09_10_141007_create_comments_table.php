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
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->text('content');
            // کاربر کامنت گذار
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // polymorphic relation
            $table->morphs('commentable');
            // ریپلای و سلسله مراتب
            $table->unsignedBigInteger('parent_id')->nullable();
            // امتیاز (اختیاری)
            $table->tinyInteger('rating')->nullable(); // 1 تا 5
            // وضعیت
            $table->tinyInteger('status')->default(0); // 0=pending, 1=approved, 2=rejected
            // IP برای کنترل اسپم
            $table->ipAddress('ip')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
