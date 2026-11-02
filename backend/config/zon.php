<?php

// Technical values of the platform (business values live in the database: packages and App\Support\Settings).
return [
    // Opened by «مشاركة» in the app: {deep_link_base}/listings/{id}.
    'deep_link_base' => env('ZON_DEEP_LINK_BASE', 'https://zon.sa'),

    // HMAC key for the device and IP fingerprints of sign-ups and AI quotas (never the raw values — §14.2).
    'rate_salt' => env('RATE_SALT'),

    'admin_allowed_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('ADMIN_ALLOWED_IPS', ''))))),

    'otp' => [
        'length' => 6,
        'ttl_minutes' => 5,
        'max_attempts' => 5,
        // A fixed code accepted only with the fake SMS driver outside production (the app's 204815 of v3.1.2).
        'test_code' => env('OTP_TEST_CODE'),
    ],

    // The pending sign-up waits here until its phone is verified (no unverified rows in customers).
    'registration_ttl_minutes' => 15,

    'agents' => [
        'url' => env('AGENTS_URL', 'http://127.0.0.1:8100'),
        'key' => env('AGENTS_INTERNAL_KEY'),
        'timeout' => 20,              // seconds; no retry after a timeout (architecture §10)
        'connect_timeout' => 2,       // a fast connection error is retried once
        'fallback_enabled' => true,   // the SQL-only answer when the AI is down
    ],

    'ai' => [
        'cache_minutes' => 15,
        'results' => 10,
        'reason_max_words' => 12,
        'candidates_limit' => 300,
        'free_text_max' => 1000,
        'quota_connection' => 'quota',
        // AI quota layers: the account's cap ×1, the device ×1.5, the IP ×3.
        'quota_multipliers' => ['account' => 1.0, 'device' => 1.5, 'ip' => 3.0],
        // Tries per day, answered or not, as a multiple of the account's cap (AiQuota::attempt).
        'attempts_multiplier' => 3,
    ],

    'index' => [
        'secret' => env('INDEX_SHARED_SECRET'),
        'connect_timeout' => 5,
        'timeout' => 10,
    ],

    'media_disk' => env('MEDIA_DISK', 'minio'),
    'media' => ['max_kb' => 8192, 'url_minutes' => 60],

    'payments' => ['reuse_pending_minutes' => 30],

    // The SuperAgent's read-only connection (ai_reader on the ai_v_* views).
    'report_connection' => 'report',
];
