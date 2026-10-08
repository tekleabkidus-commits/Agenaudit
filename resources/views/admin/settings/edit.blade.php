@extends('layouts.app')
@section('title','Platform Settings')
@section('topbar','Platform Settings')
@section('content')

<div class="page-head">
    <div>
        <span class="eyebrow">PLATFORM CONTROL CENTER</span>
        <h2>Verification, AI & Risk Rules</h2>
        <p>Internal validation is always authoritative. Configure automation without weakening duplicate or receiving-account controls.</p>
    </div>
</div>

<form method="post" action="{{ route('admin.settings.update') }}" class="stack">
    @csrf
    @method('PUT')

    <div class="settings-grid">
        <section class="card settings-card">
            <div class="card-title-row">
                <div>
                    <span class="eyebrow">AI ENGINE</span>
                    <h3>Screenshot Intelligence</h3>
                </div>
                <label class="modern-switch compact">
                    <input type="checkbox" name="ai_enabled" value="1" @checked($values['ai.enabled'])>
                    <span class="modern-switch-ui"></span>
                </label>
            </div>

            <div class="setting-list">
                <label class="setting-row">
                    <span><b>Agent screenshot extraction</b><small>Read Agent ID, username, amount, balances and timestamp.</small></span>
                    <span class="modern-switch compact"><input type="checkbox" name="ai_agent_extraction_enabled" value="1" @checked($values['ai.agent_extraction_enabled'])><span class="modern-switch-ui"></span></span>
                </label>
                <label class="setting-row">
                    <span><b>Bank screenshot extraction</b><small>Read FROM → TO, accounts, names, amount, reference and time.</small></span>
                    <span class="modern-switch compact"><input type="checkbox" name="ai_bank_extraction_enabled" value="1" @checked($values['ai.bank_extraction_enabled'])><span class="modern-switch-ui"></span></span>
                </label>
                <label class="setting-row">
                    <span><b>Quality & confidence gate</b><small>Reject unreadable evidence and pause medium-confidence results for Employee confirmation.</small></span>
                    <span class="modern-switch compact"><input type="checkbox" name="ai_quality_check_enabled" value="1" @checked($values['ai.quality_check_enabled'])><span class="modern-switch-ui"></span></span>
                </label>
                <label class="setting-row">
                    <span><b>Brand hint detection</b><small>Use visual/text brand hints only as a secondary signal. Agent master data remains authoritative.</small></span>
                    <span class="modern-switch compact"><input type="checkbox" name="ai_brand_detection_enabled" value="1" @checked($values['ai.brand_detection_enabled'])><span class="modern-switch-ui"></span></span>
                </label>
            </div>

            <div class="form-grid three" style="margin-top:16px">
                <div class="field"><label>Minimum image quality</label><input class="input" type="number" step=".01" min=".5" max="1" name="ai_min_quality" value="{{ $values['ai.min_quality_score'] }}"></div>
                <div class="field"><label>Minimum confidence</label><input class="input" type="number" step=".01" min=".5" max="1" name="ai_min_confidence" value="{{ $values['ai.min_critical_confidence'] }}"><small>Below this → clearer screenshot</small></div>
                <div class="field"><label>Auto-accept confidence</label><input class="input" type="number" step=".01" min=".5" max="1" name="ai_auto_accept_confidence" value="{{ $values['ai.auto_accept_confidence'] }}"><small>Between min and this → Employee confirmation</small></div>
            </div>
        </section>

        <section class="card settings-card">
            <div class="card-title-row">
                <div><span class="eyebrow">SECONDARY VERIFICATION</span><h3>Check.et</h3></div>
                <label class="modern-switch compact"><input type="checkbox" name="check_et_enabled" value="1" @checked($values['check_et.enabled'])><span class="modern-switch-ui"></span></label>
            </div>

            <div class="form-grid">
                <div class="field"><label>Provider outage</label><select class="select" name="check_et_outage_mode"><option value="review" @selected($values['check_et.outage_mode']==='review')>Send to Admin review</option><option value="allow" @selected($values['check_et.outage_mode']==='allow')>Allow internal verification</option></select></div>
                <div class="field"><label>Verification failed</label><select class="select" name="check_et_failure_mode"><option value="review" @selected($values['check_et.failure_mode']==='review')>Send to Admin review</option></select></div>
                <div class="field"><label>Timeout seconds</label><input class="input" type="number" min="2" max="60" name="check_et_timeout_seconds" value="{{ $values['check_et.timeout_seconds'] }}"></div>
                <div class="field"><label>Automatic retries</label><input class="input" type="number" min="0" max="5" name="check_et_retries" value="{{ $values['check_et.retries'] }}"></div>
            </div>

            <div class="alert info" style="margin-top:14px">
                Only provider-required verification fields are sent. Domain, brand, agent, employee, transaction purpose and internal business context are not included.
            </div>
        </section>

        <section class="card settings-card">
            <div><span class="eyebrow">TIME DIFFERENCE</span><h3>Payment Risk Thresholds</h3></div>
            <div class="form-grid">
                <div class="field"><label>Warning after</label><div class="input-suffix"><input class="input" type="number" name="warning_minutes" value="{{ $values['validation.warning_minutes'] }}"><span>min</span></div></div>
                <div class="field"><label>Serious after</label><div class="input-suffix"><input class="input" type="number" name="serious_minutes" value="{{ $values['validation.serious_minutes'] }}"><span>min</span></div></div>
                <div class="field"><label>Very alarming after</label><div class="input-suffix"><input class="input" type="number" name="alarming_minutes" value="{{ $values['validation.alarming_minutes'] }}"><span>min</span></div></div>
                <div class="field"><label>Critical / double confirm</label><div class="input-suffix"><input class="input" type="number" name="critical_minutes" value="{{ $values['validation.critical_minutes'] }}"><span>min</span></div></div>
            </div>
        </section>

        <section class="card settings-card">
            <div><span class="eyebrow">ACCOUNT SAFETY</span><h3>Receiving Account Validation</h3></div>
            <div class="field"><label>Receiver-name similarity</label><input class="input" type="number" step=".01" min=".5" max="1" name="receiver_similarity" value="{{ $values['validation.receiver_name_similarity'] }}"></div>
            <label class="setting-row" style="margin-top:12px">
                <span><b>Require receiver full name</b><small>Especially important for masked account numbers.</small></span>
                <span class="modern-switch compact"><input type="checkbox" name="require_receiver_name" value="1" @checked($values['validation.require_receiver_name'])><span class="modern-switch-ui"></span></span>
            </label>
            <div class="alert danger-soft" style="margin-top:14px">Wrong receiving account, receiver identity and global duplicate transaction ID are hard rules and are never Employee-editable.</div>
        </section>

        <section class="card settings-card">
            <div><span class="eyebrow">CREDIT AGING</span><h3>Credit Due & Escalation</h3></div>
            <div class="form-grid">
                <div class="field"><label>Default due period</label><div class="input-suffix"><input class="input" type="number" min="0" max="365" name="credit_default_due_days" value="{{ $values['credit.default_due_days'] }}"><span>days</span></div><small>Agents may override this individually. 0 = no due date.</small></div>
                <div class="field"><label>Warning overdue</label><div class="input-suffix"><input class="input" type="number" min="0" name="credit_warning_days" value="{{ $values['credit.warning_overdue_days'] }}"><span>days</span></div></div>
                <div class="field"><label>Serious overdue</label><div class="input-suffix"><input class="input" type="number" min="0" name="credit_serious_days" value="{{ $values['credit.serious_overdue_days'] }}"><span>days</span></div></div>
                <div class="field"><label>Critical overdue</label><div class="input-suffix"><input class="input" type="number" min="0" name="credit_critical_days" value="{{ $values['credit.critical_overdue_days'] }}"><span>days</span></div></div>
            </div>
        </section>
    </div>

    <div class="sticky-save">
        <div><b>Platform rules</b><span class="tiny muted">Changes are recorded in the Audit Log.</span></div>
        <button class="btn btn-primary btn-lg">Save Settings</button>
    </div>
</form>
@endsection
