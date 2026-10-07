<?php

return [
    'device_cookie' => [
        'name' => env('DEVICE_COOKIE_NAME', 'agent_audit_device'),
        'years' => (int) env('DEVICE_COOKIE_YEARS', 25),
        'secure' => (bool) env('DEVICE_COOKIE_SECURE', false),
    ],
    'imports' => ['max_agent_rows' => (int) env('MAX_AGENT_IMPORT_ROWS', 50000)],
    'evidence' => [
        'disk' => env('FILESYSTEM_DISK', 'private'),
        'max_kb' => 12288,
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
        'max_bank_screenshots_per_transaction' => 12,
    ],
    'hard_non_correctable_fields' => [
        'receiver_bank',
        'receiver_account',
        'receiver_name',
    ],
    'correction_fields' => [
        'amount',
        'sender_bank',
        'sender_account',
        'sender_name',
        'transaction_id',
        'transaction_date',
        'agent_id',
        'agent_username',
        'agent_amount',
        'agent_transaction_date',
        'brand_hint',
    ],
    'default_settings' => [
        'validation.warning_minutes' => 60,
        'validation.serious_minutes' => 180,
        'validation.alarming_minutes' => 360,
        'validation.critical_minutes' => 720,
        'validation.receiver_name_similarity' => 0.90,
        'validation.require_receiver_name' => true,
        'ai.enabled' => (bool) env('AI_ENABLED', false),
        'ai.min_quality_score' => (float) env('AI_MIN_QUALITY_SCORE', 0.80),
        'ai.min_critical_confidence' => (float) env('AI_MIN_CRITICAL_CONFIDENCE', 0.85),
        'check_et.enabled' => (bool) env('CHECK_ET_ENABLED', false),
        'check_et.outage_mode' => env('CHECK_ET_OUTAGE_MODE', 'review'),
        'check_et.failure_mode' => env('CHECK_ET_FAILURE_MODE', 'review'),
    ],
];
