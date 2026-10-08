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
        'gemini_api_key' => env('GEMINI_API_KEY'),
        'gemini_model' => env('GEMINI_MODEL', 'gemini-3.5-flash-lite'),
        'gemini_base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'gemini_allow_sensitive_evidence' => (bool) env('GEMINI_ALLOW_SENSITIVE_EVIDENCE', false),
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
