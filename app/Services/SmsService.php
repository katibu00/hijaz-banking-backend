<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    private $provider;
    private $config;

    public function __construct()
    {
        $this->provider = config('services.sms.provider', 'kudisms');
        $this->config = config('services.sms.providers.' . $this->provider);
    }

    /**
     * Send SMS using the configured provider
     */
    public function sendSms($phoneNumber, $message, $metadata = [])
    {
        try {
            $result = $this->sendViaProvider($phoneNumber, $message, $metadata);
            
            Log::info('SMS Sent Successfully', [
                'provider' => $this->provider,
                'phone' => $this->maskPhoneNumber($phoneNumber),
                'message_length' => strlen($message),
                'metadata' => $metadata,
                'result' => $result
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('SMS Send Failed', [
                'provider' => $this->provider,
                'phone' => $this->maskPhoneNumber($phoneNumber),
                'error' => $e->getMessage(),
                'metadata' => $metadata
            ]);
            
            throw $e;
        }
    }

    /**
     * Send OTP SMS
     */
    public function sendOtp($phoneNumber, $otpCode, $appName = 'HIJAZ')
    {
        $message = "Your {$appName} OTP is: {$otpCode}. Valid for 5 minutes. Do not share this code.";
        
        return $this->sendSms($phoneNumber, $message, [
            'type' => 'otp',
            'otp_code' => $otpCode
        ]);
    }

    /**
     * Send welcome SMS
     */
    public function sendWelcomeSms($phoneNumber, $customerName, $accountNumber)
    {
        $message = "Welcome to HIJAZ Banking, {$customerName}! Your account {$accountNumber} is now active. Start banking with us today!";
        
        return $this->sendSms($phoneNumber, $message, [
            'type' => 'welcome',
            'customer_name' => $customerName,
            'account_number' => $accountNumber
        ]);
    }

    /**
     * Send transaction alert
     */
    public function sendTransactionAlert($phoneNumber, $transactionData)
    {
        $type = $transactionData['type'] === 'credit' ? 'Credited' : 'Debited';
        $message = "HIJAZ Alert: Your account has been {$type} with ₦{$transactionData['amount']}. Balance: ₦{$transactionData['balance']}. Ref: {$transactionData['reference']}";
        
        return $this->sendSms($phoneNumber, $message, [
            'type' => 'transaction_alert',
            'transaction_data' => $transactionData
        ]);
    }

    /**
     * Send via specific provider
     */
    private function sendViaProvider($phoneNumber, $message, $metadata = [])
    {
        switch ($this->provider) {
            case 'kudisms':
                return $this->sendViaKudisms($phoneNumber, $message, $metadata);
            
            case 'termii':
                return $this->sendViaTermii($phoneNumber, $message, $metadata);
            
            case 'twilio':
                return $this->sendViaTwilio($phoneNumber, $message, $metadata);
                
            default:
                throw new \Exception("Unsupported SMS provider: {$this->provider}");
        }
    }

    /**
     * Send SMS via KudismS
     */
    private function sendViaKudisms($phoneNumber, $message, $metadata = [])
    {
        $phone = $this->formatPhoneNumber($phoneNumber);
        
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->config['api_token'],
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ])->post($this->config['api_url'] . '/sms', [
            'sender' => $this->config['sender_id'],
            'message' => $message,
            'recipients' => $phone
        ]);

        if (!$response->successful()) {
            throw new \Exception('KudismS API request failed: ' . $response->body());
        }

        $result = $response->json();
        
        if (!isset($result['status']) || $result['status'] !== 'success') {
            throw new \Exception('KudismS SMS failed: ' . ($result['message'] ?? 'Unknown error'));
        }

        return [
            'provider' => 'kudisms',
            'message_id' => $result['data']['message_id'] ?? null,
            'status' => 'sent',
            'response' => $result
        ];
    }

    /**
     * Send SMS via Termii
     */
    private function sendViaTermii($phoneNumber, $message, $metadata = [])
    {
        $phone = $this->formatPhoneNumber($phoneNumber);
        
        $response = Http::post($this->config['api_url'] . '/sms/send', [
            'api_key' => $this->config['api_key'],
            'to' => $phone,
            'from' => $this->config['sender_id'],
            'sms' => $message,
            'type' => 'plain',
            'channel' => 'generic'
        ]);

        if (!$response->successful()) {
            throw new \Exception('Termii API request failed: ' . $response->body());
        }

        $result = $response->json();

        return [
            'provider' => 'termii',
            'message_id' => $result['message_id'] ?? null,
            'status' => 'sent',
            'response' => $result
        ];
    }

    /**
     * Send SMS via Twilio
     */
    private function sendViaTwilio($phoneNumber, $message, $metadata = [])
    {
        $phone = $this->formatPhoneNumber($phoneNumber, 'international');
        
        $response = Http::withBasicAuth(
            $this->config['account_sid'], 
            $this->config['auth_token']
        )->asForm()->post(
            "https://api.twilio.com/2010-04-01/Accounts/{$this->config['account_sid']}/Messages.json",
            [
                'From' => $this->config['phone_number'],
                'To' => $phone,
                'Body' => $message
            ]
        );

        if (!$response->successful()) {
            throw new \Exception('Twilio API request failed: ' . $response->body());
        }

        $result = $response->json();

        return [
            'provider' => 'twilio',
            'message_id' => $result['sid'] ?? null,
            'status' => 'sent',
            'response' => $result
        ];
    }

    /**
     * Format phone number for Nigeria
     */
    private function formatPhoneNumber($phoneNumber, $format = 'local')
    {
        // Remove any non-digit characters
        $phone = preg_replace('/[^0-9]/', '', $phoneNumber);
        
        // Handle Nigerian numbers
        if (strlen($phone) === 11 && substr($phone, 0, 1) === '0') {
            // Remove leading zero
            $phone = substr($phone, 1);
        }
        
        if (strlen($phone) === 10) {
            if ($format === 'international') {
                return '+234' . $phone;
            } else {
                return '234' . $phone;
            }
        }
        
        // If already in international format
        if (substr($phone, 0, 3) === '234' && strlen($phone) === 13) {
            if ($format === 'international') {
                return '+' . $phone;
            }
            return $phone;
        }
        
        throw new \Exception('Invalid Nigerian phone number format: ' . $phoneNumber);
    }

    /**
     * Mask phone number for logging
     */
    private function maskPhoneNumber($phoneNumber)
    {
        $phone = preg_replace('/[^0-9]/', '', $phoneNumber);
        if (strlen($phone) >= 7) {
            return substr($phone, 0, 3) . '****' . substr($phone, -3);
        }
        return '***masked***';
    }

    /**
     * Change SMS provider dynamically
     */
    public function switchProvider($provider, $config = null)
    {
        $supportedProviders = ['kudisms', 'termii', 'twilio'];
        
        if (!in_array($provider, $supportedProviders)) {
            throw new \Exception("Unsupported SMS provider: {$provider}");
        }
        
        $this->provider = $provider;
        
        if ($config) {
            $this->config = $config;
        } else {
            $this->config = config('services.sms.providers.' . $provider);
        }
        
        return $this;
    }

    /**
     * Get current provider
     */
    public function getCurrentProvider()
    {
        return $this->provider;
    }

    /**
     * Validate phone number
     */
    public function validatePhoneNumber($phoneNumber)
    {
        try {
            $this->formatPhoneNumber($phoneNumber);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}