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
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            // Monnify Wallet Details
            $table->string('wallet_id')->unique(); // Monnify internal wallet ID
            $table->string('account_number')->unique(); // Moniepoint account number
            $table->string('account_name');
            $table->string('bank_name')->default('Moniepoint Microfinance Bank');
            $table->string('bank_code')->default('50515');
            
            // Balance Information
            $table->decimal('available_balance', 15, 2)->default(0.00);
            $table->decimal('ledger_balance', 15, 2)->default(0.00);
            
            // Wallet Status
            $table->enum('status', ['active', 'inactive', 'suspended', 'closed'])->default('active');
            $table->boolean('is_default')->default(true);
            
            // Transaction Limits based on KYC Level
            $table->decimal('daily_limit', 15, 2)->default(50000.00);
            $table->decimal('monthly_limit', 15, 2)->default(200000.00);
            $table->decimal('daily_spent', 15, 2)->default(0.00);
            $table->decimal('monthly_spent', 15, 2)->default(0.00);
            $table->date('last_daily_reset')->default(now());
            $table->date('last_monthly_reset')->default(now());
            
            // Monnify Integration Data
            $table->json('monnify_response')->nullable();
            $table->timestamp('wallet_created_at')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['user_id', 'status']);
            $table->index('account_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};