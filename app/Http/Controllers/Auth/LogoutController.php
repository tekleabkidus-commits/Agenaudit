<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\DeviceSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LogoutController extends Controller
{
    public function __invoke(Request $request, DeviceSessionService $devices, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        if ($user) $audit->log('auth.logout', $user, null, null, [], $user);
        $devices->revokeByPlainToken($request->cookie(config('agent_audit.device_cookie.name')));
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}
