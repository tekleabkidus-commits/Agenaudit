<?php

namespace App\Services\Banking;

use App\Models\PaymentInput;
use App\Models\PaymentRecord;
use App\Enums\PaymentValidationStatus;
use App\Enums\ExternalVerificationStatus;
use App\Enums\TransactionStatus;
use App\Services\Integrations\CheckEtClient;
use App\Services\Transactions\TransactionWorkflowService;
use App\Support\Normalizer;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Carbon\CarbonImmutable;

final class PaymentMessageVerificationService
{
    public function __construct(
        private PaymentMessageParser $parser,
        private CheckEtClient $api,
        private BankResolver $banks,
        private ReceivingAccountMatcher $accounts,
        private TimeRiskService $timeRisk,
        private TransactionWorkflowService $workflow
    ) {}

    public function submit(\App\Models\Transaction $tx, \App\Models\User $employee, string $text): PaymentInput
    {
        if (!$tx->brand_id || !$tx->agent_id || !$tx->type->requiresBankEvidence()) {
            throw ValidationException::withMessages(['messages'=>'Identify the agent before adding payment messages.']);
        }
        $info=$this->parser->parse($text);
        $input=PaymentInput::create([
            'transaction_id'=>$tx->id,'employee_id'=>$employee->id,
            'input_type'=>$info['input_type'],'input_content'=>$text,
            'reference'=>$info['reference'],
            'normalized_reference'=>$info['reference'] ? Normalizer::transactionId($info['reference']) : null,
            'status'=>'pending',
        ]);
        return $this->process($input);
    }

