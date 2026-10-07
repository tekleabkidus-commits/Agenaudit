# Production Deployment

Recommended stack:

- PHP 8.4 + Laravel 12
- PostgreSQL 16+
- Redis for cache/queues in production
- Nginx/Caddy + HTTPS
- S3-compatible private object storage for evidence
- Supervisor/systemd for queue workers

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
