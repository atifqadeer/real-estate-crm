# 01 — System Architecture

## Decision summary

| Decision | Choice | Why |
|---|---|---|
| App shape | Modular monolith (Laravel) | Prompt §1: extensible monolith first; no microservices without demonstrated need |
| Tenancy model | Shared DB, `organization_id` on every tenant-owned row | Prompt §5 initial release |
| IDs | BIGINT auto-increment internally; never expose sequential IDs as security | Prompt §18 allows BIGINT or UUID if consistent — BIGINT chosen, documented here |
| Auth | Laravel Sanctum personal access tokens for the SPA/API | Prompt §2 |
| Roles | Spatie Laravel Permission, roles assigned **per organization membership** (see §03) | Prompt §3: a user can hold different roles in different orgs |
| Money | DECIMAL(15,2), currency column, default PKR | Prompt §18 |
| Time | Store UTC, display in organization timezone (default Asia/Karachi) | Prompt §3 |
| Phones | E.164 (`+92…`) via normalization on write | Prompt §3 |
| Files | S3-compatible storage, org-scoped paths `org/{id}/…`, signed URLs only | Prompt §5/§6 |
| Queues | Redis + Laravel Queues/Horizon (production); `sync` driver only for local smoke tests | Prompt §2 |

## Module map (backend `app/Domain/…` or service folders)

1. **Identity** — users, auth (Sanctum), invitations, MFA (later phase)
2. **Tenancy** — organizations, memberships, tenant context resolution,
   tenant scoping trait/policy base, workspace settings
3. **Access** — roles/permissions (Spatie), policies, audit logs
4. **Social** — platform registry, accounts, tokens (Phase 2+)
5. **Publishing** — posts, destinations, jobs, approvals (Phase 3)
6. **Leads** — sources, forms, webhooks, dedupe, assignment (Phase 4–5)
7. **Campaigns** — campaigns, spend, attribution, reports (Phase 6)
8. **Notifications** — in-app + email/WhatsApp preferences
9. **Reporting** — org-scoped aggregates and exports

Controllers stay thin: FormRequest validation → Policy authorization →
Service class → API Resource response. Multi-table writes run in DB
transactions. Jobs/events carry `organization_id` and re-validate record
ownership when handled (prompt §5).

## Request lifecycle (tenant safety)

1. Sanctum authenticates the user.
2. `ResolveTenant` middleware reads `X-Organization-Id` header (or route
   parameter), loads the user's **membership**, and 403s if none/inactive.
3. The membership's role set becomes the effective permission context for
   this request (roles are never global for tenant users).
4. `TenantScope` (global scope on tenant-owned models) constrains every
   query to the current organization automatically.
5. Policies still authorize the specific action (defense in depth:
   scoping hides other tenants' rows; policies decide what you may do
   with your own tenant's rows).

## Non-goals for Phase 1

No social connections, publishing, lead capture, WhatsApp, or campaign
sync. No simulated integrations presented as real (prompt §1/§24). No
Phase 2 work until Phase 1 acceptance tests pass (prompt §25).
