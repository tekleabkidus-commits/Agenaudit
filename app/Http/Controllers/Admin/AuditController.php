<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller; use App\Models\AuditLog; use App\Models\User; use Illuminate\Http\Request; use Illuminate\View\View;
class AuditController extends Controller { public function index(Request $r): View { $q=AuditLog::with('actor')->latest('id'); if($r->filled('actor'))$q->where('actor_id',$r->integer('actor')); if($r->filled('action'))$q->where('action','like','%'.$r->string('action')->toString().'%'); return view('admin.audit.index',['logs'=>$q->paginate(50)->withQueryString(),'users'=>User::orderBy('name')->get()]); } }
