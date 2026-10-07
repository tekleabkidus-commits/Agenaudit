<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeviceSession;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\DeviceSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SessionController extends Controller
{
    public function index(): View
    {
        $users = User::query()
            ->whereHas('deviceSessions', fn($q)=>$q->whereNull('revoked_at')->where('expires_at','>',now()))
            ->withCount([
                'deviceSessions as active_device_sessions_count' => fn($q)=>$q->whereNull('revoked_at')->where('expires_at','>',now()),
            ])
            ->orderBy('name')
            ->get();

        return view('admin.sessions.index',[
            'sessions'=>DeviceSession::with('user')->latest('last_seen_at')->paginate(50),
            'usersWithSessions'=>$users,
        ]);
    }

    public function revoke(Request $request, DeviceSession $deviceSession, DeviceSessionService $sessions, AuditLogger $audit): RedirectResponse
    {
        $sessions->revoke($deviceSession);
        $audit->log('session.revoked',$deviceSession,null,['user_id'=>$deviceSession->user_id]);

        return back()->with('success','Device session revoked.');
    }

    public function revokeAll(Request $request, User $user, DeviceSessionService $sessions, AuditLogger $audit): RedirectResponse
    {
        $count=$sessions->revokeAll($user);
        $audit->log('session.revoked_all',$user,null,['count'=>$count]);

        return back()->with('success',"{$count} active device session(s) revoked.");
    }
}
