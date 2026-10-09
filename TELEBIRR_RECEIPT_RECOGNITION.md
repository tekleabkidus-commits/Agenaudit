# Telebirr receipt recognition v1 — Agenaudit

## Scope
The 20 example screenshots supplied for this design cover Telebirr invoice/PDF, green successful transfer, blue payment-success receipt, and mini-app transaction detail formats. No original financial screenshots, personal account identifiers, QR content, or private reference numbers are stored in the source repository.

## Extraction and validation
Gemini now receives a schema that separates **explicit sending institution**, receiving bank/wallet, transfer type, screenshot-template family, observed visual clues, debit amount, settled/transfer amount, service fee and fee VAT.

The backend independently combines the observed markers to decide whether the sender is likely Telebirr:
- `telebirr_pdf`: the Ethio telecom header *and* explicit Telebirr payment mode.
- `telebirr_green_success`: successful green check, DJ reference, transaction-to label, *plus at least two* of the extra reference-template clues (banner, green QR label, download/share bar, Telebirr receipt URL).
- `telebirr_blue_success`: blue success mark, payment-method heading, QR receipt, DJ reference.
- `telebirr_miniapp`: storefront icon, transaction-detail header, invoice link, DJ reference.

Names in advertising banners do NOT establish the sender bank. DJ transaction ID format alone is not sufficient. If the model supplies a conflicting sender without a visible institution label, treat the sender as unknown pending review. Missing destination bank never proves a Telebirr-to-Telebirr transfer.

The destination is identified independently. A phone number plus explicit wallet-to-wallet language can identify a Telebirr wallet; a named destination such as Bank of Abyssinia is not treated as the issuer of the transfer reference.

**Recognition is not bank verification.** It is a fallible visual categorization. It cannot prove receipt authenticity, settlement, or transfer to an approved receiving account. The financial workflow still enforces configured company receiving accounts, the reference deduplication registry, and Check.et response handling. Unverifiable evidence must not become an approved payment.

## Amounts, fees and VAT
An outbound `-30,008.00 ETB` display can mean a 30,000 ETB transfer plus 8 ETB fees. The system only uses a net amount when an official settled amount is visible, or when a total debit and explicit fee components are present, and the arithmetic agrees.

When the displayed amount could include unknown fees, set the transaction aside for Admin review; **never subtract a guessed flat fee**.

## Check.et routing
The bank/wallet that issued the transaction reference is the look-up issuer. For Telebirr → Bank of Abyssinia, Check.et should check the Telebirr-issued reference against the Telebirr verifier when supported, while the approved receiving account remains BOA. Provider verification availability and settlement checks remain distinct.

## Validation / rollout
The test suite has synthetic positive and negative examples for PDF, green, wallet-to-bank, source conflict, missing issuer and destinations, generic green receipts, and fee ambiguity. Real-world classification accuracy has **not** yet been measured; run a controlled validation set from these and other banks (especially similar green UIs) before enabling unattended approvals.

Keep originals in private evidence storage; do not commit customer receipts or use them to train a model without a lawful basis and consent/contract safeguards. The external Gemini privacy terms remain applicable.
