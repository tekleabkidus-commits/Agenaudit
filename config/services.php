<?php

return [
    'ai' => [
        'driver' => env('AI_DRIVER', 'http'),
        'base_url' => rtrim((string) env('AI_BASE_URL', 'https://api.openai.com/v1'), '/'),
        'enabled' => (bool) env('AI_ENABLED', false),
        'endpoint' => env('AI_ENDPOINT'),
        'api_key' => env('AI_API_KEY'),
        'model' => env('AI_MODEL'),
        'timeout' => (int) env('AI_TIMEOUT_SECONDS', 45),
        'min_quality_score' => (float) env('AI_MIN_QUALITY_SCORE', 0.80),
        'min_critical_confidence' => (float) env('AI_MIN_CRITICAL_CONFIDENCE', 0.85),
    ],
    'check_et' => [
        'enabled' => (bool) env('CHECK_ET_ENABLED', false),
        'api_key' => env('CHECK_ET_API_KEY'),
        'base_url' => rtrim((string) env('CHECK_ET_BASE_URL', 'https://api.check.et'), '/'),
        'timeout' => (int) env('CHECK_ET_TIMEOUT_SECONDS', 8),
        'outage_mode' => env('CHECK_ET_OUTAGE_MODE', 'review'),
        'failure_mode' => env('CHECK_ET_FAILURE_MODE', 'review'),
    ],
];
