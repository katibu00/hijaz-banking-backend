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
        Schema::create('otps', function (Blueprint $table) {
            $table->id();
            $table->string('phone_number');
            $table->string('otp_code');
            $table->enum('type', ['registration', 'login', 'password_reset', 'transaction']);
            $table->boolean('is_verified')->default(false);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->integer('attempts')->default(0);
            $table->json('metadata')->nullable(); // For storing additional data if needed
            $table->timestamps();
            
            // Indexes
            $table->index(['phone_number', 'type', 'is_verified']);
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('otps');
    }
};