<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

Artisan::command('agent-audit:health', function () {
    $this->info('Agent Audit Platform is bootable.');
})->purpose('Check application boot health');

Artisan::command('agent-audit:create-admin {--name=} {--username=} {--email=} {--password=}', function () {
    $name = trim((string) ($this->option('name') ?: env('BOOTSTRAP_ADMIN_NAME', 'System Administrator')));
    $username = trim((string) ($this->option('username') ?: env('BOOTSTRAP_ADMIN_USERNAME', '')));
    $email = trim((string) ($this->option('email') ?: env('BOOTSTRAP_ADMIN_EMAIL', '')));
    $password = (string) ($this->option('password') ?: env('BOOTSTRAP_ADMIN_PASSWORD', ''));

    if ($username === '' || $password === '') {
        $this->error('Laravel Cloud commands are non-interactive. Set BOOTSTRAP_ADMIN_USERNAME and BOOTSTRAP_ADMIN_PASSWORD in the environment, then run this command again.');
        $this->line('Optional: BOOTSTRAP_ADMIN_NAME and BOOTSTRAP_ADMIN_EMAIL.');
        return self::FAILURE;
    }

    $data = [
        'name' => $name,
        'username' => $username,
        'email' => $email !== '' ? $email : null,
        'password' => $password,
    ];

    $validator = Validator::make($data, [
        'name' => ['required', 'string', 'max:120'],
        'username' => ['required', 'string', 'max:120', Rule::unique('users', 'username')],
        'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
        'password' => ['required', 'string', 'min:12'],
    ]);

    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $message) {
            $this->error($message);
        }
        return self::FAILURE;
    }

    $user = User::create([
        'name' => $data['name'],
        'username' => $data['username'],
        'email' => $data['email'],
        'password' => Hash::make($data['password']),
        'role' => UserRole::Admin,
        'is_active' => true,
    ]);

    $this->newLine();
    $this->info('Admin account created successfully.');
    $this->line('User ID: '.$user->id);
    $this->line('Username: '.$user->username);
    if ($user->email) {
        $this->line('Email: '.$user->email);
    }
    $this->newLine();
    $this->comment('Security: remove BOOTSTRAP_ADMIN_PASSWORD from Laravel Cloud environment variables after the account is created.');

    return self::SUCCESS;
})->purpose('Create an active Agent Audit administrator account using Laravel Cloud environment secrets');


Artisan::command('agent-audit:repair-admin', function () {
    $clean = static function ($value): string {
        $value = trim((string) $value);
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        return $value;
    };

    $name = $clean(env('BOOTSTRAP_ADMIN_NAME', 'System Administrator'));
    $username = mb_strtolower($clean(env('BOOTSTRAP_ADMIN_USERNAME', '')));
    $email = mb_strtolower($clean(env('BOOTSTRAP_ADMIN_EMAIL', '')));
    $password = $clean(env('BOOTSTRAP_ADMIN_PASSWORD', ''));

    if ($username === '' || strlen($password) < 12) {
        $this->error('Set BOOTSTRAP_ADMIN_USERNAME and a BOOTSTRAP_ADMIN_PASSWORD of at least 12 characters.');
        return self::FAILURE;
    }

    $user = User::query()->where('role', UserRole::Admin->value)->orderBy('id')->first()
        ?? User::query()->orderBy('id')->first()
        ?? new User();

    $conflict = User::query()
        ->whereRaw('LOWER(username) = ?', [$username])
        ->when($user->exists, fn ($q) => $q->whereKeyNot($user->getKey()))
        ->exists();

    if ($conflict) {
        $this->error('Another account already uses that username.');
        return self::FAILURE;
    }

    $user->fill([
        'name' => $name,
        'username' => $username,
        'email' => $email !== '' ? $email : null,
        'password' => $password,
        'role' => UserRole::Admin,
        'is_active' => true,
    ]);
    $user->save();

    $this->info('Admin account repaired successfully.');
    $this->line('User ID: '.$user->id);
    $this->line('Username: '.$user->username);
    $this->line('Active: yes');
    $this->comment('Login now uses the exact bootstrap username/password values.');
    return self::SUCCESS;
})->purpose('Repair the primary Admin account from Laravel Cloud bootstrap variables');


