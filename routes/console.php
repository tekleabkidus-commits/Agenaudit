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
    $name = trim((string) ($this->option('name') ?: $this->ask('Admin full name', 'System Administrator')));
    $username = trim((string) ($this->option('username') ?: $this->ask('Admin username')));
    $email = trim((string) ($this->option('email') ?: $this->ask('Admin email (optional)', '')));
    $password = (string) ($this->option('password') ?: $this->secret('Admin password'));

    if ($password === '') {
        $this->error('Password is required.');
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

    return self::SUCCESS;
})->purpose('Create an active Agent Audit administrator account');
