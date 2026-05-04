# DisputeShield

Auto-submit Stripe dispute evidence from PostHog data.

## Files
- `webhook.php` — Stripe webhook handler
- `disputes.php` — Admin dashboard  
- `workers/sync.php` — Sync historical disputes (run via CLI)
- `app/evidence.php` — Evidence builder
- `app/posthog.php` — PostHog queries
- `app/db.php` — SQLite database

## Environment Variables (set in Railway)
- `STRIPE_SECRET_KEY`
- `STRIPE_WEBHOOK_SECRET`
- `POSTHOG_API_KEY`
- `POSTHOG_PROJECT_ID` (default: 304522)
- `TELEGRAM_BOT_TOKEN`
- `TELEGRAM_CHAT_ID`
- `DASH_KEY` — password for /disputes?key=
- `SITE_NAME`

## Dashboard
https://your-app.railway.app/disputes?key=YOUR_DASH_KEY
