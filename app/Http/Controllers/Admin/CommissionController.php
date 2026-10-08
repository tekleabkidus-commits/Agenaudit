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
use Illuminate\Support\Facades\DB;
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
            'brands' => Brand::where('is_active', true)
                ->withCount(['agents', 'agents as commissioned_agents_count' => fn ($q) => $q->where('commission_enabled', true)])
                ->orderBy('name')->get(),
        ]);
    }

    /**
     * Brand master switch is enforced even when an agent's individual
     * commission flag is ON. A bulk application updates all existing
     * agent flags and limits in one database transaction.
     */
    public function updateBrand(Request $request, Brand $brand, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'commission_monthly_limit' => ['required', 'integer', 'min:1', 'max:31'],
            'apply_to_agents' => ['nullable','boolean'],
        ]);

        $enabled = $request->boolean('commission_enabled');
        $monthlyLimit = (int) $data['commission_monthly_limit'];
        $apply = $request->boolean('apply_to_agents');

        $affected = DB::transaction(function () use ($brand, $enabled, $monthlyLimit, $apply, $audit): int {
            $locked = Brand::query()->whereKey($brand->id)->lockForUpdate()->firstOrFail();
            $before = $locked->only(['commission_enabled','commission_monthly_limit']);

            $locked->update([
                'commission_enabled' => $enabled,
                'commission_monthly_limit' => $monthlyLimit,
            ]);

            $count = 0;
            if ($apply) {
                $count = Agent::query()->where('brand_id', $locked->id)->update([
                    'commission_enabled' => $enabled,
                    'commission_monthly_limit' => $monthlyLimit,
                    'updated_at' => now(),
                ]);
            }

            $audit->log('brand.commission_settings_updated', $locked, $before, [
                'commission_enabled' => $enabled,
                'commission_monthly_limit' => $monthlyLimit,
                'applied_to_existing_agents' => $apply,
                'agents_updated' => $count,
            ]);

            return $count;
        }, 3);

        return back()->with('success', $apply
            ? "{$brand->name}: commission policy updated for {$affected} agents."
            : "{$brand->name}: brand commission policy updated. Individual agent settings preserved.");
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
