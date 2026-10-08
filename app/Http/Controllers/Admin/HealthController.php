<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class HealthController extends Controller
{
    public function index(SettingsService $settings): View
    {
        $database = true;
        try {
            DB::select('SELECT 1');
        } catch (Throwable) {
            $database = false;
        }

        $disk = (string) config('agent_audit.evidence.disk', 'private');
        $production = app()->environment('production');
        $remoteStorageConfigured = !in_array($disk, ['local', 'private', 'public'], true);

        $aiEnabled = $settings->bool('ai.enabled', (bool) config('services.ai.enabled'));
        $aiDriver = (string) config('services.ai.driver','openai');
        $aiConfigured = match ($aiDriver) {
            'gemini' => filled(config('services.ai.gemini_api_key')),
            'http' => filled(config('services.ai.api_key')) && filled(config('services.ai.endpoint')),
            'openai' => filled(config('services.ai.api_key')) && filled(config('services.ai.model')),
            default => false,
        };
        $geminiPrivacyApproved = $aiDriver !== 'gemini'
            || (bool) config('services.ai.gemini_allow_sensitive_evidence', false);

        $checkEnabled = $settings->bool('check_et.enabled', (bool) config('services.check_et.enabled'));
        $checkConfigured = filled(config('services.check_et.api_key'));

        return view('admin.health.index', [
            'checks'=>[
                ['name'=>'Database','status'=>$database?'ready':'error','description'=>$database?'Connection successful':'Database is not reachable'],
                ['name'=>'Private evidence storage','status'=>$production && !$remoteStorageConfigured?'warning':'configured',
                    'description'=>$disk.' disk'.($remoteStorageConfigured ? ' — remote disk configured; perform storage test' : ' — local disk; do not use for durable Cloud financial evidence')],
                ['name'=>'Queue worker','status'=>'configured',
                    'description'=>'Queue connection: '.config('queue.default').'. Worker service health must be checked in Laravel Cloud'],
                ['name'=>'AI screenshot extraction','status'=>!$aiEnabled?'optional':($aiConfigured && $geminiPrivacyApproved?'configured':'warning'),
                    'description'=>$aiEnabled
                        ? (!$aiConfigured
                            ? 'Enabled but missing server-side driver credentials'
                            : (!$geminiPrivacyApproved
                                ? 'Gemini free-tier privacy gate is OFF; real financial screenshots route to Admin manual extraction.'
                                : 'Driver configured; real screenshot validation still required'))
                        : 'Disabled; Admin manual extraction is required'],
                ['name'=>'Check.et secondary verification','status'=>!$checkEnabled?'optional':($checkConfigured?'configured':'warning'),
                    'description'=>$checkEnabled ? ($checkConfigured?'Credentials configured; live bank verification still required':'Enabled without a server-side API key') : 'Disabled — internal verification remains active'],
            ],
            'evidenceDisk'=>$disk,
            'production'=>$production,
        ]);
    }

    public function testStorage(Request $request, AuditLogger $audit): RedirectResponse
    {
        $disk = (string) config('agent_audit.evidence.disk', 'private');
        $path = 'health-checks/'.Str::uuid().'.txt';
        $marker = Str::random(32);

        try {
            $storage = Storage::disk($disk);
            $stored = $storage->put($path, $marker, ['visibility'=>'private']);
            if (!$stored || $storage->get($path) !== $marker) {
                throw new \RuntimeException('Storage read/write check failed.');
            }

            $storage->delete($path);

            $audit->log('storage.diagnostic_passed',null,null,['disk'=>$disk],[],$request->user());

            return back()->with('success','Private storage write/read/delete test passed. This does not replace the retention and backup review.');
        } catch (Throwable $e) {
            report($e);
            try { Storage::disk($disk)->delete($path); } catch (Throwable) {}

            $audit->log('storage.diagnostic_failed',null,null,['disk'=>$disk],[],$request->user());

            return back()->withErrors(['storage'=>'Private storage test failed. Check disk credentials, bucket access and Laravel Cloud configuration.']);
        }
    }
}