    public function process(PaymentInput $input): PaymentInput
    {
        $tx=$input->transaction()->with('brand')->firstOrFail();
        if (in_array($tx->status,[TransactionStatus::Completed,TransactionStatus::Rejected,TransactionStatus::Cancelled],true)
            || in_array($input->status,['verified','duplicate'],true)) return $input->fresh();

        $info=$this->parser->parse($input->input_content);
        $reference=$info['reference'];
        if ($reference) {
            $normalized=Normalizer::transactionId($reference);
            $duplicates=PaymentInput::where('normalized_reference',$normalized)->whereKeyNot($input->id)->exists();
            $used=PaymentRecord::where('normalized_transaction_id',$normalized)
                ->when($input->payment_record_id,fn($q)=>$q->whereKeyNot($input->payment_record_id))->exists();
            if ($duplicates || $used) return $this->stop($input,'duplicate','Reference already submitted to Agenaudit.');
        }

        // SMS with an official link: verify the URL to minimize personal SMS
        // content sent externally. Never fetch pasted links from our server.
        $type=$info['url']?'receipt_url':$input->input_type;
        $result=$this->api->verifyReceiptInput($type,$info['url']??$input->input_content);
        if (in_array($result->status,['disabled','unavailable'],true))
            return $this->stop($input,'unavailable','Check.et unavailable. Your message is saved; Recheck later.');
        if ($result->status!=='passed')
            return $this->stop($input,'review','Official verification was inconclusive.');

        $json=$result->payload;
        $reference=$reference ?: trim((string)(data_get($json,'data.receipt.transaction_number')
            ?? data_get($json,'data.transaction_number') ?? ''));
        if (!$reference) return $this->stop($input,'review','No unique transaction reference was returned.');
        $normalized=Normalizer::transactionId($reference);
        $reported=data_get($json,'data.receipt.transaction_number') ?? data_get($json,'data.transaction_number');
        if ($reported && Normalizer::transactionId((string)$reported)!==$normalized)
            return $this->stop($input,'review','Receipt reference conflicts with the official record.');

        $bankCode=(string)data_get($json,'data.bank');
        if ($info['bank'] && $bankCode && Normalizer::identifier($bankCode)!==Normalizer::identifier($info['bank']))
            return $this->stop($input,'review','Official bank differs from the receipt.');
        $from=$this->banks->resolve($bankCode ?: $info['bank']);
        if (!$from) return $this->stop($input,'review','The issuing bank is not recognized.');
        $amount=(float)data_get($json,'data.receipt.amount');
        $shown=$info['amount_hint'];
        $principalKnown=($from->check_et_amount_meaning==='transfer_amount')
            || ($shown!==null && abs($shown-$amount)<0.01);

        $officialReceiver=trim((string)data_get($json,'data.receipt.receiver_name',''));
        if ($info['receiver_name'] && $officialReceiver
            && Normalizer::nameSimilarity($officialReceiver,$info['receiver_name'])<0.80)
            return $this->stop($input,'review','Official receiver name does not match the SMS.');
        $receiver=$officialReceiver ?: $info['receiver_name'];

        // Recipient data supplied by an editable SMS alone is not official
        // evidence of where a transfer actually settled.
        $account=trim((string)(data_get($json,'data.receipt.receiver_account')
            ?? data_get($json,'data.receipt.receiver_account_number')
            ?? data_get($json,'data.receipt.receiver_phone') ?? ''));
        $destinationName=(string)(data_get($json,'data.receipt.receiver_bank')
            ?? data_get($json,'data.receipt.destination_bank') ?? '');
        $to=$destinationName ? $this->banks->resolve($destinationName)
            : ($info['bank']==='telebirr' && $info['receiver_account'] ? $from : null);
        $match=$to && $account ? $this->accounts->match($tx->brand,$to,$account,$receiver) : null;
        $date=data_get($json,'data.receipt.transaction_date') ?: $info['transaction_at'];
        try { $when=$date ? CarbonImmutable::parse($date) : null; }
        catch (\Throwable) { $when=null; }
        [$risk,$minutes]=$when ? $this->timeRisk->evaluate($when,$tx->agent_system_at??$when)
            : [\App\Enums\RiskLevel::Normal,null];

        $ok=$principalKnown && $when && $match?->status==='matched';
        $reason=$ok?null:(!$principalKnown?'Transfer amount semantics require review.'
            : (!$when?'Verified transfer has no reliable date.':
                'Verified payment amount, but the destination account is not independently matched.'));
        $values=[
            'transaction_id'=>$tx->id,'evidence_file_id'=>null,'payment_input_id'=>$input->id,
            'from_bank_id'=>$from->id,'to_bank_id'=>$to?->id,
            'receiving_account_id'=>$match?->account?->id,'from_bank_raw'=>$from->name,
            'to_bank_raw'=>$to?->name,'receiver_account'=>$account ?: ($info['receiver_account']??null),
            'receiver_name'=>$receiver,'sender_name'=>data_get($json,'data.receipt.payer_name'),
            'amount'=>$amount,'transaction_id_raw'=>$reference,'normalized_transaction_id'=>$normalized,
            'transaction_at'=>$when,'risk_level'=>$risk,'time_difference_minutes'=>$minutes,
            'account_match_method'=>$match?->method,'account_match_confidence'=>$match?->confidence,
            'internal_status'=>$ok?PaymentValidationStatus::Valid:PaymentValidationStatus::Review,
            'external_status'=>ExternalVerificationStatus::Passed,'external_response'=>$json,
            'external_request_keys'=>$result->requestKeys,'external_checked_at'=>now(),
            'rejection_code'=>$ok?null:'message_amount_or_destination_review','rejection_reason'=>$reason,
        ];

        try {
            DB::transaction(function () use ($input,$values,$normalized,$reference,$ok,$reason) {
                if (PaymentRecord::where('normalized_transaction_id',$normalized)
                    ->when($input->payment_record_id,fn($q)=>$q->whereKeyNot($input->payment_record_id))->exists()) {
                    throw new \RuntimeException('Duplicate verified reference.');
                }
                $record=$input->payment_record_id
                    ? PaymentRecord::findOrFail($input->payment_record_id) : new PaymentRecord();
                $record->fill($values)->save();
                $input->update(['reference'=>$reference,'normalized_reference'=>$normalized,
                    'payment_record_id'=>$record->id,'status'=>$ok?'verified':'review',
                    'failure_code'=>$ok?null:'message_amount_or_destination_review',
                    'failure_reason'=>$reason,'checked_at'=>now()]);
            });
        } catch (\Illuminate\Database\QueryException | \RuntimeException) {
            return $this->stop($input,'duplicate','This transaction reference already exists.');
        }
        $this->workflow->recalculate($tx);
        return $input->fresh();
    }

    private function stop(PaymentInput $input,string $status,string $reason): PaymentInput
    {
        $input->update(['status'=>$status,'failure_code'=>$status,'failure_reason'=>$reason,'checked_at'=>now()]);
        $this->workflow->recalculate($input->transaction);
        return $input->fresh();
    }
}
