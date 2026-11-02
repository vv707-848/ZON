<?php

// External services. Each has a driver: "fake" (simulated, for development and until a contract exists) or the
// live one. Switching to live = the driver class + its line in IntegrationServiceProvider + the value here in .env.
// In production a fake driver is refused unless PRODUCTION_ALLOWS_FAKE names the service (App\Integrations\Drivers).
return [
    'production_allows_fake' => env('PRODUCTION_ALLOWS_FAKE', ''),

    'sms' => [
        'driver' => env('SMS_DRIVER', 'fake'),               // fake · taqnyat
        'taqnyat' => [
            'url' => env('TAQNYAT_URL', 'https://api.taqnyat.sa'),
            'token' => env('TAQNYAT_TOKEN'),
            'sender' => env('TAQNYAT_SENDER', 'ZON'),
        ],
    ],

    // Advertising licences and FAL licences of the General Authority for Real Estate.
    'rega' => ['driver' => env('REGA_DRIVER', 'fake')],      // fake (until REGA grants API access)

    'nafath' => ['driver' => env('NAFATH_DRIVER', 'fake')],  // fake (until the contract)

    'payments' => [
        'driver' => env('PAYMENTS_DRIVER', 'fake'),          // fake · moyasar
        'moyasar' => [
            'url' => env('MOYASAR_URL', 'https://api.moyasar.com/v1'),
            'secret_key' => env('MOYASAR_SECRET_KEY'),
            'publishable_key' => env('MOYASAR_PUBLISHABLE_KEY'),
            'webhook_secret' => env('MOYASAR_WEBHOOK_SECRET'),
        ],
    ],

    'push' => [
        'driver' => env('PUSH_DRIVER', 'fake'),              // fake · fcm
        'fcm' => [
            'project_id' => env('FCM_PROJECT_ID'),
            'credentials' => env('FCM_CREDENTIALS'),         // path to the service-account JSON
        ],
    ],

    'invoicing' => ['driver' => env('INVOICING_DRIVER', 'local')],        // local (ZATCA phase 1 QR)

    'image_hash' => ['driver' => env('IMAGE_HASH_DRIVER', 'gd')],         // gd · agents
];
