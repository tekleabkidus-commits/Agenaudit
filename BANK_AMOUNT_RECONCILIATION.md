# Bank and wallet amount reconciliation — Agenaudit

## Meaning of Check.et `data.receipt.amount`

Check.et's publicly documented v1 response describes this value as the **ETB amount as reported by the bank**. It does not document whether the field is *always* net transferred principal, recipient credit, or a payer debit including fees across every bank and wallet.

**Do not assume or programmatically declare this amount fee-exclusive without provider-specific confirmation.**

Verify at minimum one controlled, authorized receipt for every supported bank/wallet and distinct transaction channel (same-bank, cross-bank, wallet-to-bank, wallet-to-wallet). Prefer several receipts of different amounts, fee tiers and VAT treatment. Confirm with an actual statement/official settlement data where available; a picture is not sufficient. If some channel behaves differently, leave the bank setting `unknown` and route those receipts to review until a per-channel rule exists.

## Required comparison

For each authorized transaction, record the following separately:

1. Institution that issued the reference, and the receiving institution.
2. Receipt reference, status and time (keep sensitive identifiers masked in reports).
3. Explicit principal / transfer amount shown on the source bank's official receipt.
4. Sender's total debit (if present).
5. Service fee, VAT, other charges and a *complete fee total* if present.
6. Actual receiver credit where independently available. Receiving-bank fees can differ from sender fees.
7. Check.et `data.verification_method`, `data.receipt.amount`, `data.receipt.status`, `data.receipt.receiver_name` and currency.
8. Is the Check.et amount equal to principal, receiver credit, or total payer debit? Does this hold across different transaction types?

Do not submit real receipts or expose API secrets in public GitHub issues, browser logs or chat. Keep raw responses and screenshots privately in Agenaudit's evidence storage.

## System behavior (as deployed after this change)

- Gemini extracts the visually **transferred/received amount**, the **payer debit**, **fee components**, optional **total fees**, and whether the visible fee list is complete.
- For **any** bank or wallet: if the transfer amount is clearly labeled, use it without requiring fee fields.
- If only the total sender debit is shown, calculate transfer amount only when a complete, internally consistent receipt fee breakdown is explicit.
- Otherwise ask Check.et. A successful reference verification without confirmed amount semantics must remain in Admin review. Nothing is credited based on an unclassified API amount.
- In Admin → Banks & Wallets, `Check.et amount meaning` defaults to `unknown`.
  - `transfer_amount`: only after reviewing official receipt/API pairs and establishing that Check.et's `amount` is the *transfer principal, excluding sender fees*, for all routes you intend to process automatically.
  - `total_debit`: known payer debit including charges, **not** suitable for inferring principal when fees are absent.
  - `unknown`: conservative default; do not auto-credit ambiguous screenshots.
- If the API is unavailable, nonofficial, missing an amount, or conflicts with the receipt, require Admin review.
- Even a verified *principal* is not necessarily the receiver's *net credited balance* if incoming charges apply; use receiver statements or confirmation for that distinction.

For a complete example:
- Payer debit 30,008 ETB; principal 30,000 ETB; fee 7 ETB plus VAT 1 ETB. The principal is **30,000 ETB**, not the payer debit.
- If the screenshot only displays **30,008 ETB** and Check.et responds with `30008` but its amount semantics are **unknown**, it **cannot** justify a 30,008 ETB top-up.
- If the screenshot labels **30,000 ETB as transferred** and doesn't mention a fee, **30,000 ETB** remains the amount to reconcile.
- If the Check.et response independently confirms `30000` as an official transfer principal for a *calibrated* provider, that amount can be used to reconcile an otherwise ambiguous screenshot, still subject to receiving account and duplicate checks.

## Tests and limitations

The regression suite uses wholly synthetic API payloads for confirmed, unconfirmed, nonofficial, fee-inclusive and fee-absent cases. The tests validate application decisions, **not real Check.et semantics or bank settlement**. A controlled comparison with genuine bank transactions is still needed before marking any provider calibrated.
