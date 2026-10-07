<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\Dashboard\DashboardService;
use App\Services\Dashboard\DateRange;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard): View
    {
        $preset = $request->string('date','today')->toString();
        $range = DateRange::fromPreset($preset,$request->input('from'),$request->input('to'));
        $brandId = $request->filled('brand') && $request->input('brand') !== 'total' ? (int)$request->input('brand') : null;
        return view('admin.dashboard.index', [
            'data'=>$dashboard->data($range,$brandId),'brands'=>Brand::where('is_active',true)->orderBy('name')->get(),
            'preset'=>$preset,'brandId'=>$brandId,
        ]);
    }
}
