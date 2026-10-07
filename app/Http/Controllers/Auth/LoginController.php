<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\DeviceSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
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
        $username = strtolower(trim($request->string('username')->toString()));
        $key = $username.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 8)) {
            return back()->withErrors(['username'=>'Too many login attempts. Try again shortly.'])->onlyInput('username');
        }

        $ok = Auth::attempt([
            'username'=>$username,
            'password'=>$request->string('password')->toString(),
            'is_active'=>true,
        ]);
        if (!$ok) {
            RateLimiter::hit($key, 60);
            return back()->withErrors(['username'=>'Invalid username or password.'])->onlyInput('username');
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $user = $request->user();
        $user->update(['last_login_at'=>now()]);
        $devices->issue($user, $request);
        $audit->log('auth.login', $user, null, ['persistent_device'=>true], [], $user);

        return redirect()->intended($user->isAdmin() ? route('admin.dashboard') : route('employee.home'));
    }
}
