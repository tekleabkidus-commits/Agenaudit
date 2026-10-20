# Agenaudit — transaction SMS / official receipt link workflow

## Employee flow
1. Upload the agent-system top-up evidence as before, identifying the agent and the amount credited.
2. On the transaction detail page, choose **Paste bank transaction message or receipt link**.
3. Submit one, two, or three messages/links in the same form. Repeat to add further independent payments to the same agent top-up.
4. Each message is saved encrypted in the Agenaudit database and receives its own Check.et verification and recheck status. Existing screenshot upload remains optional.
5. Where a message contains an approved official receipt URL, Agenaudit sends **only the URL** to Check.et. Otherwise it uses Check.et's `receipt_text` format. Agenaudit does not fetch customer-provided URLs itself.
6. Failed or unavailable API requests can be retried from the same Employee transaction page, without uploading anything again. Already verified inputs are not silently reprocessed.

## Pilot input formats
- Telebirr official receipt URL: `https://transactioninfo.ethiotelecom.et/receipt/DJTESTLINK01`
- Telebirr SMS: `You have transferred ETB 40,000.00 to Example Receiver (2519****1234) on 20/10/2026 06:56:48. Your transaction number is DJTESTTEXT01. The service fee is ETB 6.96 and the VAT is ETB 1.04. Receipt: https://transactioninfo.ethiotelecom.et/receipt/DJTESTTEXT01`

These are **synthetic fixtures**, not customer references. An SMS explicitly stating 40,000 ETB *transferred* supplies that amount as a hint; 6.96 ETB service fee and 1.04 ETB VAT must not be added to the principal.

## Financial safety rules
- The input message is not proof of payment. Require Check.et `success=true`, `exists=true`, official verification method, completed status, positive amount, ETB currency, a unique reference, and identifiable issuing bank.
- Do not treat Check.et's `duplicate=true` as automatic fraud; Agenaudit's own global payment reference controls prevent reuse.
- `data.receipt.amount` is verified financial data but may have bank-specific fee semantics. Count it only if the bank is calibrated to principal amount or an explicitly labelled SMS transfer amount agrees with the official result.
- A **match to the agent top-up total is a reconciliation reference**, not proof of the receiver. The official receiving account (or equivalent trusted receiving-bank evidence) must match the configured brand account. A user-editable SMS recipient, even with a masked phone number, cannot establish actual settlement by itself. Without official receiver-account evidence, leave the payment for Admin review.
- Missing references, mismatched issuer or recipient, duplicate references, or bank API outages remain review/unavailable and do not inflate the valid top-up total.
- Existing screenshot evidence and manual reconciliation features remain available, but are secondary to the new text/link flow.
- First URL allowlist includes only `transactioninfo.ethiotelecom.et`; the allowlist must be extended through reviewed code/configuration for other bank receipt hosts. Any URL is transmitted to Check.et, never opened from Agenaudit servers.

## Deployment and validation
- Requires database migration `2026_10_20_000011_add_payment_message_inputs.php`. No existing bank screenshots are deleted.
- Check.et API key remains in Laravel Cloud environment. No new API credentials.
- Before marking Check.et response semantics as settled principal for a bank, compare controlled transfers across its transaction channels.
- The automated test suite mocks Check.et. It proves the workflow and duplicate/receiver/fee checks, **not** actual API support for a particular receipt link. An authorized end-to-end link test is needed on production.
