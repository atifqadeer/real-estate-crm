# 14 — Go-Live Checklist (after the localhost run works)

Prepared 2026-11-12. This is the single checklist Muse offered at the
end of Phase 7. Part A is for the localhost MySQL run Atif is doing
now (docs/13); Part B is for a real deployment — do not do Part B on
localhost. Nothing here is verified live yet: see the honest limits
at the bottom.

## Part A — Localhost MySQL smoke test (do this first)

Run these after `php artisan migrate:fresh --seed` succeeds in
docs/13 ("Using MySQL instead of SQLite").

1. `php artisan db:show` reports a **MySQL** connection to
   `real_estate_crm` (not SQLite).
2. Start the three windows: `php artisan serve`, `php artisan
   queue:work`, `npm run dev`. Open http://localhost:5173.
3. Log in as `admin@agency.demo` / `password` — agency view, sees
   everything.
4. Log out, log in as `owner@clienta.demo` / `password`, then as
   `owner@clientb.demo` / `password` — each sees only their own
   workspace. (`multi@demo.pk` / `password` is a member of both, for
   the tenant-isolation demo.)
5. Quick spot-checks: create a lead in Client A, switch to Client B
   and confirm it is not there; create a post draft and confirm the
   approval flow appears; open Analytics and confirm figures load
   (provider metrics with no data show as "n/a", never 0).
6. If anything fails, paste the **exact** error message plus which
   step it came from. The three most likely MySQL first-run errors:
   - `could not find driver` → `extension=pdo_mysql` is still
     commented out in `C:\php\php.ini`; uncomment it, close and
     reopen PowerShell.
   - `Access denied for user 'crm'@'localhost'` → the password in
     `backend\.env` does not match the one set in the `CREATE USER`
     step in docs/13.
   - `Unknown database 'real_estate_crm'` → the `CREATE DATABASE`
     step in docs/13 was not run, or ran against a different MySQL
     instance.
   Note: this is the app's first run against a real MySQL server —
   it was fully tested on SQLite only. A migrate error is a bug to
   report back, not a setup mistake to guess at.

## Part B — Go-live checklist (production host only)

### Hosting & environment
- [ ] Host with MySQL 8 + Redis 7 (see docs/06). Set
      `APP_ENV=production`, `APP_DEBUG=false`, strong `APP_KEY`.
- [ ] Queue worker running under Supervisor/systemd, plus the
      scheduler (`* * * * * php artisan schedule:run`) — scheduled
      posts, the daily Meta token refresh and the hourly follow-up
      sweep all depend on these.
- [ ] TLS via reverse proxy; object storage bucket private, signed
      URLs only.
- [ ] Backups: database + storage, with a **tested restore**.
- [ ] Sentry (or equivalent) DSN set; log level warning+.
- [ ] Delete or change **all demo accounts** (`admin@agency.demo`,
      `owner@clienta.demo`, `owner@clientb.demo`, `multi@demo.pk` —
      password `password`) before any real client data goes in.
- [ ] Pakistani legal review of privacy/marketing obligations
      (prompt §20) before go-live.

### Meta — Facebook / Instagram / Lead Ads
- [ ] Create the Meta app; put `META_APP_ID`, `META_APP_SECRET`,
      `META_REDIRECT_URI` in the server `.env` (never in chat/git).
      Details: docs/07.
- [ ] **Business Verification + App Review for Advanced Access**
      ("Full Access") — required for real client Pages and client
      ad accounts. Until then only Pages owned by app-role users
      can connect (fine for a pilot with your own Page).
- [ ] Lead Ads: set `META_WEBHOOK_VERIFY_TOKEN` in `.env` and the
      same value in the App Dashboard Webhooks config; subscribe
      Pages to `leadgen` (docs/09).
- [ ] Campaigns: `ads_read` permission is required for Meta Ads
      insights (docs/11). Media must be on a **public URL** — Meta
      cannot fetch it from localhost (docs/08).

### WhatsApp
- [ ] Real WABA + phone number + permanent system-user token;
      connect in-app (verified live before saving). Point the
      WhatsApp webhook at `/api/v1/webhooks/whatsapp` (docs/10).
- [ ] Sync templates; only APPROVED templates can be sent, and
      free-form messages work only inside the 24-hour customer
      service window.

### LinkedIn
- [ ] LinkedIn app approved for the **Community Management API**
      product — Development tier to test, Standard tier (screencast
      + technical sign-off) for production. Without it, connect
      returns an honest 503, not a silent failure (docs/12).
- [ ] Page discovery only lists pages where the connecting member
      is an **ADMINISTRATOR** — that is LinkedIn's rule, not a bug.

### AI assistant & billing
- [ ] AI key (optional): `AI_API_KEY` / `AI_API_URL` /
      `AI_MODEL` for any OpenAI-compatible provider. Unconfigured
      = honest 422, never fake output (docs/12).
- [ ] Create agency service packages and assign subscriptions to
      client workspaces; limits only bite when a package defines
      them. Invoices are manual (bank / JazzCash / EasyPaisa /
      cash) — no payment gateway is connected.

## Honest limits (unchanged)
- Tests (72/72) fake Meta/LinkedIn/WhatsApp APIs; no live
  connection, live publish, live lead, live WhatsApp message or
  live MySQL run has been verified yet.
- Deliberately **not** shipped, with reasons in docs/12: TikTok
  (unaudited apps = private-only posts), X (pay-per-post),
  YouTube (100 uploads/day quota), Pinterest Trial (sandbox
  pins), Google Ads (developer tokens sunset 2026-09-09),
  property portals (no approved public API).
