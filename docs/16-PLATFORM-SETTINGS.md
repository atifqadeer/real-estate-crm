# 16 — Platform Settings (integration credentials in the database)

Requested 2026-11-25: a settings page holding the Meta / other
platform requirements, saved in a database settings table instead of
only in `.env`.

## What was built

- **`platform_settings` table** (global key/value; distinct from the
  per-workspace `client_settings`). Secrets are stored **encrypted**
  (Laravel Crypt) and are never returned by the API.
- **`PlatformSettings` service** defining every field for Meta
  (App ID, App Secret, OAuth Redirect URI, Webhook Verify Token,
  Graph version, Scopes), LinkedIn (Client ID/Secret, Redirect URI,
  Scopes, Version) and the AI assistant (Base URL, API Key, Model).
  At boot, database values **override** `config('services.*')`, so all
  existing consumers (MetaGraphClient, webhook controllers, LinkedIn
  client, ads sync) use them with no code changes. `.env` remains the
  fallback when a value is not in the database.
- **API:** `GET/PUT /api/v1/admin/platform-settings`, **agency staff
  only** (`is_agency_staff`). GET returns per-field status
  (set / missing, source: database or env) with secret values masked,
  plus the callback/webhook URLs to paste into provider dashboards.
  On save, a blank secret keeps the existing value; `null` clears a
  value. (Note: Laravel converts `''` to `null` in transit — the
  controller treats both as "keep" for secrets.)
- **Frontend:** new **Platform Settings** sidebar page (visible only
  to agency staff) with a section per provider, Configured/Incomplete
  badges, per-field help text, and the URLs card. Client users never
  see it; clients still connect their own Pages via OAuth as before.
- Also fixed the audit's scope gaps: default `META_SCOPES` (config +
  `.env.example`) now include `leads_retrieval` and
  `pages_manage_metadata`.

## Tests

`PlatformSettingsTest` (4 tests): staff-only access, encrypted
storage + masking, database values driving the Meta client and the
live webhook verification handshake, blank-secret-keeps / null-clears.
Full suite: **86 passed**. Verified in the browser: saved Meta values
through the page and reloaded it — Meta shows "Configured / from
database", secrets masked.
