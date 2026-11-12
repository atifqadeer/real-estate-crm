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

## Phase 2 — Social Account Connections — ✅ DONE 2026-11-12 (code + mocked-API tests)
Platform registry · Meta OAuth (Facebook Pages, Instagram discovery) ·
encrypted token storage · status/refresh/reauthorize · connection logs.
**Gate:** Meta app review + business verification requirements verified
against current official docs before coding each flow.
Delivered: see docs/07. 8 Phase 2 tests green (Graph API faked).
**Still external:** live connection needs Atif's Meta app credentials in
`.env`, and Advanced Access (App Review + Business Verification) before
real client Pages can connect — Standard Access covers only app-role
users and their own Pages.

## Phase 3 — Social Publishing — ✅ DONE 2026-11-12 (code + mocked-API tests)
Composer + media pipeline · per-destination records/jobs · FB/IG
publishing · scheduling + calendar · approvals · retry/idempotency.
Delivered: see docs/08. 10 Phase 3 tests green. Live publishing still
needs the Phase 2 externals (Meta app credentials + Advanced Access)
and publicly reachable media URLs.

## Phase 7 — Advanced Integrations & Commercialization — ✅ DONE 2026-11-12 (code + mocked-API tests)
LinkedIn Company Pages connect + Posts API publishing (ADMINISTRATOR
discovery, Community Management API gate documented) · TikTok/X/
YouTube/Pinterest/Google Ads/property portals evaluated with verified
gates and deliberately not shipped (docs/12) · AI content assistant
(provider-agnostic, guardrailed, review-marked, never auto-publishes) ·
subscription packages + plan-limit enforcement + manual invoicing ·
white-label branding · previous-period analytics comparison.
11 Phase 7 tests green; full suite 72.

## Phase 6 — Campaigns & Analytics — ✅ DONE 2026-11-12 (code + mocked-API tests)
Campaign module (ad groups/ads, daily spend manual + Meta Ads insights
sync with ads_read, idempotent) · attribution (manual/Meta-id/UTM,
distinct-lead counting, method mix) · CPL/CPQL/conversion metrics ·
campaign comparison · budget-threshold notifications · client +
agency analytics · CSV/XLSX/PDF report exports with audit logging ·
missing provider metrics shown as null, never zero. See docs/11.
10 Phase 6 tests green. Live sync needs ads_read (+ Full Access for
client ad accounts).

## Phase 5 — CRM Pipeline & WhatsApp — ✅ DONE 2026-11-12 (code + mocked-API tests)
Kanban pipeline with drag-and-drop stage moves · saved filter views ·
tasks · in-app notifications with per-user preferences (lead assigned,
follow-up due/overdue sweep, content outcomes, social health, WhatsApp)
· WhatsApp Cloud API: verified connect, template sync, inbox with
24-hour window + opt-out enforcement, inbound webhooks with lead
matching. See docs/10. 11 Phase 5 tests green. Live WhatsApp needs a
real WABA + number + permanent token and the webhook pointed at
/api/v1/webhooks/whatsapp.

## Phase 4 — Lead Generation — ✅ DONE 2026-11-12 (code + mocked-API tests)
Lead model + statuses · manual + CSV/XLSX import · website forms ·
Meta Lead Ads webhooks (signature verified, deduped) · assignment rules ·
follow-up reminders. Delivered: see docs/09. 10 Phase 4 tests green.
Live lead flow needs the Phase 2 externals (Meta app + Advanced Access
incl. leads_retrieval) and META_WEBHOOK_VERIFY_TOKEN set.

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
