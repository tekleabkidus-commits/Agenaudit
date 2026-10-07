<?php
namespace App\Http\Controllers\Admin;

use App\Enums\UserRole; use App\Http\Controllers\Controller; use App\Models\EmployeePermission; use App\Models\User; use App\Services\Audit\AuditLogger; use App\Services\Auth\DeviceSessionService; use Illuminate\Http\RedirectResponse; use Illuminate\Http\Request; use Illuminate\Validation\Rule; use Illuminate\View\View;

class EmployeeController extends Controller
{
    public function index(): View { return view('admin.employees.index',['users'=>User::with('permissions')->orderBy('name')->paginate(40),'correctionFields'=>config('agent_audit.correction_fields')]); }
    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $request->merge(['username'=>strtolower(trim((string)$request->input('username')))]);
        $data=$request->validate(['name'=>['required','string','max:120'],'username'=>['required','string','max:100','alpha_dash','unique:users,username'],'password'=>['required','string','min:10','max:255'],'role'=>['required',Rule::enum(UserRole::class)],'correction_fields'=>['nullable','array'],'correction_fields.*'=>['string']]);
        $user=User::create(['name'=>$data['name'],'username'=>$data['username'],'password'=>$data['password'],'role'=>UserRole::from($data['role']),'is_active'=>true]);
        $fields=$this->allowed($data['correction_fields']??[]); EmployeePermission::updateOrCreate(['user_id'=>$user->id],['correction_fields'=>$fields]);
        $audit->log('user.created',$user,null,['name'=>$user->name,'username'=>$user->username,'role'=>$user->role->value,'correction_fields'=>$fields]); return back()->with('success','User created.');
    }
    public function update(Request $request, User $user, AuditLogger $audit, DeviceSessionService $devices): RedirectResponse
    {
        $request->merge(['username'=>strtolower(trim((string)$request->input('username')))]);
        $data=$request->validate(['name'=>['required','string','max:120'],'username'=>['required','string','max:100','alpha_dash',Rule::unique('users','username')->ignore($user->id)],'role'=>['required',Rule::enum(UserRole::class)],'password'=>['nullable','string','min:10','max:255'],'correction_fields'=>['nullable','array'],'correction_fields.*'=>['string']]);
        $newRole=UserRole::from($data['role']); $newActive=$request->boolean('is_active');
        if($user->id===$request->user()->id && (!$newActive || $newRole!==UserRole::Admin)) return back()->withErrors(['user'=>'You cannot disable or remove your own Admin role while signed in.']);
        if($user->role===UserRole::Admin && (!$newActive || $newRole!==UserRole::Admin)){
            $otherAdmins=User::where('role',UserRole::Admin->value)->where('is_active',true)->whereKeyNot($user->id)->count(); if($otherAdmins===0)return back()->withErrors(['user'=>'At least one active Admin must remain.']);
        }
        $before=['name'=>$user->name,'username'=>$user->username,'role'=>$user->role->value,'is_active'=>$user->is_active,'correction_fields'=>$user->permissions?->correction_fields];
        $attrs=['name'=>$data['name'],'username'=>$data['username'],'role'=>$newRole,'is_active'=>$newActive]; $passwordChanged=filled($data['password']??null); if($passwordChanged)$attrs['password']=$data['password'];
        $user->update($attrs); $fields=$this->allowed($data['correction_fields']??[]); EmployeePermission::updateOrCreate(['user_id'=>$user->id],['correction_fields'=>$fields]);
        if($passwordChanged||!$newActive)$devices->revokeAll($user);
        $audit->log('user.updated',$user,$before,['name'=>$user->name,'username'=>$user->username,'role'=>$user->role->value,'is_active'=>$user->is_active,'correction_fields'=>$fields,'sessions_revoked'=>$passwordChanged||!$newActive]); return back()->with('success','User updated.');
    }
    private function allowed(array $fields): array { return array_values(array_intersect($fields,config('agent_audit.correction_fields',[]))); }
}
