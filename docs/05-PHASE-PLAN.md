# 05 — Phase-by-Phase Implementation Plan

Gate rule (prompt §25): a phase starts only when the previous phase's
acceptance tests pass.

## Phase 1 — Foundation & Tenancy  ← CURRENT
Auth (Sanctum) · organizations (agency + client) · memberships ·
per-organization roles/permissions (Spatie teams) · tenant middleware +
global scope + policies · client workspace settings · invitations ·
audit logs · tenant-scoped dashboard · React app shell with
permission-aware sidebar · tenant-isolation test suite.
**Acceptance:** docs/03 test list green; Client A cannot see Client B.

## Phase 2 — Social Account Connections
Platform registry · Meta OAuth (Facebook Pages, Instagram discovery) ·
encrypted token storage · status/refresh/reauthorize · connection logs.
**Gate:** Meta app review + business verification requirements verified
against current official docs before coding each flow.

## Phase 3 — Social Publishing
Composer + media pipeline · per-destination records/jobs · FB/IG
publishing · scheduling + calendar · approvals · retry/idempotency.

## Phase 4 — Lead Generation
Lead model + statuses · manual + CSV/XLSX import · website forms ·
Meta Lead Ads webhooks (signature verified, deduped) · assignment rules ·
follow-up reminders.

## Phase 5 — CRM & Communication
Kanban pipeline · lead detail/timeline · agent dashboards · WhatsApp
Business Cloud API · notifications + preferences.

## Phase 6 — Campaigns & Analytics
Campaigns + manual spend · CPL/CPQL · attribution (first/latest touch,
all touchpoints kept) · agency/client reports · PDF/CSV/Excel exports.

## Phase 7 — Advanced & Commercialization
LinkedIn/TikTok/X/YouTube/Pinterest (capability-verified only) ·
Google Ads API · portal connectors via approved access only · AI
content assistant (human approval before publishing; never invent
property facts) · packages/billing · white-label.

## Social capability matrix (to be verified per platform in Phase 2/7)

| Platform | Connect | Publish | Leads | Status |
|---|---|---|---|---|
| Facebook Pages | OAuth | Yes (API) | Lead Ads webhooks | Phase 2–4 priority |
| Instagram Professional | via Meta | Yes (API, eligibility rules) | Lead Ads | Phase 2–4 |
| LinkedIn Pages | OAuth | Later | — | Phase 7 |
| TikTok | OAuth | Later | Later | Phase 7, approval-gated |
| X / YouTube / Pinterest | OAuth | Later | — | Phase 7, only if justified |

No capability is promised to clients until verified against the
platform's current official documentation and app-review requirements.