Artisan::command('agent-audit:check-admin', function () {
    $clean = static function ($value): string {
        $value = trim((string) $value);
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        return $value;
    };

    $username = mb_strtolower($clean(env('BOOTSTRAP_ADMIN_USERNAME', '')));
    $password = $clean(env('BOOTSTRAP_ADMIN_PASSWORD', ''));

    $user = User::query()->whereRaw('LOWER(username) = ?', [$username])->first();

    if (!$user) {
        $this->error('Configured Admin username does not exist in the database.');
        return self::FAILURE;
    }

    $this->line('User ID: '.$user->id);
    $this->line('Username: '.$user->username);
    $this->line('Role: '.$user->role->value);
    $this->line('Active: '.($user->is_active ? 'yes' : 'no'));

    if (!Hash::check($password, $user->password)) {
        $this->error('Password check failed.');
        return self::FAILURE;
    }

    $this->info('Password check passed. These credentials are valid.');
    return self::SUCCESS;
})->purpose('Verify Laravel Cloud bootstrap Admin credentials against the database');


/**
 * Production integration smoke test. Calls the configured providers using
 * secrets already in Laravel Cloud. It never prints keys or response bodies,
 * creates transactions, writes screenshots, or submits bank verifications.
 */
Artisan::command('agent-audit:test-apis', function (\App\Services\SettingsService $settings) {
    $failed = false;

    $aiDriver = (string) config('services.ai.driver', 'http');
    $aiEnabled = $settings->bool('ai.enabled', (bool) config('services.ai.enabled', false));
    $aiKey = (string) config('services.ai.api_key', '');
    $aiModel = (string) config('services.ai.model', '');

    $this->info('Agenaudit API connection tests (credentials are never printed)');
    $this->line('AI: Admin switch '.($aiEnabled ? 'ON' : 'OFF').'; driver '.$aiDriver.'; model '.($aiModel ?: 'not set'));

    if (!$aiEnabled) {
        $this->warn('AI automation is OFF in Admin Settings. The API can be connected but automatic evidence processing will stay disabled.');
    }

    if ($aiDriver !== 'openai') {
        $this->warn('OpenAI direct test skipped: AI_DRIVER is not openai. The custom HTTP driver needs its own gateway test.');
    } elseif ($aiKey === '' || $aiModel === '') {
        $this->error('OpenAI: AI_API_KEY or AI_MODEL is missing.');
        $failed = true;
    } else {
        try {
            $base = rtrim((string) config('services.ai.base_url', 'https://api.openai.com/v1'), '/');
            $message = [
                'type' => 'input_text',
                'text' => 'Read the text printed in the synthetic test image. Reply with exactly the letters you see and nothing else.',
            ];
            $input = [$message];
            $visionAvailable = function_exists('imagecreatetruecolor') && function_exists('imagepng');

            if ($visionAvailable) {
                $img = imagecreatetruecolor(320, 128);
                $white = imagecolorallocate($img, 255, 255, 255);
                $black = imagecolorallocate($img, 0, 0, 0);
                imagefilledrectangle($img, 0, 0, 319, 127, $white);
                imagestring($img, 5, 60, 52, 'AGENAUDIT', $black);
                ob_start();
                imagepng($img);
                $png = ob_get_clean();
                imagedestroy($img);
                $input[] = [
                    'type'=>'input_image',
                    'image_url'=>'data:image/png;base64,'.base64_encode($png),
                    'detail'=>'high',
                ];
            } else {
                $this->warn('GD extension is absent: testing text API connectivity only; image vision remains untested.');
                $input[0]['text'] = 'Reply with the single word AGENAUDIT and nothing else.';
            }

            $response = \Illuminate\Support\Facades\Http::acceptJson()
                ->withToken($aiKey)
                ->timeout(30)
                ->withoutRedirecting()
                ->post($base.'/responses', [
                    'model' => $aiModel,
                    'input' => [[
                        'role' => 'user',
                        'content' => $input,
                    ]],
                    'max_output_tokens' => 48,
                ]);

            if (!$response->successful()) {
                $failed = true;
                // Only display well-known error codes. Never echo raw provider
                // error text or headers because diagnostics must be safe to share.
                $errorCode = (string) data_get($response->json(), 'error.code', '');
                $errorType = (string) data_get($response->json(), 'error.type', '');
                $quotaCodes = [
                    'insufficient_quota', 'credit_balance_exhausted',
                    'organization_usage_limit_exceeded',
                    'organization_spend_limit_exceeded',
                    'project_spend_limit_exceeded',
                ];
                $isQuota = in_array($errorCode, $quotaCodes, true)
                    || in_array($errorType, $quotaCodes, true);
                $isRateLimit = in_array($errorCode, ['rate_limit_exceeded'], true)
                    || in_array($errorType, ['rate_limit_exceeded'], true);

                $guidance = match (true) {
                    $response->status() === 401 => 'API key is invalid or revoked.',
                    $response->status() === 403 => 'API project or model access denied.',
                    $response->status() === 404 => 'Endpoint or model is not available.',
                    $response->status() === 429 && $isQuota => 'Quota/billing restriction. Check OpenAI API credits, organization and project spend/usage limits. Waiting or retrying will not solve a quota error.',
                    $response->status() === 429 && $isRateLimit => 'Request/token rate limit. Wait, pace requests and check the OpenAI organization/project rate limits.',
                    $response->status() === 429 => 'Could be billing, quota, or rate limits. Check the OpenAI API billing page and organization/project limits.',
                    default => 'Check model configuration, provider status, and Laravel Cloud Logs.',
                };

                $this->error('OpenAI: FAILED HTTP '.$response->status().'. '.$guidance);
            } else {
                $body = $response->json();
                $output = (string) data_get($body, 'output_text', '');
                if ($output === '') {
                    foreach ((array) data_get($body, 'output', []) as $item) {
                        foreach ((array) data_get($item, 'content', []) as $part) {
                            if (data_get($part, 'type') === 'output_text') {
                                $output .= (string) data_get($part, 'text', '');
                            }
                        }
                    }
                }

                if (strtoupper(trim($output, " \t\n\r\0\x0B.\"'")) === 'AGENAUDIT') {
                    $this->info($visionAvailable
                        ? 'OpenAI: PASS — key, model, Responses API and synthetic image reading.'
                        : 'OpenAI: PASS — key, model and Responses API; image reading untested.');
                } else {
                    $failed = true;
                    $this->error('OpenAI: API returned HTTP 200 but the diagnostic text did not match. Check the selected model and image support.');
                }
            }
        } catch (\Throwable $e) {
            report($e);
            $failed = true;
            $this->error('OpenAI: FAILED due to network or runtime exception (details in Laravel Cloud Logs).');
        }
    }

    $checkEnabled = $settings->bool('check_et.enabled', (bool) config('services.check_et.enabled', false));
    $checkKey = (string) config('services.check_et.api_key', '');
    $this->line('Check.et: Admin switch '.($checkEnabled ? 'ON' : 'OFF'));

    if (!$checkEnabled) {
        $this->warn('Check.et is OFF in Admin Settings. Secondary payment verification will not run.');
    }

    if ($checkKey === '') {
        $failed = true;
        $this->error('Check.et: CHECK_ET_API_KEY is missing.');
    } else {
        try {
            $base = rtrim((string) config('services.check_et.base_url', 'https://api.check.et'), '/');
            // Read-only API routes. Do not send a fake or real transaction to
            // POST /verify: verification may spend quota and affect deduplication.
            $response = \Illuminate\Support\Facades\Http::acceptJson()
                ->withToken($checkKey)
                ->withoutRedirecting()
                ->timeout(12)
                ->get($base.'/api/v1/verifications', ['limit'=>1]);

            // Some deployments expose /accounts rather than /verifications.
            if ($response->status() === 404) {
                $response = \Illuminate\Support\Facades\Http::acceptJson()
                    ->withToken($checkKey)
                    ->withoutRedirecting()
                    ->timeout(12)
                    ->get($base.'/api/v1/accounts');
            }

            if ($response->successful()) {
                $this->info('Check.et: PASS — authenticated read-only API request succeeded. Payment verification still requires a separate controlled test.');
            } elseif (in_array($response->status(), [401, 403], true)) {
                $failed = true;
                $this->error('Check.et: FAILED HTTP '.$response->status().' — the key was rejected or is missing read permission.');
            } elseif ($response->status() === 404) {
                $failed = true;
                $this->warn('Check.et: Provider reached, but a read-only account/history endpoint was not found. API key validity is UNVERIFIED; no payment was submitted.');
            } else {
                $failed = true;
                $this->error('Check.et: FAILED HTTP '.$response->status().'; inspect provider access, connectivity, and Laravel Cloud Logs.');
            }
        } catch (\Throwable $e) {
            report($e);
            $failed = true;
            $this->error('Check.et: FAILED due to network or runtime exception (details in Laravel Cloud Logs).');
        }
    }

    $this->newLine();
    $this->line('No financial records or real customer evidence were created or sent during these diagnostics.');
    $this->line('Full Check.et payment validation and the Laravel evidence queue require separate end-to-end testing.');

    return $failed ? self::FAILURE : self::SUCCESS;
})->purpose('Safely test configured OpenAI and Check.et API connections without exposing credentials or verifying payments');
