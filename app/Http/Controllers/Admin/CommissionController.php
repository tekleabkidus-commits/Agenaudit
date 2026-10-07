<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Brand;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CommissionController extends Controller
{
    public function index(Request $request): View
    {
        $q = Agent::query()
            ->with('brand')
            ->withCount([
                'transactions as commission_used_this_month' => fn ($tx) => $tx
                    ->where('type', TransactionType::Commission->value)
                    ->where('status', TransactionStatus::Completed->value)
                    ->whereBetween('completed_at', [now()->startOfMonth(), now()->endOfMonth()]),
            ])
            ->orderBy('agent_id');

        if ($request->filled('brand')) {
            $q->where('brand_id', $request->integer('brand'));
        }

        if ($request->filled('status')) {
            $q->where('commission_enabled', $request->input('status') === 'enabled');
        }

        if ($request->filled('q')) {
            $needle = '%'.$request->string('q')->toString().'%';
            $q->where(fn ($x) => $x
                ->where('agent_id', 'like', $needle)
                ->orWhere('username', 'like', $needle));
        }

        return view('admin.commissions.index', [
            'agents' => $q->paginate(40)->withQueryString(),
            'brands' => Brand::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Agent $agent, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'commission_monthly_limit' => ['required','integer','min:1','max:31'],
        ]);

        $before = [
            'commission_enabled' => $agent->commission_enabled,
            'commission_monthly_limit' => $agent->commission_monthly_limit,
        ];

        $agent->update([
            'commission_enabled' => $request->boolean('commission_enabled'),
            'commission_monthly_limit' => (int) $data['commission_monthly_limit'],
        ]);

        $audit->log('agent.commission_settings_updated', $agent, $before, [
            'commission_enabled' => $agent->commission_enabled,
            'commission_monthly_limit' => $agent->commission_monthly_limit,
        ]);

        return back()->with(
            'success',
            $agent->commission_enabled
                ? "Commission Deposit enabled for {$agent->agent_id}."
                : "Commission Deposit disabled for {$agent->agent_id}."
        );
    }
}
