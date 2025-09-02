<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class MonnifyService
{
    private $baseUrl;
    private $apiKey;
    private $secretKey;
    private $contractCode;
    private $environment;

    public function __construct()
    {
        $this->environment = config('services.monnify.environment', 'sandbox');
        $this->baseUrl = $this->environment === 'live' 
            ? config('services.monnify.base_url_live')
            : config('services.monnify.base_url_sandbox');
        
        $this->apiKey = $this->environment === 'live'
            ? config('services.monnify.api_key_live')
            : config('services.monnify.api_key_sandbox');
        
        $this->secretKey = $this->environment === 'live'
            ? config('services.monnify.secret_key_live')
            : config('services.monnify.secret_key_sandbox');
        
        $this->contractCode = $this->environment === 'live'
            ? config('services.monnify.contract_code_live')
            : config('services.monnify.contract_code_sandbox');
    }

    /**
     * Get access token from Monnify
     */
    private function getAccessToken()
    {
        $cacheKey = "monnify_access_token_{$this->environment}";
        
        return Cache::remember($cacheKey, 3000, function () {
            try {
                $credentials = base64_encode($this->apiKey . ':' . $this->secretKey);
                
                $response = Http::withHeaders([
                    'Authorization' => 'Basic ' . $credentials,
                    'Content-Type' => 'application/json'
                ])->post($this->baseUrl . '/api/v1/auth/login');

                if ($response->successful()) {
                    $data = $response->json();
                    if ($data['requestSuccessful']) {
                        return $data['responseBody']['accessToken'];
                    }
                }

                Log::error('Monnify Auth Failed', ['response' => $response->body()]);
                throw new \Exception('Failed to get Monnify access token');
            } catch (\Exception $e) {
                Log::error('Monnify Auth Exception', ['error' => $e->getMessage()]);
                throw $e;
            }
        });
    }

    /**
     * Make authenticated request to Monnify API
     */
    private function makeRequest($method, $endpoint, $data = [])
    {
        try {
            $token = $this->getAccessToken();
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ])->{$method}($this->baseUrl . $endpoint, $data);

            $responseData = $response->json();
            
            Log::info("Monnify {$method} Request", [
                'endpoint' => $endpoint,
                'request_data' => $data,
                'response' => $responseData,
                'status_code' => $response->status()
            ]);

            if (!$response->successful()) {
                throw new \Exception('Monnify API request failed: ' . $response->body());
            }

            return $responseData;
        } catch (\Exception $e) {
            Log::error('Monnify API Exception', [
                'method' => $method,
                'endpoint' => $endpoint,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Verify BVN with Monnify
     */
    public function verifyBvn($bvn, $dateOfBirth)
    {
        $data = [
            'bvn' => $bvn,
            'dateOfBirth' => $dateOfBirth // Format: DD-MM-YYYY
        ];

        return $this->makeRequest('post', '/api/v2/kyc/bvn/validate', $data);
    }

    /**
     * Verify NIN with Monnify
     */
    public function verifyNin($nin)
    {
        $data = [
            'nin' => $nin
        ];

        return $this->makeRequest('post', '/api/v2/kyc/nin/validate', $data);
    }

    /**
     * Create wallet for customer
     */
    public function createWallet($customerData)
    {
        $data = [
            'walletName' => $customerData['wallet_name'],
            'customerName' => $customerData['customer_name'],
            'bvnDetails' => [
                'bvn' => $customerData['bvn'],
                'bvnDateOfBirth' => $customerData['date_of_birth'] // Format: DD-MM-YYYY
            ],
            'customerEmail' => $customerData['email'],
            'customerPhoneNumber' => $customerData['phone_number']
        ];

        return $this->makeRequest('post', '/api/v2/wallets', $data);
    }

    /**
     * Get wallet details
     */
    public function getWalletDetails($walletId)
    {
        return $this->makeRequest('get', "/api/v2/wallets/{$walletId}");
    }

    /**
     * Get wallet balance
     */
    public function getWalletBalance($walletId)
    {
        return $this->makeRequest('get', "/api/v2/wallets/{$walletId}/balance");
    }

    /**
     * Get wallet transactions
     */
    public function getWalletTransactions($walletId, $page = 0, $size = 10)
    {
        $params = http_build_query([
            'page' => $page,
            'size' => $size
        ]);

        return $this->makeRequest('get', "/api/v2/wallets/{$walletId}/transactions?{$params}");
    }

    /**
     * Transfer from wallet
     */
    public function transferFromWallet($transferData)
    {
        $data = [
            'amount' => $transferData['amount'],
            'reference' => $transferData['reference'],
            'narration' => $transferData['narration'] ?? 'Wallet Transfer',
            'destinationBankCode' => $transferData['destination_bank_code'],
            'destinationAccountNumber' => $transferData['destination_account'],
            'currency' => 'NGN',
            'sourceAccountNumber' => $transferData['wallet_id'], // Internal wallet ID
        ];

        return $this->makeRequest('post', '/api/v2/transfers/single', $data);
    }

    /**
     * Get transfer status
     */
    public function getTransferStatus($reference)
    {
        return $this->makeRequest('get', "/api/v2/transfers/{$reference}");
    }

    /**
     * Get list of banks
     */
    public function getBanks()
    {
        $cacheKey = "monnify_banks_{$this->environment}";
        
        return Cache::remember($cacheKey, 3600, function () {
            return $this->makeRequest('get', '/api/v1/banks');
        });
    }

    /**
     * Validate account number
     */
    public function validateAccount($accountNumber, $bankCode)
    {
        $params = http_build_query([
            'accountNumber' => $accountNumber,
            'bankCode' => $bankCode
        ]);

        return $this->makeRequest('get', "/api/v1/nama/validate-account?{$params}");
    }

    /**
     * Get transaction fees
     */
    public function getTransactionFees($amount, $destinationBankCode = null)
    {
        // Implement Monnify fee calculation logic
        // This is a placeholder - check Monnify docs for actual fee structure
        $fee = 0;
        
        if ($amount <= 5000) {
            $fee = 10;
        } elseif ($amount <= 50000) {
            $fee = 25;
        } else {
            $fee = 50;
        }

        return [
            'fee' => $fee,
            'amount' => $amount,
            'total' => $amount + $fee
        ];
    }

    /**
     * Webhook signature validation
     */
    public function validateWebhookSignature($payload, $signature)
    {
        $computedSignature = hash_hmac('sha512', $payload, $this->secretKey);
        return hash_equals($computedSignature, $signature);
    }

    /**
     * Check if service is in sandbox mode
     */
    public function isSandbox()
    {
        return $this->environment === 'sandbox';
    }

    /**
     * Switch environment (for easy testing)
     */
    public function switchEnvironment($environment)
    {
        if (!in_array($environment, ['sandbox', 'live'])) {
            throw new \Exception('Invalid environment. Use "sandbox" or "live".');
        }

        $this->environment = $environment;
        $this->__construct(); // Reinitialize with new environment
        
        // Clear cached token
        Cache::forget("monnify_access_token_{$environment}");
        
        return $this;
    }
}