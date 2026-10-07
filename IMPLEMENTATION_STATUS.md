# Implementation Status

## Implemented in code

### Identity & access
- Admin / Employee roles only.
- Username/password login with throttling.
- Persistent remembered-device session token with server-side revocation.
- User disable/password-change session revocation.
- Admin device-session management.
- Security headers and no-referrer policy.

### Master data
- Brand CRUD.
- Bank catalog.
- Receiving accounts assigned to one or many brands.
- Global-unique Agent ID and username.
- Single-agent management and XLSX import with preview/confirmation.
- Agent brand-move audit history.
- Per-agent credit + commission settings.

### Evidence & AI
- Private screenshot storage.
- Multiple bank screenshots + one agent-system screenshot.
- Queue-based processing.
- Generic HTTP AI gateway driver.
- Direct OpenAI image extraction driver with strict structured-output schema.
- Quality/confidence guard and clearer-screenshot workflow.
- Admin manual extraction only when AI is disabled.
- Admin revalidation after master-data fixes.

### Financial validation
- Agent/brand automatic matching from extracted Agent ID + username.
- From-bank → To-bank capture.
- Full/masked receiving-account matching with receiver-name validation.
- Platform-global bank transaction ID unique constraint and hard reject.
- Amount aggregation and comparison against one agent-system top-up.
- Configurable payment-to-top-up risk thresholds.
- Critical two-step confirmation + password.
- Optional Check.et secondary verification and retry/Admin review path.

### Transaction types
- Paid Top-Up.
- Credit issue.
- Credit repayment.
- Balance removal / withdrawal.
- Commission Deposit.
- Credit ledger and outstanding-credit warnings/limits.
- Per-agent monthly commission eligibility/limit.

### Admin UI
- Filterable dashboard with deposit bank percentages.
- Transaction review/detail/revalidate/external override.
- Correction approval queue.
- Brands, banks, receiving accounts, agents, imports.
- Users + per-employee correction permissions.
- Validation/AI/Check.et settings.
- Reports + CSV export.
- Audit log.
- Persistent device sessions.

### Employee UI
- Mobile-first home and transaction history.
- No company financial totals.
- Transaction-type creation.
- Agent evidence first; brand/agent derived automatically.
- Multiple bank screenshot upload.
- Read-only extracted values.
- Credit warning, correction request, critical confirmations, finalization/cancel.

## Environment-dependent work before production launch

These require a real deployment environment or live credentials rather than additional product specification:

1. Run `composer install`, migrations and the PHPUnit suite in CI/staging.
2. Configure PostgreSQL/Redis/private object storage.
3. Add the chosen AI API key/model and validate extraction against a representative bank/agent screenshot set.
4. Add Check.et credentials and test each enabled bank's live verification behavior.
5. Load real brands, bank accounts and agent master XLSX files.
6. Run concurrency/load tests with realistic transaction volume.
7. Security review, backup/restore test and deployment monitoring.

This workspace does not currently have Composer/network access, so Laravel framework boot tests cannot be executed here. All project PHP source is syntax-linted locally before packaging, and pure-PHP regression checks are used for logic that does not require Laravel's vendor tree.
