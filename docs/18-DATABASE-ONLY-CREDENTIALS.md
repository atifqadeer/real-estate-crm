# 18 — Platform credentials live only in the database (2026-11-26)

Atif's rule: **no environment variables for the API key or any platform
credentials — they belong in the database.** Implemented the same day.

## What changed

- `backend/config/services.php` — the Meta, LinkedIn and AI sections no
  longer read any `env()` values. Credential fields (`app_id`,
  `app_secret`, `redirect`, `webhook_verify_token`, `client_id`,
  `client_secret`, `key`) default to `null` and are filled at boot from
  the `platform_settings` table by `AppServiceProvider` /
  `PlatformSettings::applyToConfig()`. Only non-secret operational
  defaults remain in config (Graph version `v27.0`, OAuth scopes,
  LinkedIn version `202605`, AI endpoint/model) — and even those can be
  overridden from the Platform Settings page.
  (`FRONTEND_URL` stays an env value: it is the deployment's own URL,
  not a credential.)
- `backend/.env.example` — all `META_*`, `LINKEDIN_*` and `AI_*`
  variables removed, replaced with a note explaining credentials are
  managed in the app under Platform Settings.
- Status API — a field's `source` is now `database`, `default`
  (built-in non-secret default) or `null`. The settings page badge reads
  "from database" / "built-in default"; the "from .env" badge is gone,
  as is the page copy about overriding `.env`.
- Error messages — Meta, LinkedIn and AI "not configured" errors now
  point only to the Platform Settings page and state that credentials
  are stored in the database, never in environment variables.
- `AdvancedIntegrationsTest` updated to assert the new AI message.

## Behaviour

- Fresh install: integrations report "not configured" until an agency
  admin saves credentials on the Platform Settings page. There is no
  other way for credentials to enter the system.
- WhatsApp was already database-only (per-account token in
  `whatsapp_accounts`, encrypted) and is unchanged.

## Verification

- Full backend suite: **86 passed**.
- Frontend build clean.
- Browser-verified as agency admin: page shows the database-only copy,
  no ".env" badge anywhere, Meta group "Configured" purely from
  database values, unset credentials show as missing (no fallback).
