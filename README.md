# Real Estate Marketing Agency CRM — Pakistan

Multi-tenant CRM + social media management platform for a marketing agency
serving real estate agencies, brokers and developers in Pakistan.
Built phase by phase from the complete project prompt
(`Real_Estate_Marketing_Agency_CRM_Complete_Prompt.txt`, 2026-11-12).

**Current status: Phase 6 — Campaigns & Analytics COMPLETE in code (2026-11-12)**
Campaign management (ad groups/ads, daily spend by manual entry or
Meta Ads insights sync), lead attribution by manual link / Meta
campaign ID / UTM with distinct-lead counting, CPL, cost per qualified
lead and conversion metrics, campaign comparison, budget-threshold
notifications, client and agency analytics dashboards, and audited
CSV/XLSX/PDF report exports — with unsupplied provider metrics shown as
missing, never zero. 61 tests green (Graph API faked; live ad sync
needs the ads_read permission — see `docs/11-PHASE6-CAMPAIGNS-ANALYTICS.md`).

**Phase 5 — CRM Pipeline & WhatsApp COMPLETE in code (2026-11-12)**
Kanban pipeline board with drag-and-drop stage moves, saved filter
views, tasks, an in-app notification center with per-user preferences
(lead assignment, follow-up due/overdue, content outcomes, social
account health, WhatsApp messages), and the WhatsApp Cloud API: verified
number connect, template sync, a two-pane inbox with the 24-hour window
and opt-out rules enforced server-side, and signature-verified inbound
webhooks that match or create leads. 51 tests green (Graph API faked;
live WhatsApp needs a real WABA — see `docs/10-PHASE5-CRM-WHATSAPP.md`).

**Phase 4 — Lead Generation COMPLETE in code (2026-11-12)**
Full lead model (pipeline stages, sources, touchpoints, activity
timeline, custom fields), one deduplicating ingestion path for manual
entry, CSV/XLSX import with outcome reports, public website forms
(honeypot + throttling + UTM capture), Meta Lead Ads webhooks
(verification handshake, HMAC signature check, queued retrieval,
redelivery-safe) with per-Page subscription and form backfill,
assignment rules + round-robin with agent dashboards, and follow-ups
with due/overdue views. 40 tests green (Graph API faked; live lead flow
needs the Meta app setup — see `docs/09-PHASE4-LEADS.md`).

**Phase 3 — Content & Publishing COMPLETE in code (2026-11-12)**
Post composer with per-destination overrides and previews, media library,
approval workflow (per-post flag or workspace rule, enforced before any
publish/schedule), Facebook + Instagram publishing adapters, independent
per-destination queue jobs with idempotency and bounded retry, UTC
scheduling with a locked every-minute dispatcher, content calendar, and
16 starter templates. 30 tests green (Graph API faked in tests; live
publishing needs the Phase 2 Meta app setup — see `docs/08-PHASE3-PUBLISHING.md`).

**Phase 2 — Social Account Connections COMPLETE in code (2026-11-12)**
Meta (Facebook + Instagram) connection module: platform registry, OAuth
connect/callback with Page + linked-Instagram discovery, encrypted token
storage, per-account permissions, test/reconnect/disconnect, connection
logs, daily token-refresh job. 20 tests green (Graph API is faked in
tests — a live connection still needs the agency's own Meta app
credentials in `.env` and, for client Pages, Advanced Access via App
Review + Business Verification; see `docs/07-PHASE2-META-INTEGRATION.md`).

**Phase 1 — Foundation & Tenancy COMPLETE (2026-11-12)**
Laravel 13 API + React/TS frontend implemented; full test suite green:
12 passed, including 10 tenant-isolation acceptance tests (Client A gets
403 on every Client B resource; per-organization roles proven; invitation
flow proven). Local dev runs on SQLite; production MySQL/Redis config is
documented in `backend/.env.example` and `docker-compose.yml` (the build
VM has no MySQL/Docker, so MySQL verification happens on the deploy host).
Demo logins after `php artisan migrate:fresh --seed` (local only):
`admin@agency.demo`, `owner@clienta.demo`, `owner@clientb.demo`,
`multi@demo.pk` — all password `password`.

## Stack (as specified)

- Backend: PHP / Laravel (REST API `/api/v1`, Sanctum, Spatie Permission)
- Frontend: React + TypeScript + Tailwind CSS (Vite, in `frontend/`)
- Production data: MySQL 8, Redis (queues/cache), S3-compatible storage
- Local automated tests run on SQLite in-memory (see "Environment note")

## Environment note (important, not a silent downgrade)

This build machine initially had **no PHP, Composer, MySQL, Redis or
Docker**. PHP + Composer were installed to proceed. Consequences:

- Production/deployment configuration targets **MySQL 8 + Redis** exactly
  as specified (`.env.example`, `docs/06-SETUP-AND-DEPLOYMENT.md`).
- Automated tests in this environment run against **SQLite in-memory**
  because no MySQL server is available here. Tenant-isolation behaviour
  under test is database-agnostic (it lives in middleware, policies and
  query scoping, not in MySQL-specific features).
- Docker files are provided for a repeatable MySQL/Redis environment on
  a machine that has Docker.

## Layout

- `docs/` — design pack (architecture, ERD/schema, tenancy & security,
  API, phase plan, setup) — read these first
- `backend/` — Laravel API (created with Composer)
- `frontend/` — React + TypeScript app (Vite)

## Phase 1 deliverables

Architecture decisions, ER diagram + schema, role/permission design,
tenant-isolation middleware + policies, API route structure, frontend
structure, local setup — followed by the working foundation: auth
(Sanctum), agency + client organizations, memberships, roles/permissions,
client workspace settings, audit logging, tenant-scoped dashboard, and
automated tests proving Client A cannot access Client B's data.
