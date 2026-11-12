# Real Estate Marketing Agency CRM — Pakistan

Multi-tenant CRM + social media management platform for a marketing agency
serving real estate agencies, brokers and developers in Pakistan.
Built phase by phase from the complete project prompt
(`Real_Estate_Marketing_Agency_CRM_Complete_Prompt.txt`, 2026-11-12).

**Current status: Phase 1 — Foundation & Tenancy COMPLETE (2026-11-12)**
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
