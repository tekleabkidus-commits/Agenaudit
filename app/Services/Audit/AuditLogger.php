<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    public function log(string $action, ?Model $auditable = null, mixed $before = null, mixed $after = null, array $metadata = [], ?User $actor = null): AuditLog
    {
        /** @var Request|null $request */
        $request = app()->bound('request') ? request() : null;
        return AuditLog::create([
            'actor_id' => ($actor ?? Auth::user())?->id,
            'action' => $action,
            'auditable_type' => $auditable ? $auditable::class : null,
            'auditable_id' => $auditable?->getKey(),
            'before' => $before,
            'after' => $after,
            'metadata' => $metadata ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'created_at' => now(),
        ]);
    }
}
