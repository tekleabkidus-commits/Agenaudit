<?php
namespace App\Services\Imports;

use App\Models\Agent; use App\Models\AgentBrandHistory; use App\Models\AgentImport; use App\Models\AgentImportRow; use App\Models\Brand; use App\Models\User;
use App\Services\Audit\AuditLogger; use App\Support\Normalizer; use Illuminate\Http\UploadedFile; use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Storage; use Illuminate\Support\Str; use OpenSpout\Reader\XLSX\Reader; use RuntimeException;

class AgentImportService
{
    public function __construct(private AuditLogger $audit) {}

    public function stage(UploadedFile $file, User $admin): AgentImport
    {
        $disk=config('agent_audit.evidence.disk','private'); $path='imports/agents/'.Str::uuid().'.xlsx'; Storage::disk($disk)->put($path,file_get_contents($file->getRealPath()));
        $import=AgentImport::create(['uploaded_by'=>$admin->id,'original_name'=>$file->getClientOriginalName(),'disk'=>$disk,'path'=>$path,'status'=>'staging']);
        try {
            [$headers,$rawRows]=$this->readWorkbook($file->getRealPath());
            $indexes=array_flip($headers); $max=(int)config('agent_audit.imports.max_agent_rows',50000);
            if(count($rawRows)>$max) throw new RuntimeException("Excel import exceeds the configured {$max}-row limit.");

            $prepared=[]; $ids=[]; $users=[];
            foreach($rawRows as $i=>$row){
                $brandName=trim((string)($row[$indexes['brand name']]??'')); $idRaw=trim((string)($row[$indexes['agent id']]??'')); $userRaw=trim((string)($row[$indexes['agent username']]??''));
                $id=Normalizer::identifier($idRaw); $user=Normalizer::identifier($userRaw); if($id)$ids[]=$id; if($user)$users[]=$user;
                $prepared[]=['row_number'=>$i+2,'brand_name'=>$brandName,'agent_id'=>$idRaw,'agent_username'=>$userRaw,'id_norm'=>$id,'user_norm'=>$user];
            }
            $activeBrands=Brand::where('is_active',true)->get();
            $ambiguousNames=$activeBrands->groupBy(fn(Brand $b)=>Normalizer::name($b->name))
                ->filter(fn($rows)=>$rows->count()>1)
                ->keys();
            if($ambiguousNames->isNotEmpty()) {
                throw new RuntimeException('Brand names are ambiguous after normalization: '.$ambiguousNames->join(', ').'. Rename duplicate brands before importing.');
            }
            $brands=$activeBrands->keyBy(fn(Brand $b)=>Normalizer::name($b->name));
            $existingById=$this->agentsByNormalized('agent_id_normalized',$ids); $existingByUser=$this->agentsByNormalized('username_normalized',$users);
            $seenIds=[]; $seenUsers=[]; $summary=['total'=>0,'valid'=>0,'errors'=>0,'new'=>0,'unchanged'=>0,'move'=>0]; $inserts=[]; $now=now();
            foreach($prepared as $r){
                $summary['total']++; $errors=[]; $brand=$brands->get(Normalizer::name($r['brand_name'])); $id=$r['id_norm']; $user=$r['user_norm'];
                if(!$brand)$errors[]='Brand does not exist or is inactive. Add/activate the brand before importing this agent.'; if(!$id)$errors[]='Agent ID is empty.'; if(!$user)$errors[]='Agent Username is empty.';
                if($id&&isset($seenIds[$id]))$errors[]='Duplicate Agent ID inside this Excel file.'; if($user&&isset($seenUsers[$user]))$errors[]='Duplicate Agent Username inside this Excel file.'; if($id)$seenIds[$id]=true; if($user)$seenUsers[$user]=true;
                $byId=$id?($existingById[$id]??null):null; $byUser=$user?($existingByUser[$user]??null):null; $action=null; $resolved=null;
                if(!$errors){ if(!$byId&&!$byUser)$action='new'; elseif($byId&&$byUser&&$byId->id===$byUser->id){$resolved=$byId;$action=$brand&&$byId->brand_id!==$brand->id?'move':'unchanged';} else $errors[]='Agent ID or username conflicts with another existing platform agent.'; }
                $status=$errors?'error':'valid'; if($errors)$summary['errors']++; else{$summary['valid']++;$summary[$action]++;}
                $inserts[]=['agent_import_id'=>$import->id,'row_number'=>$r['row_number'],'brand_name'=>$r['brand_name'],'agent_id'=>$r['agent_id'],'agent_username'=>$r['agent_username'],'action'=>$action,'status'=>$status,'errors'=>$errors?json_encode($errors):null,'resolved_brand_id'=>$brand?->id,'resolved_agent_id'=>$resolved?->id,'created_at'=>$now,'updated_at'=>$now];
                if(count($inserts)>=1000){AgentImportRow::insert($inserts);$inserts=[];}
            }
            if($inserts)AgentImportRow::insert($inserts);
            $import->update(['status'=>$summary['errors']?'preview_with_errors':'preview','total_rows'=>$summary['total'],'valid_rows'=>$summary['valid'],'error_rows'=>$summary['errors'],'new_rows'=>$summary['new'],'unchanged_rows'=>$summary['unchanged'],'move_rows'=>$summary['move']]);
            $this->audit->log('agents.import_staged',$import,null,$summary,[],$admin); return $import->fresh('rows');
        } catch(\Throwable $e){ $import->update(['status'=>'failed']); throw $e; }
    }

