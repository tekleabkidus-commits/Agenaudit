<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgentImport;
use App\Services\Imports\AgentImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class AgentImportController extends Controller
{
    public function index(): View { return view('admin.imports.index',['imports'=>AgentImport::with('uploader')->latest()->paginate(25)]); }
    public function store(Request $request, AgentImportService $service): RedirectResponse
    {
        $data=$request->validate(['file'=>['required','file','mimes:xlsx','max:10240']]);
        try { $import=$service->stage($data['file'],$request->user()); }
        catch(Throwable $e){ return back()->withErrors(['file'=>$e->getMessage()]); }
        return redirect()->route('admin.agent-imports.show',$import);
    }
    public function show(AgentImport $agentImport): View { return view('admin.imports.show',['import'=>$agentImport->load(['rows.brand','rows.agent'])]); }
    public function confirm(Request $request, AgentImport $agentImport, AgentImportService $service): RedirectResponse
    {
        try { $service->confirm($agentImport,$request->user(),$request->boolean('allow_moves')); }
        catch(Throwable $e){ return back()->withErrors(['import'=>$e->getMessage()]); }
        return redirect()->route('admin.agents.index')->with('success','Agent import completed.');
    }
}
