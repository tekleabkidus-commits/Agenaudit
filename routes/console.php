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
