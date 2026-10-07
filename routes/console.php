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
