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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('wallet_id')->constrained()->onDelete('cascade');
            
            // Transaction Details
            $table->string('reference')->unique();
            $table->string('external_reference')->nullable(); // Monnify transaction reference
            $table->enum('type', ['credit', 'debit']);
            $table->enum('category', ['transfer', 'deposit', 'withdrawal', 'reversal', 'fee']);
            $table->decimal('amount', 15, 2);
            $table->decimal('fee', 15, 2)->default(0.00);
            $table->decimal('balance_before', 15, 2);
            $table->decimal('balance_after', 15, 2);
            
            // Transaction Status
            $table->enum('status', ['pending', 'processing', 'successful', 'failed', 'reversed'])->default('pending');
            $table->string('status_message')->nullable();
            
            // Destination/Source Information (for transfers)
            $table->string('destination_account')->nullable();
            $table->string('destination_bank')->nullable();
            $table->string('destination_bank_code')->nullable();
            $table->string('destination_account_name')->nullable();
            
            // Metadata
            $table->string('narration')->nullable();
            $table->string('channel')->default('app'); // app, web, api
            $table->json('metadata')->nullable();
            $table->json('provider_response')->nullable(); // Monnify response
            
            // Timestamps
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index(['user_id', 'type', 'status']);
            $table->index(['wallet_id', 'created_at']);
            $table->index('reference');
            $table->index('external_reference');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};