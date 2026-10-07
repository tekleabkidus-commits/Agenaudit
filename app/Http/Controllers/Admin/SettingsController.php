<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(SettingsService $settings): View
    {
        return view('admin.settings.edit', [
            'values'=>[
                'ai.enabled'=>$settings->bool('ai.enabled'),
                'ai.agent_extraction_enabled'=>$settings->bool('ai.agent_extraction_enabled', true),
                'ai.bank_extraction_enabled'=>$settings->bool('ai.bank_extraction_enabled', true),
                'ai.quality_check_enabled'=>$settings->bool('ai.quality_check_enabled', true),
                'ai.brand_detection_enabled'=>$settings->bool('ai.brand_detection_enabled', true),
                'ai.min_quality_score'=>$settings->float('ai.min_quality_score', .80),
                'ai.min_critical_confidence'=>$settings->float('ai.min_critical_confidence', .75),
                'ai.auto_accept_confidence'=>$settings->float('ai.auto_accept_confidence', .90),

                'check_et.enabled'=>$settings->bool('check_et.enabled'),
                'check_et.outage_mode'=>$settings->get('check_et.outage_mode','review'),
                'check_et.failure_mode'=>$settings->get('check_et.failure_mode','review'),
                'check_et.timeout_seconds'=>$settings->int('check_et.timeout_seconds',8),
                'check_et.retries'=>$settings->int('check_et.retries',1),

                'validation.warning_minutes'=>$settings->int('validation.warning_minutes',60),
                'validation.serious_minutes'=>$settings->int('validation.serious_minutes',180),
                'validation.alarming_minutes'=>$settings->int('validation.alarming_minutes',360),
                'validation.critical_minutes'=>$settings->int('validation.critical_minutes',720),
                'validation.receiver_name_similarity'=>$settings->float('validation.receiver_name_similarity',.90),
                'validation.require_receiver_name'=>$settings->bool('validation.require_receiver_name',true),

                'credit.default_due_days'=>$settings->int('credit.default_due_days',7),
                'credit.warning_overdue_days'=>$settings->int('credit.warning_overdue_days',1),
                'credit.serious_overdue_days'=>$settings->int('credit.serious_overdue_days',3),
                'credit.critical_overdue_days'=>$settings->int('credit.critical_overdue_days',7),
            ],
        ]);
    }

    public function update(Request $request, SettingsService $settings, AuditLogger $audit): RedirectResponse
    {
        $data=$request->validate([
            'ai_min_quality'=>['required','numeric','between:0.5,1'],
            'ai_min_confidence'=>['required','numeric','between:0.5,1'],
            'ai_auto_accept_confidence'=>['required','numeric','between:0.5,1','gte:ai_min_confidence'],

            'warning_minutes'=>['required','integer','min:1'],
            'serious_minutes'=>['required','integer','gt:warning_minutes'],
            'alarming_minutes'=>['required','integer','gt:serious_minutes'],
            'critical_minutes'=>['required','integer','gt:alarming_minutes'],
            'receiver_similarity'=>['required','numeric','between:0.5,1'],

            'check_et_outage_mode'=>['required','in:review,allow'],
            'check_et_failure_mode'=>['required','in:review,allow'],
            'check_et_timeout_seconds'=>['required','integer','min:2','max:60'],
            'check_et_retries'=>['required','integer','min:0','max:5'],

            'credit_default_due_days'=>['required','integer','min:0','max:365'],
            'credit_warning_days'=>['required','integer','min:0'],
            'credit_serious_days'=>['required','integer','gte:credit_warning_days'],
            'credit_critical_days'=>['required','integer','gte:credit_serious_days'],
        ]);

        $pairs=[
            'ai.enabled'=>$request->boolean('ai_enabled'),
            'ai.agent_extraction_enabled'=>$request->boolean('ai_agent_extraction_enabled'),
            'ai.bank_extraction_enabled'=>$request->boolean('ai_bank_extraction_enabled'),
            'ai.quality_check_enabled'=>$request->boolean('ai_quality_check_enabled'),
            'ai.brand_detection_enabled'=>$request->boolean('ai_brand_detection_enabled'),
            'ai.min_quality_score'=>(float)$data['ai_min_quality'],
            'ai.min_critical_confidence'=>(float)$data['ai_min_confidence'],
            'ai.auto_accept_confidence'=>(float)$data['ai_auto_accept_confidence'],

            'check_et.enabled'=>$request->boolean('check_et_enabled'),
            'check_et.outage_mode'=>$data['check_et_outage_mode'],
            'check_et.failure_mode'=>$data['check_et_failure_mode'],
            'check_et.timeout_seconds'=>(int)$data['check_et_timeout_seconds'],
            'check_et.retries'=>(int)$data['check_et_retries'],

            'validation.warning_minutes'=>(int)$data['warning_minutes'],
            'validation.serious_minutes'=>(int)$data['serious_minutes'],
            'validation.alarming_minutes'=>(int)$data['alarming_minutes'],
            'validation.critical_minutes'=>(int)$data['critical_minutes'],
            'validation.receiver_name_similarity'=>(float)$data['receiver_similarity'],
            'validation.require_receiver_name'=>$request->boolean('require_receiver_name'),

            'credit.default_due_days'=>(int)$data['credit_default_due_days'],
            'credit.warning_overdue_days'=>(int)$data['credit_warning_days'],
            'credit.serious_overdue_days'=>(int)$data['credit_serious_days'],
            'credit.critical_overdue_days'=>(int)$data['credit_critical_days'],
        ];

        foreach($pairs as $key=>$value) {
            $settings->set($key,$value,str($key)->before('.')->toString());
        }

        $audit->log('settings.updated',null,null,$pairs);

        return back()->with('success','Platform settings updated.');
    }
}
