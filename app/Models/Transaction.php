<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
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
        'reference',
        'external_reference',
        'type',
        'category',
        'amount',
        'fee',
        'balance_before',
        'balance_after',
        'status',
        'status_message',
        'destination_account',
        'destination_bank',
        'destination_bank_code',
        'destination_account_name',
        'narration',
        'channel',
        'metadata',
        'provider_response',
        'processed_at'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'fee' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'metadata' => 'array',
        'provider_response' => 'array',
        'processed_at' => 'datetime'
    ];

    /**
     * Get the user that owns the transaction
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the wallet associated with the transaction
     */
    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    /**
     * Get formatted amount
     */
    public function getFormattedAmountAttribute()
    {
        return '₦' . number_format($this->amount, 2);
    }

    /**
     * Get transaction type icon
     */
    public function getTypeIconAttribute()
    {
        return $this->type === 'credit' ? '↑' : '↓';
    }

    /**
     * Get transaction type color
     */
    public function getTypeColorAttribute()
    {
        return $this->type === 'credit' ? 'green' : 'red';
    }

    /**
     * Check if transaction is successful
     */
    public function isSuccessful()
    {
        return $this->status === 'successful';
    }

    /**
     * Check if transaction is pending
     */
    public function isPending()
    {
        return in_array($this->status, ['pending', 'processing']);
    }

    /**
     * Check if transaction failed
     */
    public function isFailed()
    {
        return $this->status === 'failed';
    }

    /**
     * Mark transaction as successful
     */
    public function markAsSuccessful($message = null)
    {
        $this->status = 'successful';
        $this->status_message = $message;
        $this->processed_at = now();
        $this->save();
    }

    /**
     * Mark transaction as failed
     */
    public function markAsFailed($message = null)
    {
        $this->status = 'failed';
        $this->status_message = $message;
        $this->processed_at = now();
        $this->save();
    }

    /**
     * Scope for successful transactions
     */
    public function scopeSuccessful($query)
    {
        return $query->where('status', 'successful');
    }

    /**
     * Scope for pending transactions
     */
    public function scopePending($query)
    {
        return $query->whereIn('status', ['pending', 'processing']);
    }

    /**
     * Scope for failed transactions
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope for credit transactions
     */
    public function scopeCredits($query)
    {
        return $query->where('type', 'credit');
    }

    /**
     * Scope for debit transactions
     */
    public function scopeDebits($query)
    {
        return $query->where('type', 'debit');
    }

    /**
     * Scope for transactions within date range
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Generate unique transaction reference
     */
    public static function generateReference($prefix = 'HJZ')
    {
        return $prefix . date('Ymd') . time() . rand(1000, 9999);
    }

    /**
     * Get transaction summary for a wallet
     */
    public static function getSummaryForWallet($walletId, $period = 'today')
    {
        $query = self::where('wallet_id', $walletId)->successful();

        switch ($period) {
            case 'today':
                $query->whereDate('created_at', today());
                break;
            case 'week':
                $query->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()]);
                break;
            case 'month':
                $query->whereMonth('created_at', now()->month);
                break;
        }

        return [
            'total_credits' => $query->clone()->credits()->sum('amount'),
            'total_debits' => $query->clone()->debits()->sum('amount'),
            'transaction_count' => $query->count(),
        ];
    }
}