<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Carbon\Carbon;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'phone_number',
        'email',
        'password',
        'first_name',
        'middle_name',
        'last_name',
        'date_of_birth',
        'gender',
        'address',
        'state',
        'lga',
        'bvn',
        'nin',
        'verification_type',
        'bvn_verified',
        'nin_verified',
        'kyc_level',
        'status',
        'monnify_customer_id',
        'monnify_data'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'bvn',
        'nin'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'kyc_verified_at' => 'datetime',
        'date_of_birth' => 'date',
        'bvn_verified' => 'boolean',
        'nin_verified' => 'boolean',
        'is_active' => 'boolean',
        'monnify_data' => 'array'
    ];

    /**
     * Get the user's wallet
     */
    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }

    /**
     * Get the user's transactions
     */
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Get user's full name
     */
    public function getFullNameAttribute()
    {
        return trim("{$this->first_name} {$this->middle_name} {$this->last_name}");
    }

    /**
     * Get masked phone number
     */
    public function getMaskedPhoneAttribute()
    {
        if (!$this->phone_number) return null;
        return substr($this->phone_number, 0, 4) . '***' . substr($this->phone_number, -3);
    }

    /**
     * Get masked BVN
     */
    public function getMaskedBvnAttribute()
    {
        if (!$this->bvn) return null;
        return substr($this->bvn, 0, 3) . '****' . substr($this->bvn, -3);
    }

    /**
     * Get masked NIN
     */
    public function getMaskedNinAttribute()
    {
        if (!$this->nin) return null;
        return substr($this->nin, 0, 3) . '****' . substr($this->nin, -3);
    }

    /**
     * Check if user is KYC verified
     */
    public function isKycVerified()
    {
        return $this->kyc_level !== 'tier_0' && ($this->bvn_verified || $this->nin_verified);
    }

    /**
     * Get KYC limits based on tier
     */
    public function getKycLimits()
    {
        $limits = [
            'tier_0' => ['daily' => 0, 'monthly' => 0],
            'tier_1' => ['daily' => 50000, 'monthly' => 200000],
            'tier_2' => ['daily' => 200000, 'monthly' => 500000],
            'tier_3' => ['daily' => 5000000, 'monthly' => 5000000]
        ];

        return $limits[$this->kyc_level] ?? $limits['tier_0'];
    }

    /**
     * Check if user can create wallet
     */
    public function canCreateWallet()
    {
        return $this->isKycVerified() && 
               $this->status === 'active' && 
               !$this->wallet()->exists();
    }

    /**
     * Get age from date of birth
     */
    public function getAgeAttribute()
    {
        if (!$this->date_of_birth) return null;
        return Carbon::parse($this->date_of_birth)->age;
    }

    /**
     * Scope for active users
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active')->where('is_active', true);
    }

    /**
     * Scope for KYC verified users
     */
    public function scopeKycVerified($query)
    {
        return $query->where(function ($q) {
            $q->where('bvn_verified', true)->orWhere('nin_verified', true);
        })->where('kyc_level', '!=', 'tier_0');
    }

    /**
     * Check if phone is verified
     */
    public function hasVerifiedPhone()
    {
        return !is_null($this->phone_verified_at);
    }

    /**
     * Mark phone as verified
     */
    public function markPhoneAsVerified()
    {
        $this->forceFill([
            'phone_verified_at' => $this->freshTimestamp(),
        ])->save();
    }
}