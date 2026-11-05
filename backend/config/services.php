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

    // In-app purchases in the apps (subscriptions and boosts): the store sells, our server reads the purchase back.
    'app_store' => [
        'driver' => env('APP_STORE_DRIVER', 'fake'),        // fake · apple
        'issuer_id' => env('APP_STORE_ISSUER_ID'),
        'key_id' => env('APP_STORE_KEY_ID'),
        'private_key' => env('APP_STORE_PRIVATE_KEY'),       // path to the .p8 key of App Store Connect
        'bundle_id' => env('APP_STORE_BUNDLE_ID', 'com.zon.app'),
        // The Team ID of the Apple developer account (Membership details): with the bundle id it names the app in
        // apple-app-site-association, so zon.sa links open the app. Empty: the file is not served.
        'team_id' => env('APPLE_TEAM_ID'),
        'url' => env('APP_STORE_URL', 'https://api.storekit.apple.com'),
        'sandbox_url' => env('APP_STORE_SANDBOX_URL', 'https://api.storekit-sandbox.apple.com'),
    ],
    'google_play' => [
        'driver' => env('GOOGLE_PLAY_DRIVER', 'fake'),      // fake · google
        'package' => env('GOOGLE_PLAY_PACKAGE', 'com.zon.app'),
        'credentials' => env('GOOGLE_PLAY_CREDENTIALS'),     // path to the service-account JSON (Play Console access)
        // SHA-256 fingerprints of the app's signing certificates (Play Console → App integrity → App signing; and the
        // upload key's for test builds), comma separated: assetlinks.json lets zon.sa links open the app. Empty: not served.
        'cert_fingerprints' => array_values(array_filter(array_map('trim', explode(',', (string) env('ANDROID_CERT_SHA256', ''))))),
    ],
    // The stores' notices reach /api/v1/store/notices/{store}?key=… — the key keeps strangers' calls out.
    'store_notices_key' => env('STORE_NOTICES_KEY'),

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
