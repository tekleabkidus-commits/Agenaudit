# Agent Audit — locked requirements traceability

Checked against the Agenaudit discussions and GitHub code on 2026-10-08.

Legend:
- **Code**: implemented in Laravel source, backed by routes/models/services/views.
- **CI**: automated PHP syntax, migrations, routes, Blade compilation and PHPUnit have been run in GitHub Actions; specific edge cases may still require more tests.
- **Live QA**: requires testing the actual configured Laravel Cloud environment with representative production-like evidence.

## Core identity, brands and agent master data
| Agreed behavior | Evidence in source | Status |
| --- | --- | --- |
| Exactly Admin and Employee roles | `UserRole`, role middleware | Code |
| Admin company-wide access; Employees only own history | policies/controllers | Code |
| Employee can work for one or multiple brands | `brand_user` migration; Users & Permissions create/edit | Code |
| Backend denies Employee transactions for unassigned brands | AgentIdentityService, policies, credit repayment controller | Code |
| Admin dynamically creates/disables brands | BrandController | Code |
| Bank receiving accounts assigned to several brands if needed | ReceivingAccountController and pivot | Code |
| Agent IDs unique across the entire platform | global unique normalized agent indexes | Code + CI |
| Agent usernames unique across the entire platform | global unique normalized username indexes | Code + CI |
| Agent moves between brands preserve history | AgentBrandHistory | Code |
| Add a single agent in Admin | Agents create form/controller | Code |
| Excel import has Brand Name / Agent ID / Agent Username | AgentImportController and XLSX writer | Code |
| Import rejects brand names not currently active/existing | AgentImportService preview and confirm | Code |
| Excel sample template downloadable | AgentImportController::template | Code |

## Evidence and reconciliation
| Agreed behavior | Evidence in source | Status |
| --- | --- | --- |
| Employee uploads agent-system screenshot first | Employee/EvidenceController and workflow | Code |
| AI identifies agent ID and username; brand from master data | AgentIdentityService | Code |
| No Employee brand picker for normal transaction | Employee creation and transaction screens | Code |
| Blurry/insufficient evidence → reupload, not handwritten data | ExtractionGuard, EvidenceProcessor | Code |
| AI ON/OFF plus per-module controls | SettingsController, EvidenceProcessor | Code |
| High confidence automatic; medium Employee confirmation; low reupload | EvidenceProcessor and confirmation routes | Code |
| Read-only extracted fields and Admin-approved corrections | Employee view, correction workflow | Code |
| Original screenshot, extraction and requested changes retained | evidence/corrections/audit models | Code |
| Receiver bank/account/name never correctable | config, CorrectionService | Code |
| Agent-screen before/after amount and reference retained | AgentIdentityService, transaction fields | Code |
| Agent before/after amount reconciles when both values visible | AgentIdentityService | Code; Live QA |
| 1–12 bank receipts per paid top-up | Employee/EvidenceController | Code |
| Explicit From Bank → To Bank; sender/receiver/amount/reference/time | payment schema and model | Code |
| Receiving-account full or masked match requires uniqueness | ReceivingAccountMatcher | Code + CI |
| Wrong receiver hard rejected, excluded from paid totals | PaymentVerificationService, workflow | Code + CI |
| Duplicate bank reference globally blocked, previous attempt linked | global unique index, duplicate_of_payment_id | Code + CI |
| Failed or pending bank receipt cannot count as valid | PaymentVerificationService | Code + CI |
| Aggregated valid receipts reconcile to the agent-system amount | TransactionWorkflowService | Code + CI |
| Never finalize while another screenshot is unprocessed | TransactionWorkflowService | Code + CI |
| Late AI jobs do not update terminal transactions | EvidenceProcessor | Code + CI |
| Configurable 1h/3h/6h/12h time risk and critical double confirmation | TimeRiskService, Admin settings | Code |

## Financial workflow
| Agreed behavior | Evidence in source | Status |
| --- | --- | --- |
| Paid Top-Up, Give Credit, Remove Balance, Commission Deposit | TransactionType, Employee UI | Code |
| Credit Repayment as separate financial event | CreditLedgerService, Employee UI | Code |
| No automatic credit repayment from new paid top-up | workflow branching | Code |
| Credit issuance allowed when existing outstanding credit exists | workflow, credit warning | Code |
| Additional credit subject to per-agent limit | agent settings/workflow | Code |
| Credit issued/partial/paid records, due dates, aging, allocations | CreditRecord, CreditRepaymentAllocation, Admin credit page | Code |
| Commission enabled/disabled per agent by Admin | dedicated Commission Management controller/page | Code |
| Commission max uses/month, default two, used/remaining | commission controller/Employee and Admin views | Code |
| Commission screenshot only, no initial bank receipt | TransactionType screenshot rules | Code |
| Commission-only report | Reports filtering | Code |
| Remove Balance requires screenshot and reason | Employee workflow | Code |
| Removal reason presets; Other requires notes | Employee controller/view | Code |
| Financial records and evidence have audit events, no routine deletion | workflow/audit models | Code |

## Admin and Employee experience
| Agreed behavior | Evidence in source | Status |
| --- | --- | --- |
| Admin single date + brand filters (dynamic brands) | dashboard controllers/views | Code |
| Deposit hero card with by-bank share, credit, withdrawal and charts | DashboardService and Blade | Code |
| Custom date windows in reports | DateRange/ReportController | Code |
| Admin overview, transactions, approval queue, credits, commission, agents, banks, brands, users, reports, audit | routes and Blade | Code |
| Employee has no company totals | Employee HomeController | Code |
| Employee mobile-first screenshot flow and own activity | Employee Blade, responsive stylesheet | Code |
| Modern bespoke Admin/Employee visual styling and mobile navigation | main Blade layout and Fintech UI v2 styles | Code; visual review advised |
| Report CSV scalable and spreadsheet-injection safe | ReportController streaming export | Code |
| Admin device token/session revocation | DeviceSessionService/SessionController | Code |

## Check.et and privacy
| Agreed behavior | Evidence in source | Status |
| --- | --- | --- |
| Secondary verification optional; own rules are primary | CheckEtClient and SettingsController | Code |
| No site domain, brand, agent, employee or purpose in payload | CheckEtClient::buildPayload | Code + CI |
| Bank code/provider catalog, including 10 documented codes | bank metadata migration | Code + CI |
| CBE Birr uses payer phone/account where required | bank-specific account source | Code + CI |
| Check.et failure → Admin review (override audit logged) | TransactionWorkflowService, Admin | Code |
| No screenshots transmitted to Check.et | CheckEtClient | Code |
| Enable/disable, retries, timeout, per-bank routing | Admin settings/banks | Code |
| Provider availability/response correctness with real Check.et key | Bank live verification test matrix | **Live QA** |

## Production verification — not silently claimed as complete
- Automated GitHub CI exists (`.github/workflows/ci.yml`) and has passed PHP 8.4 lint, migration, tests, routes, and Blade compilation.
- Composer dependency versions are pinned in `composer.lock`.
- The public Laravel Cloud `/up` endpoint and login route were reachable in GitHub Actions smoke checks.
- Cloud auto-deploy of the latest SHA, private object storage association and permissions, background queue service health, real API keys, screenshot extraction accuracy, concurrency on PostgreSQL, back-ups and recovery tests remain **environment-dependent checks**.
- There are two roles. This is intentionally a bespoke Blade UI, not a Filament-generated admin. Switching to Filament would be a separate architecture change and is not required by the business rules.
- This system records/audits actions from the external agent system; it does not directly move balances in that other system.

Never process live financial evidence until the required Cloud resources and the live-QA checks above are completed.
