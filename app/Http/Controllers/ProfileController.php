<?php

namespace App\Http\Controllers;

use App\Services\Audit\AuditLogger;
use App\Services\Auth\DeviceSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
            'devices' => $request->user()->deviceSessions()->latest('last_seen_at')->limit(20)->get(),
        ]);
    }

    public function password(Request $request, DeviceSessionService $devices, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:12', 'max:255', 'confirmed'],
        ]);

        $user = $request->user();
        if (!Hash::check($data['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => 'Current password is incorrect.']);
        }

        $user->update(['password' => $data['password']]);
        $devices->revokeAll($user);
        $devices->issue($user, $request);
        $audit->log('auth.password_changed', $user, null, ['persistent_sessions_rotated' => true], [], $user);

        return back()->with('success', 'Password changed. Other remembered devices were signed out; this device remains signed in.');
    }
}
