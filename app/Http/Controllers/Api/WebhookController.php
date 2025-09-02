<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Models\Transaction;
use App\Services\MonnifyService;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class WebhookController extends Controller
{
    protected $monnifyService;
    protected $smsService;

    public function __construct(MonnifyService $monnifyService, SmsService $smsService)
    {
        $this->monnifyService = $monnifyService;
        $this->smsService = $smsService;
    }

    /**
     * Handle Monnify transaction webhooks
     */
    public function monnifyTransaction(Request $request)
    {
        try {
            // Validate webhook signature
            $signature = $request->header('monnify-signature');
            $payload = $request->getContent();

            if (!$this->monnifyService->validateWebhookSignature($payload, $signature)) {
                Log::warning('Invalid Monnify webhook signature', [
                    'signature' => $signature,
                    'payload' => $payload
                ]);
                
                return response()->json(['error' => 'Invalid signature'], 401);
            }

            $data = $request->all();
            
            Log::info('Monnify Transaction Webhook Received', $data);

            $eventData = $data['eventData'] ?? $data;
            $transactionReference = $eventData['transactionReference'] ?? null;
            $accountNumber = $eventData['destinationAccountNumber'] ?? $eventData['accountNumber'] ?? null;

            if (!$transactionReference || !$accountNumber) {
                Log::error('Missing required fields in webhook', $data);
                return response()->json(['error' => 'Missing required fields'], 400);
            }

            // Find the wallet by account number
            $wallet = Wallet::where('account_number', $accountNumber)->first();

            if (!$wallet) {
                Log::warning('Wallet not found for webhook', [
                    'account_number' => $accountNumber,
                    'transaction_reference' => $transactionReference
                ]);
                return response()->json(['error' => 'Wallet not found'], 404);
            }

            return DB::transaction(function () use ($eventData, $wallet, $transactionReference) {
                // Check if transaction already exists
                $existingTransaction = Transaction::where('external_reference', $transactionReference)->first();
                
                if ($existingTransaction) {
                    Log::info('Transaction already processed', [
                        'reference' => $transactionReference,
                        'existing_id' => $existingTransaction->id
                    ]);
                    return response()->json(['message' => 'Transaction already processed'], 200);
                }

                $amount = $eventData['amountPaid'] ?? $eventData['amount'] ?? 0;
                $fee = $eventData['fee'] ?? 0;
                $netAmount = $amount - $fee;

                // Create transaction record
                $transaction = Transaction::create([
                    'user_id' => $wallet->user_id,
                    'wallet_id' => $wallet->id,
                    'reference' => Transaction::generateReference(),
                    'external_reference' => $transactionReference,
                    'type' => 'credit',
                    'category' => 'deposit',
                    'amount' => $netAmount,
                    'fee' => $fee,
                    'balance_before' => $wallet->available_balance,
                    'balance_after' => $wallet->available_balance + $netAmount,
                    'status' => 'successful',
                    'narration' => $eventData['paymentDescription'] ?? 'Account Credit',
                    'channel' => 'webhook',
                    'provider_response' => $eventData,
                    'processed_at' => now()
                ]);

                // Update wallet balance
                $wallet->credit($netAmount, $transaction->narration);

                // Send SMS notification
                try {
                    $this->smsService->sendTransactionAlert(
                        $wallet->user->phone_number,
                        [
                            'type' => 'credit',
                            'amount' => number_format($netAmount, 2),
                            'balance' => number_format($wallet->available_balance, 2),
                            'reference' => $transaction->reference
                        ]
                    );
                } catch (\Exception $e) {
                    Log::error('Failed to send transaction alert SMS', [
                        'transaction_id' => $transaction->id,
                        'error' => $e->getMessage()
                    ]);
                }

                Log::info('Transaction processed successfully', [
                    'transaction_id' => $transaction->id,
                    'wallet_id' => $wallet->id,
                    'amount' => $netAmount,
                    'new_balance' => $wallet->available_balance
                ]);

                return response()->json(['message' => 'Transaction processed successfully'], 200);
            });

        } catch (\Exception $e) {
            Log::error('Webhook processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $request->all()
            ]);

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }

    /**
     * Handle Monnify collection webhooks
     */
    public function monnifyCollection(Request $request)
    {
        try {
            // Validate webhook signature
            $signature = $request->header('monnify-signature');
            $payload = $request->getContent();

            if (!$this->monnifyService->validateWebhookSignature($payload, $signature)) {
                return response()->json(['error' => 'Invalid signature'], 401);
            }

            $data = $request->all();
            Log::info('Monnify Collection Webhook Received', $data);

            $eventData = $data['eventData'] ?? $data;
            $paymentReference = $eventData['paymentReference'] ?? null;
            $accountNumber = $eventData['accountNumber'] ?? null;

            if (!$paymentReference || !$accountNumber) {
                return response()->json(['error' => 'Missing required fields'], 400);
            }

            // Find wallet
            $wallet = Wallet::where('account_number', $accountNumber)->first();

            if (!$wallet) {
                return response()->json(['error' => 'Wallet not found'], 404);
            }

            // Process collection (similar to transaction processing)
            return $this->processCollection($eventData, $wallet);

        } catch (\Exception $e) {
            Log::error('Collection webhook processing failed', [
                'error' => $e->getMessage(),
                'payload' => $request->all()
            ]);

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }

    /**
     * Handle Monnify transfer webhooks
     */
    public function monnifyTransfer(Request $request)
    {
        try {
            // Validate webhook signature
            $signature = $request->header('monnify-signature');
            $payload = $request->getContent();

            if (!$this->monnifyService->validateWebhookSignature($payload, $signature)) {
                return response()->json(['error' => 'Invalid signature'], 401);
            }

            $data = $request->all();
            Log::info('Monnify Transfer Webhook Received', $data);

            $eventData = $data['eventData'] ?? $data;
            $transferReference = $eventData['reference'] ?? null;

            if (!$transferReference) {
                return response()->json(['error' => 'Missing transfer reference'], 400);
            }

            // Find transaction by external reference
            $transaction = Transaction::where('external_reference', $transferReference)->first();

            if (!$transaction) {
                Log::warning('Transaction not found for transfer webhook', [
                    'reference' => $transferReference
                ]);
                return response()->json(['error' => 'Transaction not found'], 404);
            }

            // Update transaction status based on webhook data
            $status = strtolower($eventData['status'] ?? 'failed');
            $statusMessage = $eventData['statusMessage'] ?? null;

            switch ($status) {
                case 'successful':
                case 'success':
                    $transaction->markAsSuccessful($statusMessage);
                    
                    // Send success SMS
                    try {
                        $this->smsService->sendTransactionAlert(
                            $transaction->user->phone_number,
                            [
                                'type' => 'debit',
                                'amount' => number_format($transaction->amount, 2),
                                'balance' => number_format($transaction->wallet->available_balance, 2),
                                'reference' => $transaction->reference,
                                'destination' => $transaction->destination_account
                            ]
                        );
                    } catch (\Exception $e) {
                        Log::error('Failed to send transfer success SMS', [
                            'transaction_id' => $transaction->id,
                            'error' => $e->getMessage()
                        ]);
                    }
                    break;

                case 'failed':
                case 'failure':
                    $transaction->markAsFailed($statusMessage);
                    
                    // Reverse the debit if it was already processed
                    if ($transaction->type === 'debit' && $transaction->balance_before > 0) {
                        $transaction->wallet->credit(
                            $transaction->amount,
                            "Reversal for failed transfer - {$transaction->reference}"
                        );
                    }
                    
                    // Send failure SMS
                    try {
                        $message = "HIJAZ Alert: Your transfer of ₦" . number_format($transaction->amount, 2) . 
                                  " failed and has been reversed. Ref: {$transaction->reference}";
                        
                        $this->smsService->sendSms($transaction->user->phone_number, $message);
                    } catch (\Exception $e) {
                        Log::error('Failed to send transfer failure SMS', [
                            'transaction_id' => $transaction->id,
                            'error' => $e->getMessage()
                        ]);
                    }
                    break;

                default:
                    // Keep as pending or processing
                    $transaction->status = 'processing';
                    $transaction->status_message = $statusMessage;
                    $transaction->save();
            }

            Log::info('Transfer webhook processed', [
                'transaction_id' => $transaction->id,
                'status' => $status,
                'reference' => $transferReference
            ]);

            return response()->json(['message' => 'Transfer webhook processed successfully'], 200);

        } catch (\Exception $e) {
            Log::error('Transfer webhook processing failed', [
                'error' => $e->getMessage(),
                'payload' => $request->all()
            ]);

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }

    /**
     * Process collection webhook
     */
    private function processCollection($eventData, $wallet)
    {
        return DB::transaction(function () use ($eventData, $wallet) {
            $paymentReference = $eventData['paymentReference'];
            
            // Check if already processed
            $existingTransaction = Transaction::where('external_reference', $paymentReference)->first();
            
            if ($existingTransaction) {
                return response()->json(['message' => 'Collection already processed'], 200);
            }

            $amount = $eventData['amountPaid'] ?? 0;
            $fee = $eventData['settlementAmount'] - $amount; // Calculate fee
            $netAmount = $amount - abs($fee);

            // Create transaction
            $transaction = Transaction::create([
                'user_id' => $wallet->user_id,
                'wallet_id' => $wallet->id,
                'reference' => Transaction::generateReference(),
                'external_reference' => $paymentReference,
                'type' => 'credit',
                'category' => 'deposit',
                'amount' => $netAmount,
                'fee' => abs($fee),
                'balance_before' => $wallet->available_balance,
                'balance_after' => $wallet->available_balance + $netAmount,
                'status' => 'successful',
                'narration' => $eventData['paymentDescription'] ?? 'Collection Credit',
                'channel' => 'collection',
                'provider_response' => $eventData,
                'processed_at' => now()
            ]);

            // Update wallet
            $wallet->credit($netAmount, $transaction->narration);

            // Send notification
            try {
                $this->smsService->sendTransactionAlert(
                    $wallet->user->phone_number,
                    [
                        'type' => 'credit',
                        'amount' => number_format($netAmount, 2),
                        'balance' => number_format($wallet->available_balance, 2),
                        'reference' => $transaction->reference
                    ]
                );
            } catch (\Exception $e) {
                Log::error('Failed to send collection alert SMS', [
                    'transaction_id' => $transaction->id,
                    'error' => $e->getMessage()
                ]);
            }

            return response()->json(['message' => 'Collection processed successfully'], 200);
        });
    }

    /**
     * Handle generic webhook events
     */
    public function handleGenericWebhook(Request $request)
    {
        try {
            $data = $request->all();
            $eventType = $data['eventType'] ?? 'unknown';

            Log::info("Generic webhook received: {$eventType}", $data);

            // Route to specific handlers based on event type
            switch ($eventType) {
                case 'SUCCESSFUL_TRANSACTION':
                    return $this->monnifyTransaction($request);
                
                case 'SUCCESSFUL_COLLECTION':
                    return $this->monnifyCollection($request);
                
                case 'TRANSFER_STATUS_UPDATE':
                    return $this->monnifyTransfer($request);
                
                default:
                    Log::info("Unhandled webhook event type: {$eventType}", $data);
                    return response()->json(['message' => 'Event received'], 200);
            }

        } catch (\Exception $e) {
            Log::error('Generic webhook processing failed', [
                'error' => $e->getMessage(),
                'payload' => $request->all()
            ]);

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }
}