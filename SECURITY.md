# Security Model

## Authentication

- Laravel session authentication for active browser use.
- A separate random persistent-device token supports "stay signed in until logout/Admin revocation".
- Only the SHA-256 hash of the persistent-device token is stored in the database.
- Disabling a user or changing a password revokes persistent device sessions.
- Admin can revoke one device or all devices for a user.
- Production must use HTTPS and `DEVICE_COOKIE_SECURE=true` / `SESSION_SECURE_COOKIE=true`.

## Financial integrity

The database, not the browser, enforces the most important invariants:

- `agents.agent_id_normalized` is globally unique.
- `agents.username_normalized` is globally unique.
- `payment_records.normalized_transaction_id` is globally unique platform-wide.
- Receiving-account mismatch is a hard rejection.
- Completed financial history is never exposed through delete routes.
- Credit/commission completion uses transactional locking around the affected agent.
- Original AI extraction remains stored when a correction is requested/approved.

## Evidence

- Evidence files are stored on a private filesystem disk.
- Evidence is served only through an authenticated/authorized controller.
- File hashes are stored for traceability.
- Employees cannot edit extracted evidence fields.
- Blurry or low-confidence critical evidence requires a replacement screenshot.

## External services

### AI
AI calls are made server-to-server. The browser never receives the API key.

### Check.et
Check.et is optional and secondary. The client intentionally constructs a minimal request containing only the provider/bank transaction reference and an account number only where required by that bank's verification method. Application domain, brand, agent, employee, and business-purpose fields are not included in the verification payload. The provider can still associate the request with the Check.et API account and backend network connection.

## Production checklist

- Set `APP_ENV=production`, `APP_DEBUG=false`.
- Use PostgreSQL, Redis, HTTPS and encrypted backups.
- Keep screenshots in private S3-compatible/object storage with server-side encryption and lifecycle rules.
- Store all API secrets in the deployment secret store, not source control.
- Run queue workers under a process supervisor.
- Restrict Admin accounts and rotate the seeded demo credentials or do not seed demo users in production.
- Configure database backups and perform restore drills.
- Monitor failed queue jobs and external verification failures.
