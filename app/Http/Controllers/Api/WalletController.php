<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MonnifyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WalletController extends Controller
{
    protected $monnifyService;

    public function __construct(MonnifyService $monnifyService)
    {
        $this->monnifyService = $monnifyService;
    }

    /**
     * Get wallet details and balance
     */
    public function getWallet(Request $request)
    {
        try {
            $user = $request->user();
            $wallet = $user->wallet;

            if (!$wallet) {
                return response()->json([
                    'success' => false,
                    'message' => 'Wallet not found'
                ], 404);
            }

            // Sync balance from Monnify
            try {
                $balanceResponse = $this->monnifyService->getWalletBalance($wallet->wallet_id);
                if ($balanceResponse['requestSuccessful']) {
                    $balance = $balanceResponse['responseBody']['availableBalance'] ?? 0;
                    $wallet->syncBalanceFromMonnify($balance);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to sync balance from Monnify', [
                    'wallet_id' => $wallet->wallet_id,
                    'error' => $e->getMessage()
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => $statementData
            ]);

        } catch (\Exception $e) {
            Log::error('Get Statement Failed', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate statement'
            ], 500);
        }
    }

    /**
     * Get list of Nigerian banks
     */
    public function getBanks(Request $request)
    {
        try {
            $banks = $this->monnifyService->getBanks();

            if (!$banks['requestSuccessful']) {
                throw new \Exception('Failed to get banks list');
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'banks' => $banks['responseBody']
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Get Banks Failed', [
                'error' => $e->getMessage()
            ]);

            // Fallback to common Nigerian banks
            $commonBanks = [
                ['name' => 'Access Bank', 'code' => '044'],
                ['name' => 'Guaranty Trust Bank', 'code' => '058'],
                ['name' => 'United Bank for Africa', 'code' => '033'],
                ['name' => 'Zenith Bank', 'code' => '057'],
                ['name' => 'First Bank of Nigeria', 'code' => '011'],
                ['name' => 'Stanbic IBTC Bank', 'code' => '221'],
                ['name' => 'Standard Chartered Bank', 'code' => '068'],
                ['name' => 'Union Bank of Nigeria', 'code' => '032'],
                ['name' => 'Polaris Bank', 'code' => '076'],
                ['name' => 'Fidelity Bank', 'code' => '070'],
                ['name' => 'Moniepoint Microfinance Bank', 'code' => '50515']
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'banks' => $commonBanks
                ],
                'message' => 'Using cached banks list'
            ]);
        }
    }

    /**
     * Validate account number
     */
    public function validateAccount(Request $request)
    {
        try {
            $validator = \Validator::make($request->all(), [
                'account_number' => 'required|string|digits:10',
                'bank_code' => 'required|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid input data',
                    'errors' => $validator->errors()
                ], 422);
            }

            $validation = $this->monnifyService->validateAccount(
                $request->account_number,
                $request->bank_code
            );

            if (!$validation['requestSuccessful']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Account validation failed'
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'account_number' => $request->account_number,
                    'account_name' => $validation['responseBody']['accountName'],
                    'bank_code' => $request->bank_code
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Account Validation Failed', [
                'account_number' => $request->account_number,
                'bank_code' => $request->bank_code,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Account validation failed'
            ], 500);
        }
    }

    /**
     * Get wallet balance from Monnify
     */
    public function refreshBalance(Request $request)
    {
        try {
            $user = $request->user();
            $wallet = $user->wallet;

            if (!$wallet) {
                return response()->json([
                    'success' => false,
                    'message' => 'Wallet not found'
                ], 404);
            }

            // Get fresh balance from Monnify
            $balanceResponse = $this->monnifyService->getWalletBalance($wallet->wallet_id);

            if (!$balanceResponse['requestSuccessful']) {
                throw new \Exception('Failed to get balance from Monnify');
            }

            $balance = $balanceResponse['responseBody']['availableBalance'] ?? 0;
            $wallet->syncBalanceFromMonnify($balance);

            return response()->json([
                'success' => true,
                'data' => [
                    'balance' => [
                        'available_balance' => $wallet->available_balance,
                        'formatted_balance' => $wallet->formatted_balance,
                        'last_updated' => now()->toISOString()
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Refresh Balance Failed', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to refresh balance'
            ], 500);
        }
    }

    /**
     * Sync transactions from Monnify
     */
    public function syncTransactions(Request $request)
    {
        try {
            $user = $request->user();
            $wallet = $user->wallet;

            if (!$wallet) {
                return response()->json([
                    'success' => false,
                    'message' => 'Wallet not found'
                ], 404);
            }

            $page = $request->get('page', 0);
            $size = $request->get('size', 50);

            // Get transactions from Monnify
            $transactionsResponse = $this->monnifyService->getWalletTransactions(
                $wallet->wallet_id, 
                $page, 
                $size
            );

            if (!$transactionsResponse['requestSuccessful']) {
                throw new \Exception('Failed to get transactions from Monnify');
            }

            $monnifyTransactions = $transactionsResponse['responseBody']['content'] ?? [];
            $syncedCount = 0;

            foreach ($monnifyTransactions as $monnifyTxn) {
                // Check if transaction already exists
                $existingTxn = $wallet->transactions()
                                     ->where('external_reference', $monnifyTxn['transactionReference'])
                                     ->first();

                if (!$existingTxn) {
                    // Create new transaction record
                    $wallet->transactions()->create([
                        'user_id' => $user->id,
                        'reference' => $monnifyTxn['transactionReference'],
                        'external_reference' => $monnifyTxn['transactionReference'],
                        'type' => strtolower($monnifyTxn['transactionType']) === 'credit' ? 'credit' : 'debit',
                        'category' => $this->mapTransactionCategory($monnifyTxn),
                        'amount' => $monnifyTxn['amount'],
                        'balance_before' => $monnifyTxn['balanceBefore'] ?? 0,
                        'balance_after' => $monnifyTxn['balanceAfter'] ?? 0,
                        'status' => $this->mapTransactionStatus($monnifyTxn['status']),
                        'narration' => $monnifyTxn['narration'] ?? 'Monnify Transaction',
                        'provider_response' => $monnifyTxn,
                        'processed_at' => \Carbon\Carbon::parse($monnifyTxn['transactionDate']),
                        'created_at' => \Carbon\Carbon::parse($monnifyTxn['transactionDate'])
                    ]);
                    
                    $syncedCount++;
                }
            }

            // Update wallet balance
            if (!empty($monnifyTransactions)) {
                $latestBalance = end($monnifyTransactions)['balanceAfter'] ?? $wallet->available_balance;
                $wallet->syncBalanceFromMonnify($latestBalance);
            }

            return response()->json([
                'success' => true,
                'message' => "Synced {$syncedCount} new transactions",
                'data' => [
                    'synced_count' => $syncedCount,
                    'total_fetched' => count($monnifyTransactions),
                    'current_balance' => $wallet->formatted_balance
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Sync Transactions Failed', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to sync transactions'
            ], 500);
        }
    }

    /**
     * Helper methods
     */
    private function mapTransactionCategory($monnifyTransaction)
    {
        $narration = strtolower($monnifyTransaction['narration'] ?? '');
        
        if (str_contains($narration, 'transfer')) {
            return 'transfer';
        } elseif (str_contains($narration, 'deposit')) {
            return 'deposit';
        } elseif (str_contains($narration, 'withdrawal')) {
            return 'withdrawal';
        } elseif (str_contains($narration, 'fee')) {
            return 'fee';
        } else {
            return 'deposit'; // Default
        }
    }

    private function mapTransactionStatus($monnifyStatus)
    {
        switch (strtolower($monnifyStatus)) {
            case 'successful':
            case 'success':
                return 'successful';
            case 'pending':
                return 'pending';
            case 'failed':
            case 'failure':
                return 'failed';
            default:
                return 'pending';
        }
    }
} true,
                'data' => [
                    'wallet' => [
                        'id' => $wallet->id,
                        'account_number' => $wallet->account_number,
                        'account_name' => $wallet->account_name,
                        'bank_name' => $wallet->bank_name,
                        'bank_code' => $wallet->bank_code,
                        'available_balance' => $wallet->available_balance,
                        'formatted_balance' => $wallet->formatted_balance,
                        'daily_limit' => $wallet->daily_limit,
                        'monthly_limit' => $wallet->monthly_limit,
                        'remaining_daily_limit' => $wallet->remaining_daily_limit,
                        'remaining_monthly_limit' => $wallet->remaining_monthly_limit,
                        'status' => $wallet->status,
                        'created_at' => $wallet->created_at->toISOString()
                    ],
                    'user_kyc' => [
                        'level' => $user->kyc_level,
                        'limits' => $user->getKycLimits()
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Get Wallet Failed', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get wallet details'
            ], 500);
        }
    }

    /**
     * Get wallet transactions
     */
    public function getTransactions(Request $request)
    {
        try {
            $user = $request->user();
            $wallet = $user->wallet;

            if (!$wallet) {
                return response()->json([
                    'success' => false,
                    'message' => 'Wallet not found'
                ], 404);
            }

            $perPage = $request->get('per_page', 20);
            $page = $request->get('page', 1);
            $type = $request->get('type'); // credit, debit
            $status = $request->get('status'); // successful, pending, failed
            $startDate = $request->get('start_date');
            $endDate = $request->get('end_date');

            $query = $wallet->transactions()->with('user')->orderBy('created_at', 'desc');

            // Apply filters
            if ($type) {
                $query->where('type', $type);
            }

            if ($status) {
                $query->where('status', $status);
            }

            if ($startDate) {
                $query->whereDate('created_at', '>=', $startDate);
            }

            if ($endDate) {
                $query->whereDate('created_at', '<=', $endDate);
            }

            $transactions = $query->paginate($perPage, ['*'], 'page', $page);

            // Get transaction summary
            $summary = [
                'total_credits' => $wallet->transactions()->successful()->credits()->sum('amount'),
                'total_debits' => $wallet->transactions()->successful()->debits()->sum('amount'),
                'total_transactions' => $wallet->transactions()->successful()->count(),
                'pending_transactions' => $wallet->transactions()->pending()->count()
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'transactions' => $transactions->items(),
                    'pagination' => [
                        'current_page' => $transactions->currentPage(),
                        'last_page' => $transactions->lastPage(),
                        'per_page' => $transactions->perPage(),
                        'total' => $transactions->total(),
                        'has_more_pages' => $transactions->hasMorePages()
                    ],
                    'summary' => $summary
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Get Transactions Failed', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get transactions'
            ], 500);
        }
    }

    /**
     * Get single transaction
     */
    public function getTransaction(Request $request, $transactionId)
    {
        try {
            $user = $request->user();
            $wallet = $user->wallet;

            if (!$wallet) {
                return response()->json([
                    'success' => false,
                    'message' => 'Wallet not found'
                ], 404);
            }

            $transaction = $wallet->transactions()->where('id', $transactionId)->first();

            if (!$transaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'transaction' => $transaction
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Get Transaction Failed', [
                'user_id' => $request->user()->id,
                'transaction_id' => $transactionId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get transaction'
            ], 500);
        }
    }

    /**
     * Get account statement
     */
    public function getStatement(Request $request)
    {
        try {
            $validator = \Validator::make($request->all(), [
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'format' => 'sometimes|in:json,pdf'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid input data',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = $request->user();
            $wallet = $user->wallet;

            if (!$wallet) {
                return response()->json([
                    'success' => false,
                    'message' => 'Wallet not found'
                ], 404);
            }

            $startDate = $request->start_date;
            $endDate = $request->end_date;
            $format = $request->get('format', 'json');

            // Get transactions for the period
            $transactions = $wallet->transactions()
                                  ->whereBetween('created_at', [$startDate, $endDate])
                                  ->orderBy('created_at', 'desc')
                                  ->get();

            // Calculate summary
            $openingBalance = $wallet->transactions()
                                    ->where('created_at', '<', $startDate)
                                    ->successful()
                                    ->latest()
                                    ->first()?->balance_after ?? 0;

            $closingBalance = $transactions->where('status', 'successful')
                                          ->last()?->balance_after ?? $openingBalance;

            $totalCredits = $transactions->where('type', 'credit')
                                        ->where('status', 'successful')
                                        ->sum('amount');

            $totalDebits = $transactions->where('type', 'debit')
                                       ->where('status', 'successful')
                                       ->sum('amount');

            $statementData = [
                'account_details' => [
                    'account_number' => $wallet->account_number,
                    'account_name' => $wallet->account_name,
                    'bank_name' => $wallet->bank_name,
                    'statement_period' => [
                        'start_date' => $startDate,
                        'end_date' => $endDate
                    ]
                ],
                'balance_summary' => [
                    'opening_balance' => $openingBalance,
                    'closing_balance' => $closingBalance,
                    'total_credits' => $totalCredits,
                    'total_debits' => $totalDebits,
                    'transaction_count' => $transactions->count()
                ],
                'transactions' => $transactions->map(function ($transaction) {
                    return [
                        'date' => $transaction->created_at->format('Y-m-d H:i:s'),
                        'reference' => $transaction->reference,
                        'type' => $transaction->type,
                        'amount' => $transaction->amount,
                        'balance' => $transaction->balance_after,
                        'narration' => $transaction->narration,
                        'status' => $transaction->status
                    ];
                })
            ];

            if ($format === 'pdf') {
                // TODO: Generate PDF statement
                // For now, return JSON with PDF generation note
                return response()->json([
                    'success' => true,
                    'message' => 'PDF generation will be implemented',
                    'data' => $statementData
                ]);
            }

            return response()->json([
                'success' =>