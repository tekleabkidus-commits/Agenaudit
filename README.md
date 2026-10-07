# Agent Audit Platform — Laravel 12

Production-oriented Laravel platform for agent balance top-up auditing, credit, commission deposits, withdrawals, AI screenshot extraction, internal validation, and optional Check.et secondary verification.

## Locked business rules implemented

- Two roles: **Admin** and **Employee**.
- Employee normally never selects agent or brand. The agent-system screenshot identifies Agent ID + username; the platform derives brand from the globally unique agent master record.
- Agent ID and Agent Username are globally unique across all brands.
- Admin can add brands. Agents can be added individually or imported from XLSX using: `Brand Name`, `Agent ID`, `Agent Username`.
- One paid top-up can contain multiple bank screenshots and one agent-system screenshot.
- Bank transaction IDs are globally unique across the entire platform; duplicates are hard-rejected by application rules and a database unique constraint.
- Payment records store From Bank → To Bank, sender/receiver name/account, amount, TX ID and timestamp.
- Receiving accounts may be shared by multiple brands. Full or masked account verification uses configured account + receiver name. Wrong receiving accounts are hard-rejected and are never correction-enabled.
- AI-extracted values are read-only to employees. Low-quality or low-confidence critical fields require a clearer screenshot.
- Employee correction requests are configurable per employee and require Admin approval. Original AI values remain in the audit history.
- Payment/top-up timing thresholds are configurable (default 1h warning, 3h serious, 6h alarming, 12h critical). Critical transactions require two confirmations plus employee password.
- Credit is a separate agent debt ledger. Paid deposits and further credit may continue while an agent is in credit, with a visible outstanding-credit warning. Credit limits can be enforced per agent.
- Credit repayment receives bank evidence but does not credit the agent balance again.
- Commission Deposit is enabled per agent, has a configurable monthly limit (default 2), uses agent-system evidence only, and has separate reporting.
- Balance removal/withdrawal uses agent-system evidence and a required reason.
- Check.et is optional and secondary. Internal duplicate/account rules remain authoritative. The Check.et client does not include application domain, brand, agent, employee, or business-purpose fields in its verification payload.
- Persistent device login remains until logout, Admin revocation, user disablement, password reset, or configured long expiry.
- Admin dashboard uses date presets + Total/Brand filter, with Total Deposit, bank percentages, Credit, Withdrawal, Commission and Net Funding.
- Employee UI is mobile-first and does **not** expose company financial totals.

## Local setup

```bash
cp .env.example .env
composer install
php artisan key:generate
# configure PostgreSQL in .env
php artisan migrate --seed
php artisan serve
php artisan queue:work --queue=evidence,default
```

Demo users are seeded only outside production and read credentials from `.env`:

```dotenv
SEED_ADMIN_USERNAME=admin
SEED_ADMIN_PASSWORD=ChangeMe!123
SEED_EMPLOYEE_USERNAME=employee
SEED_EMPLOYEE_PASSWORD=ChangeMe!123
```

Change them before shared/staging use. Production seeding does not create demo users.

## AI setup

### Direct OpenAI vision

```dotenv
AI_ENABLED=true
AI_DRIVER=openai
AI_BASE_URL=https://api.openai.com/v1
AI_API_KEY=...
AI_MODEL=gpt-5.6
```

The included adapter sends the private screenshot server-to-server and requests strict structured JSON. The AI is an extraction assistant only; Laravel still applies every financial validation rule independently.

### Private/custom AI gateway

Set `AI_DRIVER=http`, `AI_ENDPOINT=...`, `AI_API_KEY=...`, and implement `AI_GATEWAY_CONTRACT.md`.

## Check.et

Set `CHECK_ET_API_KEY`, configure supported banks, then enable/disable Check.et from **Admin → Settings**. The platform works without it. The provider never becomes the primary decision maker.

## Key docs

- `AI_GATEWAY_CONTRACT.md` — extraction schema and AI rules
- `SECURITY.md` — authentication, evidence and financial integrity model
- `DEPLOYMENT.md` — production deployment checklist
- `IMPLEMENTATION_STATUS.md` — build status and remaining live-environment work
