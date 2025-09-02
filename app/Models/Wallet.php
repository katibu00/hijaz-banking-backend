<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Wallet extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'wallet_id',
        'account_number',
        'account_name',
        'bank_name',
        'bank_code',
        'available_balance',
        'ledger_balance',
        'status',
        'is_default',
        'daily_limit',
        'monthly_limit',
        'daily_spent',
        'monthly_spent',
        'last_daily_reset',
        'last_monthly_reset',
        'monnify_response',
        'wallet_created_at'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'available_balance' => 'decimal:2',
        'ledger_balance' => 'decimal:2',
        'daily_limit' => 'decimal:2',
        'monthly_limit' => 'decimal:2',
        'daily_spent' => 'decimal:2',
        'monthly_spent' => 'decimal:2',
        'is_default' => 'boolean',
        'last_daily_reset' => 'date',
        'last_monthly_reset' => 'date',
        'monnify_response' => 'array',
        'wallet_created_at' => 'datetime'
    ];

    /**
     * Get the user that owns the wallet
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get wallet transactions
     */
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Get formatted account number
     */
    public function getFormattedAccountNumberAttribute()
    {
        if (!$this->account_number) return null;
        return chunk_split($this->account_number, 3, ' ');
    }

    /**
     * Get available balance formatted
     */
    public function getFormattedBalanceAttribute()
    {
        return '₦' . number_format($this->available_balance, 2);
    }

    /**
     * Check if wallet is active
     */
    public function isActive()
    {
        return $this->status === 'active';
    }

    /**
     * Check if wallet can perform transactions
     */
    public function canTransact()
    {
        return $this->isActive() && $this->user->status === 'active';
    }

    /**
     * Get remaining daily limit
     */
    public function getRemainingDailyLimitAttribute()
    {
        $this->resetLimitsIfNeeded();
        return max(0, $this->daily_limit - $this->daily_spent);
    }

    /**
     * Get remaining monthly limit
     */
    public function getRemainingMonthlyLimitAttribute()
    {
        $this->resetLimitsIfNeeded();
        return max(0, $this->monthly_limit - $this->monthly_spent);
    }

    /**
     * Check if amount can be spent within limits
     */
    public function canSpend($amount)
    {
        $this->resetLimitsIfNeeded();
        
        return $amount <= $this->available_balance &&
               $amount <= $this->remaining_daily_limit &&
               $amount <= $this->remaining_monthly_limit;
    }

    /**
     * Update spending limits
     */
    public function updateSpendingLimits($amount)
    {
        $this->resetLimitsIfNeeded();
        
        $this->daily_spent += $amount;
        $this->monthly_spent += $amount;
        $this->save();
    }

    /**
     * Reset spending limits if needed
     */
    protected function resetLimitsIfNeeded()
    {
        $today = Carbon::today();
        $thisMonth = Carbon::now()->startOfMonth();

        // Reset daily limit
        if ($this->last_daily_reset->lt($today)) {
            $this->daily_spent = 0;
            $this->last_daily_reset = $today;
        }

        // Reset monthly limit
        if ($this->last_monthly_reset->lt($thisMonth)) {
            $this->monthly_spent = 0;
            $this->last_monthly_reset = $thisMonth;
        }

        $this->save();
    }

    /**
     * Credit wallet
     */
    public function credit($amount, $description = null)
    {
        $this->available_balance += $amount;
        $this->ledger_balance += $amount;
        $this->save();

        return $this->transactions()->create([
            'user_id' => $this->user_id,
            'reference' => 'TXN' . time() . rand(1000, 9999),
            'type' => 'credit',
            'category' => 'deposit',
            'amount' => $amount,
            'balance_before' => $this->available_balance - $amount,
            'balance_after' => $this->available_balance,
            'status' => 'successful',
            'narration' => $description ?? 'Wallet Credit',
            'processed_at' => now()
        ]);
    }

    /**
     * Debit wallet
     */
    public function debit($amount, $description = null, $metadata = [])
    {
        if ($amount > $this->available_balance) {
            throw new \Exception('Insufficient balance');
        }

        $this->available_balance -= $amount;
        $this->ledger_balance -= $amount;
        $this->save();

        return $this->transactions()->create([
            'user_id' => $this->user_id,
            'reference' => 'TXN' . time() . rand(1000, 9999),
            'type' => 'debit',
            'category' => 'transfer',
            'amount' => $amount,
            'balance_before' => $this->available_balance + $amount,
            'balance_after' => $this->available_balance,
            'status' => 'successful',
            'narration' => $description ?? 'Wallet Debit',
            'metadata' => $metadata,
            'processed_at' => now()
        ]);
    }

    /**
     * Update balance from Monnify
     */
    public function syncBalanceFromMonnify($balance)
    {
        $this->available_balance = $balance;
        $this->ledger_balance = $balance;
        $this->save();
    }

    /**
     * Update KYC limits based on user's KYC level
     */
    public function updateKycLimits()
    {
        $limits = $this->user->getKycLimits();
        $this->daily_limit = $limits['daily'];
        $this->monthly_limit = $limits['monthly'];
        $this->save();
    }

    /**
     * Scope for active wallets
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Get recent transactions
     */
    public function getRecentTransactions($limit = 10)
    {
        return $this->transactions()
                   ->orderBy('created_at', 'desc')
                   ->limit($limit)
                   ->get();
    }
}