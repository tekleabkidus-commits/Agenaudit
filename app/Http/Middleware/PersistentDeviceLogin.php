<?php
namespace App\Http\Middleware;
use App\Services\Auth\DeviceSessionService; use Closure; use Illuminate\Http\Request; use Illuminate\Support\Facades\Auth; use Symfony\Component\HttpFoundation\Response;
class PersistentDeviceLogin {
 public function __construct(private DeviceSessionService $devices){}
 public function handle(Request $request, Closure $next): Response { $plain=$request->cookie(config('agent_audit.device_cookie.name')); if(!Auth::check()&&$plain){$device=$this->devices->find($plain);if($device?->isUsable()){Auth::login($device->user);$this->devices->touch($plain,$request);}} elseif(Auth::check()&&$plain){$this->devices->touch($plain,$request);} return $next($request); }
}
