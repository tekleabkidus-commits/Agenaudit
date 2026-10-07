<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BrandController extends Controller
{
    public function index(): View { return view('admin.brands.index',['brands'=>Brand::withCount('agents')->orderBy('name')->paginate(30)]); }
    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data=$request->validate(['name'=>['required','string','max:120','unique:brands,name']]);
        $brand=Brand::create(['name'=>$data['name'],'slug'=>Str::slug($data['name']).'-'.Str::lower(Str::random(4)),'is_active'=>true]);
        $audit->log('brand.created',$brand,null,$brand->toArray());
        return back()->with('success','Brand added.');
    }
    public function update(Request $request, Brand $brand, AuditLogger $audit): RedirectResponse
    {
        $data=$request->validate(['name'=>['required','string','max:120',Rule::unique('brands','name')->ignore($brand->id)],'is_active'=>['nullable','boolean']]);
        $before=$brand->toArray();
        $brand->update(['name'=>$data['name'],'is_active'=>$request->boolean('is_active')]);
        $audit->log('brand.updated',$brand,$before,$brand->toArray());
        return back()->with('success','Brand updated.');
    }
}
