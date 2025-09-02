<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\TransferController;
use App\Http\Controllers\Api\WebhookController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned the "api" middleware group. Make something great!
|
*/

// Public routes (no authentication required)
Route::prefix('v1')->group(function () {
    
    // Health check
    Route::get('/health', function () {
        return response()->json([
            'status' => 'OK',
            'service' => 'HIJAZ Banking API',
            'version' => '1.0.0',
            'timestamp' => now()->toISOString(),
            'environment' => app()->environment()
        ]);
    });

    // Authentication routes
    Route::prefix('auth')->group(function () {
        // Registration flow
        Route::post('/send-otp', [AuthController::class, 'sendOtp']);
        Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
        Route::post('/verify-identity', [AuthController::class, 'verifyIdentity']);
        Route::post('/complete-registration', [AuthController::class, 'completeRegistration']);
        
        // Login
        Route::post('/login', [AuthController::class, 'login']);
    });

    // Webhook routes (Monnify callbacks)
    Route::prefix('webhooks')->group(function () {
        Route::post('/monnify/transaction', [WebhookController::class, 'monnifyTransaction']);
        Route::post('/monnify/collection', [WebhookController::class, 'monnifyCollection']);
        Route::post('/monnify/transfer', [WebhookController::class, 'monnifyTransfer']);
    });

    // Public utility routes
    Route::get('/banks', [WalletController::class, 'getBanks']);
});

// Protected routes (authentication required)
Route::prefix('v1')->middleware(['auth:sanctum'])->group(function () {
    
    // Authentication
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/profile', [AuthController::class, 'profile']);
        Route::put('/profile', [AuthController::class, 'updateProfile']);
    });

    // Wallet management
    Route::prefix('wallet')->group(function () {
        Route::get('/', [WalletController::class, 'getWallet']);
        Route::get('/balance/refresh', [WalletController::class, 'refreshBalance']);
        
        // Transactions
        Route::get('/transactions', [WalletController::class, 'getTransactions']);
        Route::get('/transactions/{id}', [WalletController::class, 'getTransaction']);
        Route::get('/transactions/sync', [WalletController::class, 'syncTransactions']);
        
        // Statement
        Route::get('/statement', [WalletController::class, 'getStatement']);
        
        // Account validation
        Route::post('/validate-account', [WalletController::class, 'validateAccount']);
    });

    // Transfer operations
    Route::prefix('transfers')->group(function () {
        // Bank transfers
        Route::post('/bank', [TransferController::class, 'transferToBank']);
        Route::get('/bank/{reference}', [TransferController::class, 'getBankTransferStatus']);
        
        // Wallet to wallet transfers
        Route::post('/wallet', [TransferController::class, 'walletToWallet']);
        
        // Get transfer fees
        Route::post('/calculate-fee', [TransferController::class, 'calculateTransferFee']);
        
        // Transfer history
        Route::get('/history', [TransferController::class, 'getTransferHistory']);
    });

    // KYC operations
    Route::prefix('kyc')->group(function () {
        Route::get('/status', [KycController::class, 'getKycStatus']);
        Route::post('/upgrade', [KycController::class, 'upgradeKyc']);
        Route::post('/verify-bvn', [KycController::class, 'verifyBvn']);
        Route::post('/verify-nin', [KycController::class, 'verifyNin']);
    });

    // User operations
    Route::prefix('user')->group(function () {
        Route::put('/password', [AuthController::class, 'changePassword']);
        Route::put('/phone', [AuthController::class, 'changePhoneNumber']);
        Route::post('/send-otp', [AuthController::class, 'sendOtp']); // For phone change, etc.
    });

    // Notifications
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'getNotifications']);
        Route::put('/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::put('/read-all', [NotificationController::class, 'markAllAsRead']);
    });
});

// Admin routes (admin authentication required)
Route::prefix('v1/admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
    
    // User management
    Route::prefix('users')->group(function () {
        Route::get('/', [AdminController::class, 'getUsers']);
        Route::get('/{id}', [AdminController::class, 'getUser']);
        Route::put('/{id}/status', [AdminController::class, 'updateUserStatus']);
        Route::put('/{id}/kyc-level', [AdminController::class, 'updateKycLevel']);
    });

    // Transaction management
    Route::prefix('transactions')->group(function () {
        Route::get('/', [AdminController::class, 'getTransactions']);
        Route::get('/{id}', [AdminController::class, 'getTransaction']);
        Route::put('/{id}/status', [AdminController::class, 'updateTransactionStatus']);
    });

    // System settings
    Route::prefix('settings')->group(function () {
        Route::get('/', [AdminController::class, 'getSettings']);
        Route::put('/', [AdminController::class, 'updateSettings']);
        Route::post('/switch-environment', [AdminController::class, 'switchEnvironment']);
    });

    // Reports
    Route::prefix('reports')->group(function () {
        Route::get('/dashboard', [ReportController::class, 'getDashboard']);
        Route::get('/transactions', [ReportController::class, 'getTransactionReport']);
        Route::get('/users', [ReportController::class, 'getUserReport']);
        Route::get('/revenue', [ReportController::class, 'getRevenueReport']);
    });
});

// Development/Testing routes (only available in non-production)
if (!app()->environment('production')) {
    Route::prefix('v1/dev')->group(function () {
        
        // Test SMS
        Route::post('/test-sms', function (Request $request) {
            $smsService = app(\App\Services\SmsService::class);
            return $smsService->sendSms(
                $request->phone_number,
                $request->message ?? 'Test message from HIJAZ Banking'
            );
        });

        // Test Monnify connection
        Route::get('/test-monnify', function () {
            $monnifyService = app(\App\Services\MonnifyService::class);
            return $monnifyService->getBanks();
        });

        // Switch Monnify environment
        Route::post('/switch-monnify-env', function (Request $request) {
            $monnifyService = app(\App\Services\MonnifyService::class);
            $environment = $request->environment; // sandbox or live
            $monnifyService->switchEnvironment($environment);
            
            return response()->json([
                'success' => true,
                'message' => "Switched to {$environment} environment",
                'is_sandbox' => $monnifyService->isSandbox()
            ]);
        });

        // Generate test data
        Route::post('/generate-test-data', [TestController::class, 'generateTestData']);
        
        // Clear test data
        Route::delete('/clear-test-data', [TestController::class, 'clearTestData']);
    });
}

// Catch-all route for API not found
Route::fallback(function () {
    return response()->json([
        'success' => false,
        'message' => 'API endpoint not found',
        'error' => 'The requested API endpoint does not exist'
    ], 404);
});