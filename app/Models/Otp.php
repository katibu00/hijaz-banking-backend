<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Otp extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'phone_number',
        'otp_code',
        'type',
        'is_verified',
        'expires_at',
        'verified_at',
        'attempts',
        'metadata'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_verified' => 'boolean',
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
        'attempts' => 'integer',
        'metadata' => 'array'
    ];

    /**
     * Check if OTP is expired
     */
    public function isExpired()
    {
        return $this->expires_at->lt(now());
    }

    /**
     * Check if OTP is valid
     */
    public function isValid()
    {
        return !$this->is_verified && !$this->isExpired() && $this->attempts < 3;
    }

    /**
     * Verify OTP
     */
    public function verify()
    {
        $this->is_verified = true;
        $this->verified_at = now();
        $this->save();
    }

    /**
     * Increment attempts
     */
    public function incrementAttempts()
    {
        $this->attempts++;
        $this->save();
    }

    /**
     * Scope for valid OTPs
     */
    public function scopeValid($query)
    {
        return $query->where('is_verified', false)
                     ->where('expires_at', '>', now())
                     ->where('attempts', '<', 3);
    }

    /**
     * Scope for specific phone and type
     */
    public function scopeForPhone($query, $phone, $type)
    {
        return $query->where('phone_number', $phone)->where('type', $type);
    }

    /**
     * Generate a new OTP
     */
    public static function generateOtp($phoneNumber, $type = 'registration', $length = 6)
    {
        // Generate random OTP
        $otp = str_pad(rand(0, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
        
        // Set expiry (5 minutes from now)
        $expiresAt = Carbon::now()->addMinutes(5);
        
        return self::create([
            'phone_number' => $phoneNumber,
            'otp_code' => $otp,
            'type' => $type,
            'expires_at' => $expiresAt,
            'attempts' => 0
        ]);
    }

    /**
     * Clean up expired OTPs
     */
    public static function cleanupExpired()
    {
        return self::where('expires_at', '<', now()->subDays(1))->delete();
    }
}