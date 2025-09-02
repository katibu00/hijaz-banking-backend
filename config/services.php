<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Monnify Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Monnify payment gateway integration.
    | Supports both sandbox and live environments with easy switching.
    |
    */

    'monnify' => [
        'environment' => env('MONNIFY_ENVIRONMENT', 'sandbox'),
        
        // Sandbox Configuration
        'base_url_sandbox' => env('MONNIFY_BASE_URL_SANDBOX', 'https://sandbox.monnify.com'),
        'api_key_sandbox' => env('MONNIFY_API_KEY_SANDBOX'),
        'secret_key_sandbox' => env('MONNIFY_SECRET_KEY_SANDBOX'),
        'contract_code_sandbox' => env('MONNIFY_CONTRACT_CODE_SANDBOX'),
        
        // Live Configuration
        'base_url_live' => env('MONNIFY_BASE_URL_LIVE', 'https://api.monnify.com'),
        'api_key_live' => env('MONNIFY_API_KEY_LIVE'),
        'secret_key_live' => env('MONNIFY_SECRET_KEY_LIVE'),
        'contract_code_live' => env('MONNIFY_CONTRACT_CODE_LIVE'),

        // Additional settings
        'webhook_signature_header' => 'monnify-signature',
        'timeout' => 30, // API timeout in seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | SMS Service Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for SMS service providers.
    | Supports multiple providers with easy switching.
    |
    */

    'sms' => [
        'provider' => env('SMS_PROVIDER', 'kudisms'),
        
        'providers' => [
            'kudisms' => [
                'api_url' => env('KUDISMS_API_URL', 'https://my.kudisms.net/api'),
                'api_token' => env('KUDISMS_API_TOKEN'),
                'sender_id' => env('KUDISMS_SENDER_ID', 'HIJAZ'),
            ],
            
            'termii' => [
                'api_url' => env('TERMII_API_URL', 'https://api.ng.termii.com/api'),
                'api_key' => env('TERMII_API_KEY'),
                'sender_id' => env('TERMII_SENDER_ID', 'HIJAZ'),
            ],
            
            'twilio' => [
                'account_sid' => env('TWILIO_ACCOUNT_SID'),
                'auth_token' => env('TWILIO_AUTH_TOKEN'),
                'phone_number' => env('TWILIO_PHONE_NUMBER'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | BVN/NIN Verification Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for identity verification services.
    |
    */

    'verification' => [
        'provider' => env('VERIFICATION_PROVIDER', 'monnify'),
        'cache_duration' => 3600, // Cache verification results for 1 hour
    ],

    /*
    |--------------------------------------------------------------------------
    | KYC Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Know Your Customer (KYC) levels and limits.
    | Based on CBN regulations for fintech apps.
    |
    */

    'kyc' => [
        'tier_1_limit' => env('KYC_TIER_1_LIMIT', 50000),
        'tier_2_limit' => env('KYC_TIER_2_LIMIT', 200000),
        'tier_3_limit' => env('KYC_TIER_3_LIMIT', 5000000),
        
        'daily_limits' => [
            'tier_0' => 0,
            'tier_1' => 50000,
            'tier_2' => 200000,
            'tier_3' => 5000000,
        ],
        
        'monthly_limits' => [
            'tier_0' => 0,
            'tier_1' => 200000,
            'tier_2' => 500000,
            'tier_3' => 5000000,
        ],

        'requirements' => [
            'tier_1' => ['bvn_or_nin', 'phone_verification'],
            'tier_2' => ['bvn_or_nin', 'phone_verification', 'address_verification', 'id_document'],
            'tier_3' => ['bvn_or_nin', 'phone_verification', 'address_verification', 'id_document', 'utility_bill'],
        ]
    ],

    /*
    |--------------------------------------------------------------------------
    | Transaction Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for transaction processing and fees.
    |
    */

    'transactions' => [
        'reference_prefix' => env('TRANSACTION_PREFIX', 'HJZ'),
        'timeout_minutes' => 15, // Transaction timeout
        
        'fees' => [
            'bank_transfer' => [
                'flat_fee' => 10, // Minimum fee
                'percentage' => 0.5, // Percentage fee
                'cap' => 100, // Maximum fee
                'free_limit' => 5000, // Free transfers below this amount
            ],
            
            'wallet_to_wallet' => [
                'fee' => 0, // Free wallet to wallet transfers
            ]
        ],
        
        'limits' => [
            'min_transfer' => 100,
            'max_transfer_tier_1' => 50000,
            'max_transfer_tier_2' => 200000,
            'max_transfer_tier_3' => 5000000,
        ]
    ],

    /*
    |--------------------------------------------------------------------------
    | Application Settings
    |--------------------------------------------------------------------------
    |
    | General application settings and configurations.
    |
    */

    'app' => [
        'support_phone' => env('SUPPORT_PHONE', '+2348012345678'),
        'support_email' => env('SUPPORT_EMAIL', 'support@hijazbanking.com'),
        'company_name' => env('COMPANY_NAME', 'HIJAZ Banking'),
        'company_address' => env('COMPANY_ADDRESS', 'Lagos, Nigeria'),
        
        'session_timeout' => 30, // minutes
        'otp_expiry' => 5, // minutes
        'otp_length' => 6,
        'max_otp_attempts' => 3,
        
        'pagination' => [
            'default_per_page' => 20,
            'max_per_page' => 100,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Configuration
    |--------------------------------------------------------------------------
    |
    | Security-related configurations.
    |
    */

    'security' => [
        'max_login_attempts' => 5,
        'lockout_duration' => 15, // minutes
        'password_reset_expiry' => 60, // minutes
        
        'rate_limits' => [
            'api_requests' => 1000, // per minute
            'otp_requests' => 5, // per hour per phone
            'login_attempts' => 5, // per hour per phone
        ],
        
        'encryption' => [
            'sensitive_fields' => ['bvn', 'nin', 'account_number'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for application logging.
    |
    */

    'logging' => [
        'channels' => [
            'transactions' => 'daily',
            'security' => 'daily',
            'api' => 'daily',
            'sms' => 'daily',
            'monnify' => 'daily',
        ],
        
        'retention_days' => 90,
        'sensitive_data_masking' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for caching strategies.
    |
    */

    'cache' => [
        'ttl' => [
            'banks_list' => 3600, // 1 hour
            'monnify_token' => 3000, // 50 minutes (token expires in 1 hour)
            'user_session' => 1800, // 30 minutes
            'transaction_status' => 300, // 5 minutes
        ],
    ],

];