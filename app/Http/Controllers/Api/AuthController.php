<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Otp;
use App\Models\Wallet;
use App\Services\SmsService;
use App\Services\MonnifyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AuthController extends Controller
{
    protected $smsService;
    protected $monnifyService;

    public function __construct(SmsService $smsService, MonnifyService $monnifyService)
    {
        $this->smsService = $smsService;
        $this->monnifyService = $monnifyService;
    }

    /**
     * Step 1: Send OTP to phone number
     */
    public function sendOtp(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone_number' => 'required|string',
                'type' => 'sometimes|in:registration,login,password_reset'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid phone number format',
                    'errors' => $validator->errors()
                ], 422);
            }

            $phoneNumber = $this->formatPhoneNumber($request->phone_number);
            $type = $request->type ?? 'registration';

            // Check if user already exists for registration
            if ($type === 'registration') {
                $existingUser = User::where('phone_number', $phoneNumber)->first();
                if ($existingUser) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Phone number already registered'
                    ], 409);
                }
            }

            // Clean up old OTPs for this phone number and type
            Otp::where('phone_number', $phoneNumber)
               ->where('type', $type)
               ->where('created_at', '<', now()->subMinutes(5))
               ->delete();

            // Check for recent OTP
            $recentOtp = Otp::where('phone_number', $phoneNumber)
                           ->where('type', $type)
                           ->where('created_at', '>', now()->subMinutes(1))
                           ->first();

            if ($recentOtp) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please wait before requesting another OTP',
                    'retry_after' => 60 - $recentOtp->created_at->diffInSeconds()
                ], 429);
            }

            // Generate and send OTP
            $otp = Otp::generateOtp($phoneNumber, $type);
            
            // Send SMS
            // $this->smsService->sendOtp($phoneNumber, $otp->otp_code);

            return response()->json([
                'success' => true,
                'message' => 'OTP sent successfully',
                'data' => [
                    'phone_number' => $this->maskPhoneNumber($phoneNumber),
                    'expires_at' => $otp->expires_at->toISOString(),
                    'type' => $type
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Send OTP Failed', [
                'phone' => $request->phone_number,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send OTP. Please try again.'
            ], 500);
        }
    }

    /**
     * Step 2: Verify OTP
     */
    public function verifyOtp(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone_number' => 'required|string',
                'otp_code' => 'required|string|size:6',
                'type' => 'sometimes|in:registration,login,password_reset'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid input data',
                    'errors' => $validator->errors()
                ], 422);
            }

            $phoneNumber = $this->formatPhoneNumber($request->phone_number);
            $otpCode = $request->otp_code;
            $type = $request->type ?? 'registration';

            // Find valid OTP
            $otp = Otp::where('phone_number', $phoneNumber)
                     ->where('type', $type)
                     ->where('otp_code', $otpCode)
                     ->valid()
                     ->first();

            if (!$otp) {
                // Increment attempts for existing OTP
                $existingOtp = Otp::where('phone_number', $phoneNumber)
                                 ->where('type', $type)
                                 ->where('otp_code', $otpCode)
                                 ->first();
                
                if ($existingOtp) {
                    $existingOtp->incrementAttempts();
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or expired OTP'
                ], 401);
            }

            // Verify OTP
            $otp->verify();

            return response()->json([
                'success' => true,
                'message' => 'OTP verified successfully',
                'data' => [
                    'phone_number' => $this->maskPhoneNumber($phoneNumber),
                    'verified_at' => $otp->verified_at->toISOString(),
                    'next_step' => $type === 'registration' ? 'bvn_verification' : 'login'
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Verify OTP Failed', [
                'phone' => $request->phone_number,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'OTP verification failed. Please try again.'
            ], 500);
        }
    }

    /**
     * Step 3: Verify BVN or NIN and get customer details
     */
    public function verifyIdentity(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone_number' => 'required|string',
                'verification_type' => 'required|in:bvn,nin',
                'verification_number' => 'required|string',
                'date_of_birth' => 'required_if:verification_type,bvn|date_format:d-m-Y'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid input data',
                    'errors' => $validator->errors()
                ], 422);
            }

            $phoneNumber = $this->formatPhoneNumber($request->phone_number);

            // Check if OTP was verified for this phone
            $verifiedOtp = Otp::where('phone_number', $phoneNumber)
                             ->where('type', 'registration')
                             ->where('is_verified', true)
                             ->where('verified_at', '>', now()->subMinutes(10))
                             ->first();

            if (!$verifiedOtp) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please verify OTP first'
                ], 400);
            }

            $verificationType = $request->verification_type;
            $verificationNumber = $request->verification_number;

            // Check if BVN/NIN already exists
            $field = $verificationType === 'bvn' ? 'bvn' : 'nin';
            $existingUser = User::where($field, $verificationNumber)->first();
            
            if ($existingUser) {
                return response()->json([
                    'success' => false,
                    'message' => strtoupper($verificationType) . ' already registered with another account'
                ], 409);
            }

            // Verify with Monnify
            if ($verificationType === 'bvn') {
                $verificationResult = $this->monnifyService->verifyBvn(
                    $verificationNumber, 
                    $request->date_of_birth
                );
            } else {
                $verificationResult = $this->monnifyService->verifyNin($verificationNumber);
            }

            if (!$verificationResult['requestSuccessful']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Identity verification failed: ' . ($verificationResult['responseMessage'] ?? 'Unknown error')
                ], 400);
            }

            $customerData = $verificationResult['responseBody'];

            return response()->json([
                'success' => true,
                'message' => 'Identity verified successfully',
                'data' => [
                    'phone_number' => $this->maskPhoneNumber($phoneNumber),
                    'verification_type' => $verificationType,
                    'customer_details' => [
                        'first_name' => $customerData['firstName'] ?? null,
                        'middle_name' => $customerData['middleName'] ?? null,
                        'last_name' => $customerData['lastName'] ?? null,
                        'date_of_birth' => $customerData['dateOfBirth'] ?? $request->date_of_birth,
                        'gender' => isset($customerData['gender']) ? strtolower($customerData['gender']) : null,
                        'state_of_origin' => $customerData['stateOfOrigin'] ?? null,
                        'lga' => $customerData['lgaOfOrigin'] ?? null
                    ],
                    'next_step' => 'complete_registration'
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Identity Verification Failed', [
                'phone' => $request->phone_number,
                'verification_type' => $request->verification_type,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Identity verification failed. Please try again.'
            ], 500);
        }
    }

    /**
     * Step 4: Complete registration with password and create wallet
     */
    public function completeRegistration(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone_number' => 'required|string',
                'verification_type' => 'required|in:bvn,nin',
                'verification_number' => 'required|string',
                'date_of_birth' => 'required|date_format:d-m-Y',
                'first_name' => 'required|string|max:50',
                'last_name' => 'required|string|max:50',
                'middle_name' => 'nullable|string|max:50',
                'gender' => 'required|in:male,female',
                'email' => 'nullable|email|unique:users,email',
                'password' => 'required|string|min:6|max:6|regex:/^[0-9]{6}$/', // 6-digit PIN
                'address' => 'nullable|string|max:255',
                'state' => 'nullable|string|max:50',
                'lga' => 'nullable|string|max:50'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid input data',
                    'errors' => $validator->errors()
                ], 422);
            }

            $phoneNumber = $this->formatPhoneNumber($request->phone_number);

            return DB::transaction(function () use ($request, $phoneNumber) {
                // Create user
                $user = User::create([
                    'phone_number' => $phoneNumber,
                    'phone_verified_at' => now(),
                    'email' => $request->email,
                    'password' => Hash::make($request->password),
                    'first_name' => $request->first_name,
                    'middle_name' => $request->middle_name,
                    'last_name' => $request->last_name,
                    'date_of_birth' => Carbon::createFromFormat('d-m-Y', $request->date_of_birth)->format('Y-m-d'),
                    'gender' => $request->gender,
                    'address' => $request->address,
                    'state' => $request->state,
                    'lga' => $request->lga,
                    $request->verification_type => $request->verification_number,
                    'verification_type' => $request->verification_type,
                    $request->verification_type . '_verified' => true,
                    'kyc_level' => 'tier_1',
                    'kyc_verified_at' => now(),
                    'status' => 'active'
                ]);

                // Create wallet with Monnify
                $walletData = [
                    'wallet_name' => $user->full_name . ' Wallet',
                    'customer_name' => $user->full_name,
                    'bvn' => $request->verification_type === 'bvn' ? $request->verification_number : null,
                    'date_of_birth' => $request->date_of_birth,
                    'email' => $user->email ?? $phoneNumber . '@hijaz.app',
                    'phone_number' => $phoneNumber
                ];

                $monnifyResponse = $this->monnifyService->createWallet($walletData);

                if (!$monnifyResponse['requestSuccessful']) {
                    throw new \Exception('Failed to create wallet: ' . $monnifyResponse['responseMessage']);
                }

                $walletDetails = $monnifyResponse['responseBody'];

                // Create local wallet record
                $wallet = Wallet::create([
                    'user_id' => $user->id,
                    'wallet_id' => $walletDetails['walletId'],
                    'account_number' => $walletDetails['accountNumber'],
                    'account_name' => $walletDetails['accountName'],
                    'bank_name' => 'Moniepoint Microfinance Bank',
                    'bank_code' => '50515',
                    'available_balance' => 0.00,
                    'ledger_balance' => 0.00,
                    'status' => 'active',
                    'daily_limit' => config('services.kyc.tier_1_limit', 50000),
                    'monthly_limit' => 200000,
                    'monnify_response' => $monnifyResponse,
                    'wallet_created_at' => now()
                ]);

                // Update user with Monnify data
                $user->update([
                    'monnify_customer_id' => $walletDetails['customerId'] ?? null,
                    'monnify_data' => $monnifyResponse
                ]);

                // Send welcome SMS
                $this->smsService->sendWelcomeSms(
                    $phoneNumber, 
                    $user->first_name, 
                    $wallet->account_number
                );

                // Generate access token
                $token = $user->createToken('hijaz_mobile_app')->plainTextToken;

                return response()->json([
                    'success' => true,
                    'message' => 'Registration completed successfully',
                    'data' => [
                        'user' => [
                            'id' => $user->id,
                            'phone_number' => $user->masked_phone,
                            'email' => $user->email,
                            'full_name' => $user->full_name,
                            'kyc_level' => $user->kyc_level,
                            'status' => $user->status
                        ],
                        'wallet' => [
                            'account_number' => $wallet->account_number,
                            'account_name' => $wallet->account_name,
                            'bank_name' => $wallet->bank_name,
                            'balance' => $wallet->formatted_balance,
                            'daily_limit' => $wallet->daily_limit,
                            'status' => $wallet->status
                        ],
                        'access_token' => $token,
                        'token_type' => 'Bearer'
                    ]
                ], 201);
            });

        } catch (\Exception $e) {
            Log::error('Registration Completion Failed', [
                'phone' => $request->phone_number,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Registration failed. Please try again.'
            ], 500);
        }
    }

    /**
     * Login with phone number and password
     */
    public function login(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone_number' => 'required|string',
                'password' => 'required|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid input data',
                    'errors' => $validator->errors()
                ], 422);
            }

            $phoneNumber = $this->formatPhoneNumber($request->phone_number);
            
            $user = User::where('phone_number', $phoneNumber)->first();

            if (!$user || !Hash::check($request->password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid credentials'
                ], 401);
            }

            if (!$user->hasVerifiedPhone()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Phone number not verified'
                ], 401);
            }

            if ($user->status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'Account is not active. Please contact support.'
                ], 401);
            }

            // Generate token
            $token = $user->createToken('hijaz_mobile_app')->plainTextToken;

            // Load wallet
            $wallet = $user->wallet;

            return response()->json([
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'phone_number' => $user->masked_phone,
                        'email' => $user->email,
                        'full_name' => $user->full_name,
                        'kyc_level' => $user->kyc_level,
                        'status' => $user->status
                    ],
                    'wallet' => $wallet ? [
                        'account_number' => $wallet->account_number,
                        'account_name' => $wallet->account_name,
                        'bank_name' => $wallet->bank_name,
                        'balance' => $wallet->formatted_balance,
                        'status' => $wallet->status
                    ] : null,
                    'access_token' => $token,
                    'token_type' => 'Bearer'
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Login Failed', [
                'phone' => $request->phone_number,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Login failed. Please try again.'
            ], 500);
        }
    }

    /**
     * Logout user
     */
    public function logout(Request $request)
    {
        try {
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Logged out successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Logout failed'
            ], 500);
        }
    }

    /**
     * Get user profile
     */
    public function profile(Request $request)
    {
        try {
            $user = $request->user()->load('wallet');
            
            return response()->json([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'phone_number' => $user->masked_phone,
                        'email' => $user->email,
                        'full_name' => $user->full_name,
                        'first_name' => $user->first_name,
                        'last_name' => $user->last_name,
                        'date_of_birth' => $user->date_of_birth?->format('d-m-Y'),
                        'gender' => $user->gender,
                        'kyc_level' => $user->kyc_level,
                        'status' => $user->status,
                        'created_at' => $user->created_at->toISOString()
                    ],
                    'wallet' => $user->wallet ? [
                        'account_number' => $user->wallet->account_number,
                        'account_name' => $user->wallet->account_name,
                        'bank_name' => $user->wallet->bank_name,
                        'balance' => $user->wallet->formatted_balance,
                        'daily_limit' => $user->wallet->daily_limit,
                        'remaining_daily_limit' => $user->wallet->remaining_daily_limit,
                        'status' => $user->wallet->status
                    ] : null
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Get Profile Failed', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get profile'
            ], 500);
        }
    }

    /**
     * Helper methods
     */
    private function formatPhoneNumber($phoneNumber)
    {
        $phone = preg_replace('/[^0-9]/', '', $phoneNumber);
        
        if (strlen($phone) === 11 && substr($phone, 0, 1) === '0') {
            $phone = '234' . substr($phone, 1);
        } elseif (strlen($phone) === 10) {
            $phone = '234' . $phone;
        } elseif (strlen($phone) === 13 && substr($phone, 0, 3) === '234') {
            // Already formatted
        } else {
            throw new \Exception('Invalid phone number format');
        }
        
        return $phone;
    }

    private function maskPhoneNumber($phoneNumber)
    {
        if (strlen($phoneNumber) >= 10) {
            return substr($phoneNumber, 0, 4) . '****' . substr($phoneNumber, -3);
        }
        return '***masked***';
    }
}