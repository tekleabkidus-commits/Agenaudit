<?php

namespace App\Services\Banking;

use Illuminate\Validation\ValidationException;

/**
 * Only parse explicit bank evidence fields. SMS is not proof of settlement.
 * Nothing here dereferences a user-controlled receipt URL.
 */
final class PaymentMessageParser
{
    /** @return array<string,mixed> */
    public function parse(string $raw): array
    {
        $raw=trim($raw);
        if ($raw === '' || mb_strlen($raw)>6000) {
            throw ValidationException::withMessages(['messages'=>'Provide a transaction message or official receipt URL (maximum 6,000 characters).']);
        }

        $isUrl=filter_var($raw,FILTER_VALIDATE_URL)!==false;
        $url=null;
        $text=$isUrl?null:$raw;
        if ($isUrl) {
            $url=$this->safeReceiptUrl($raw);
        } elseif (preg_match_all('~https?://[^\s<>"\x27]+~i',$raw,$links)) {
            foreach ($links[0] as $link) {
                $candidate=$this->safeReceiptUrl(rtrim($link, ".,;)]"));
                if ($url!==null && $candidate!==$url) {
                    throw ValidationException::withMessages(['messages'=>'Message contains multiple different receipt links. Submit one transaction at a time.']);
                }
                $url=$candidate;
            }
        }

        $reference=null;
        $bank=null;
        if ($url && preg_match('~^https://transactioninfo\.ethiotelecom\.et/receipt/(DJ[A-Z0-9]{7,30})/?$~i',$url,$m)) {
            $reference=strtoupper($m[1]);
            $bank='telebirr';
        }

        // Telebirr text explicitly labels its transaction number.
        if ($text && preg_match('/transaction\s+(?:number|no\.?|id)\s*(?:is|:)?\s*(DJ[A-Z0-9]{7,30})\b/i',$text,$m)) {
            $smsReference=strtoupper($m[1]);
            if ($reference && $reference!==$smsReference) {
                throw ValidationException::withMessages(['messages'=>'Receipt link and message contain different transaction references.']);
            }
            $reference=$smsReference;
            $bank='telebirr';
        }

        // For other providers, the Check.et parser can identify the bank;
        // never infer the bank from FT-style prefixes alone.
        if (!$reference && $text && preg_match('/\b(FT[A-Z0-9]{8,30})\b/i',$text,$m)) {
            $reference=strtoupper($m[1]);
        }

        $explicitAmount=null;
        $serviceFee=null;
        $feeVat=null;
        $receiverAccount=null;
        $receiverName=null;
        $timestamp=null;
        if ($text && $bank==='telebirr') {
            if (preg_match('/\btransferred\s+ETB\s*([\d,]+(?:\.\d{1,2})?)\s+to\s+(.+?)\s*\(([\d*Xx]{8,25})\)\s+on\s+(\d{2}\/\d{2}\/\d{4}\s+\d{2}:\d{2}:\d{2})/is',$text,$m)) {
                $explicitAmount=(float)str_replace(',','',$m[1]);
                $receiverName=trim(preg_replace('/\s+/',' ',$m[2]));
                $receiverAccount=$m[3];
                try { $timestamp=\Carbon\CarbonImmutable::createFromFormat(
                    '!d/m/Y H:i:s',trim($m[4]),'Africa/Addis_Ababa'
                )->toIso8601String(); }
                catch (\Throwable) {}
            }
            if (preg_match('/service fee is\s+ETB\s*([\d,]+(?:\.\d{1,2})?)/i',$text,$m)) $serviceFee=(float)str_replace(',','',$m[1]);
            if (preg_match('/VAT on the service fee is\s+ETB\s*([\d,]+(?:\.\d{1,2})?)/i',$text,$m)) $feeVat=(float)str_replace(',','',$m[1]);
        }

        return [
            'input_type'=>$isUrl?'receipt_url':'receipt_text',
            'url'=>$url,'reference'=>$reference,
            'bank'=>$bank,'amount_hint'=>$explicitAmount,
            'sender_fee'=>$serviceFee,'sender_fee_vat'=>$feeVat,
            'receiver_name'=>$receiverName,'receiver_account'=>$receiverAccount,
            'transaction_at'=>$timestamp,
        ];
    }

    private function safeReceiptUrl(string $url): string
    {
        $components=parse_url($url);
        $scheme=strtolower((string)($components['scheme']??''));
        $host=strtolower((string)($components['host']??''));
        $allowed=(array)config('agent_audit.evidence.allowed_receipt_hosts',[
            'transactioninfo.ethiotelecom.et',
        ]);
        if ($scheme!=='https' || !in_array($host,$allowed,true)
            || isset($components['user']) || isset($components['pass'])
            || isset($components['port']) || isset($components['fragment'])
            || isset($components['query'])) {
            throw ValidationException::withMessages(['messages'=>'Only approved HTTPS bank receipt links are accepted. Paste the SMS text instead if your link is not yet supported.']);
        }
        return $url;
    }
}
