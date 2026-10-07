<?php

namespace App\Http\Controllers\Employee;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(Request $request): View
    {
        $brandIds = $request->user()->brands()->pluck('brands.id');
        $q = Transaction::where('employee_id', $request->user()->id)
            ->where(function ($query) use ($brandIds) {
                $query->whereNull('brand_id')->orWhereIn('brand_id', $brandIds);
            });
        return view('employee.home', [
            'recent' => (clone $q)->with(['agent','brand'])->latest()->limit(8)->get(),
            'openCount' => (clone $q)->whereNotIn('status', [TransactionStatus::Completed->value,TransactionStatus::Rejected->value,TransactionStatus::Cancelled->value])->count(),
            'reviewCount' => (clone $q)->whereIn('status', [TransactionStatus::PendingAdminReview->value,TransactionStatus::PendingCorrectionApproval->value])->count(),
        ]);
    }
}