    public function confirm(AgentImport $import, User $admin, bool $allowMoves): AgentImport
    {
        if(!in_array($import->status,['preview','preview_with_errors'],true))throw new RuntimeException('Import is not awaiting confirmation.');
        if($import->error_rows>0)throw new RuntimeException('Fix the invalid Excel rows before confirming this import.');
        if($import->move_rows>0&&!$allowMoves)throw new RuntimeException('This import moves existing agents between brands; explicit move confirmation is required.');
        DB::transaction(function() use($import,$admin){
            foreach($import->rows()->where('status','valid')->orderBy('row_number')->cursor() as $row){
                $brand=Brand::whereKey($row->resolved_brand_id)->where('is_active',true)->lockForUpdate()->first(); if(!$brand) throw new RuntimeException("Brand for row {$row->row_number} no longer exists or is inactive; preview the file again."); $id=Normalizer::identifier($row->agent_id); $user=Normalizer::identifier($row->agent_username);
                $byId=Agent::where('agent_id_normalized',$id)->lockForUpdate()->first(); $byUser=Agent::where('username_normalized',$user)->lockForUpdate()->first();
                if($row->action==='new'){
                    if($byId||$byUser)throw new RuntimeException("Import conflict appeared after preview at row {$row->row_number}; preview the file again.");
                    Agent::create(['brand_id'=>$brand->id,'agent_id'=>$row->agent_id,'agent_id_normalized'=>$id,'username'=>$row->agent_username,'username_normalized'=>$user]);
                } else {
                    if(!$byId||!$byUser||$byId->id!==$byUser->id)throw new RuntimeException("Existing agent changed after preview at row {$row->row_number}; preview the file again.");
                    if($row->action==='move'&&$byId->brand_id!==$brand->id){$from=$byId->brand_id;$byId->update(['brand_id'=>$brand->id]);AgentBrandHistory::create(['agent_id'=>$byId->id,'from_brand_id'=>$from,'to_brand_id'=>$brand->id,'changed_by'=>$admin->id,'reason'=>'Excel import move','changed_at'=>now()]);}
                }
            }
            $import->update(['status'=>'completed','confirmed_at'=>now()]);
        },3);
        $this->audit->log('agents.import_completed',$import,null,['allow_moves'=>$allowMoves],[],$admin); return $import->fresh();
    }

    private function readWorkbook(string $path): array
    {
        $reader=new Reader(); $reader->open($path); $rows=[]; try{foreach($reader->getSheetIterator() as $sheet){foreach($sheet->getRowIterator() as $row)$rows[]=$row->toArray();break;}}finally{$reader->close();}
        if(count($rows)<2)throw new RuntimeException('Excel file must contain a header row and at least one agent row.');
        $headers=array_map(fn($v)=>strtolower(trim((string)$v)),array_shift($rows)); $expected=['brand name','agent id','agent username']; foreach($expected as $h)if(!in_array($h,$headers,true))throw new RuntimeException('Required Excel columns: Brand Name, Agent ID, Agent Username.');
        if(count($headers)!==count(array_unique($headers)))throw new RuntimeException('Excel header names must be unique.');
        return [$headers,$rows];
    }

    /** @return array<string,Agent> */
    private function agentsByNormalized(string $column, array $values): array
    {
        $out=[]; foreach(array_chunk(array_values(array_unique(array_filter($values))),500) as $chunk){foreach(Agent::whereIn($column,$chunk)->get() as $a)$out[$a->{$column}]=$a;} return $out;
    }
}
