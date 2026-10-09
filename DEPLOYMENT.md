# Production Deployment

Recommended stack:

- PHP 8.4 + Laravel 12
- PostgreSQL 16+
- Redis for cache/queues in production
- Nginx/Caddy + HTTPS
- S3-compatible private object storage for evidence
- Supervisor/systemd for queue workers

## Fast AI screenshot reading

Agent-system proof images are read **during the upload request** by default.
There is no queue wait for that initial identity/top-up step; the page opens
when Gemini returns or its bounded attempt fails. A literal instantaneous
result is impossible (upload time, image analysis and provider latency vary).
Set `AI_AGENT_FAST_PATH=true` explicitly on Laravel Cloud if desired, and
`AI_AGENT_FAST_TIMEOUT_SECONDS=18` to bound the Gemini request. A stalled
older agent proof has a **Read screenshots now** action on its page.

**Keep a background queue worker running for bank receipts and Check.et
rechecks.** Up to 12 bank receipts per transaction are intentionally processed
in the background so their combined AI/API latency does not cause the web
request to time out. The Employee transaction page polls a lightweight,
authorization-checked status endpoint and refreshes when extraction progresses.
For these longer operations use a persistent Laravel Cloud background process
with `php artisan queue:work --queue=evidence,default --sleep=1 --tries=3 --timeout=120`.

## Required processes

```bash
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:work --queue=evidence,default --sleep=1 --tries=3 --timeout=120
```

Schedule Laravel's scheduler once per minute if future cleanup/monitoring jobs are enabled:

```cron
* * * * * cd /var/www/agent-audit && php artisan schedule:run >> /dev/null 2>&1
```

## Persistent login

The configured 25-year persistent token means "remember this device". It remains revocable. It is intentionally not a never-expiring password stored in a browser.

## AI

Included options:

```dotenv
AI_ENABLED=true
AI_DRIVER=openai
AI_BASE_URL=https://api.openai.com/v1
AI_API_KEY=...
AI_MODEL=...
```

or use `AI_DRIVER=http` and point `AI_ENDPOINT` to a private gateway implementing `AI_GATEWAY_CONTRACT.md`.

## Check.et

```dotenv
CHECK_ET_ENABLED=false
CHECK_ET_API_KEY=...
CHECK_ET_BASE_URL=https://api.check.et
```

The Admin can enable/disable secondary verification from Platform Settings without disabling the internal validation engine.
