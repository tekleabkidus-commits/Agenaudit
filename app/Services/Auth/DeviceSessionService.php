<?php
namespace App\Services\Auth;

use App\Models\DeviceSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

class DeviceSessionService
{
    public function issue(User $user, Request $request): string
    {
        $plain=Str::random(80); $years=max(1,(int)config('agent_audit.device_cookie.years',25));
        DeviceSession::create(['user_id'=>$user->id,'token_hash'=>hash('sha256',$plain),'device_label'=>$this->deviceLabel($request->userAgent()),'user_agent'=>$request->userAgent(),'ip_address'=>$request->ip(),'last_seen_at'=>now(),'expires_at'=>now()->addYears($years)]);
        Cookie::queue(cookie(config('agent_audit.device_cookie.name'),$plain,60*24*365*$years,'/',null,(bool)config('agent_audit.device_cookie.secure'),true,false,'lax'));
        return $plain;
    }
    public function find(?string $plain): ?DeviceSession { if(!$plain)return null; return DeviceSession::with('user')->where('token_hash',hash('sha256',$plain))->first(); }
    public function touch(?string $plain, Request $request): void
    {
        if(!$plain)return; $session=$this->find($plain); if(!$session||!$session->isUsable())return;
        if(!$session->last_seen_at || $session->last_seen_at->lt(now()->subMinutes(10))) $session->update(['last_seen_at'=>now(),'ip_address'=>$request->ip(),'user_agent'=>$request->userAgent()]);
    }
    public function revokeByPlainToken(?string $plain): void { if($session=$this->find($plain))$session->update(['revoked_at'=>now()]); Cookie::queue(Cookie::forget(config('agent_audit.device_cookie.name'))); }
    public function revoke(DeviceSession $session): void { $session->update(['revoked_at'=>now()]); }
    public function revokeAll(User $user): int { return $user->deviceSessions()->whereNull('revoked_at')->update(['revoked_at'=>now()]); }
    private function deviceLabel(?string $ua): string { $ua=$ua??'Unknown device'; foreach(['iPhone','iPad','Android','Windows','Macintosh','Linux'] as $needle)if(str_contains($ua,$needle))return $needle; return mb_substr($ua,0,80); }
}
