<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\DeviceSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request, DeviceSessionService $devices, AuditLogger $audit): RedirectResponse
    {
        $username = mb_strtolower(trim($request->string('username')->toString()));
        $password = $request->string('password')->toString();
        $key = $username.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 8)) {
            return back()->withErrors(['username'=>'Too many login attempts. Try again shortly.'])->onlyInput('username');
        }

        // PostgreSQL string equality is case-sensitive. Resolve the account
        // case-insensitively so stored username casing can never break login.
        $user = User::query()
            ->whereRaw('LOWER(username) = ?', [$username])
            ->first();

        if (!$user || !$user->is_active || !Hash::check($password, $user->password)) {
            RateLimiter::hit($key, 60);
            return back()->withErrors(['username'=>'Invalid username or password.'])->onlyInput('username');
        }

        Auth::login($user);
        RateLimiter::clear($key);
        $request->session()->regenerate();

        $user->forceFill(['last_login_at'=>now()])->save();
        $devices->issue($user, $request);
        $audit->log('auth.login', $user, null, ['persistent_device'=>true], [], $user);

        return redirect()->intended($user->isAdmin() ? route('admin.dashboard') : route('employee.home'));
    }
}
