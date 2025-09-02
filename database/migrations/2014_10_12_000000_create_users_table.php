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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('phone_number')->unique();
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            
            // Personal Information
            $table->string('first_name')->nullable();
            $table->string('middle_name')->nullable();
            $table->string('last_name')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['male', 'female'])->nullable();
            $table->string('address')->nullable();
            $table->string('state')->nullable();
            $table->string('lga')->nullable();
            
            // KYC Information
            $table->string('bvn')->nullable()->unique();
            $table->string('nin')->nullable()->unique();
            $table->enum('verification_type', ['bvn', 'nin'])->nullable();
            $table->boolean('bvn_verified')->default(false);
            $table->boolean('nin_verified')->default(false);
            $table->enum('kyc_level', ['tier_0', 'tier_1', 'tier_2', 'tier_3'])->default('tier_0');
            $table->timestamp('kyc_verified_at')->nullable();
            
            // Account Status
            $table->enum('status', ['pending', 'active', 'suspended', 'closed'])->default('pending');
            $table->boolean('is_active')->default(true);
            
            // Monnify Integration
            $table->string('monnify_customer_id')->nullable()->unique();
            $table->json('monnify_data')->nullable();
            
            $table->rememberToken();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};