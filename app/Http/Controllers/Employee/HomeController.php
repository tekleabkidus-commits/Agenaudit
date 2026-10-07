<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user()->load('brands');
        $brandIds = $user->brands->pluck('id');

        $recent = Transaction::query()
            ->with(['agent','brand'])
            ->where('employee_id',$user->id)
            ->where(function ($query) use ($brandIds) {
                $query->whereNull('brand_id')->orWhereIn('brand_id',$brandIds);
            })
            ->latest()
            ->limit(8)
            ->get();

        return view('employee.home', [
            'recent'=>$recent,
            'assignedBrands'=>$user->brands,
        ]);
    }
}
