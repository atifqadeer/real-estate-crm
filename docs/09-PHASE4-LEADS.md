# 09 — Phase 4: Lead Generation

Meta Lead Ads facts verified against official Meta documentation on
2026-11-12 ("Retrieving Leads", updated 2026-05-21; "Webhooks for
Leads"; "Getting Started — Webhooks").

## Verified provider facts

- **Retrieve one lead:** `GET /v27.0/{leadgen_id}` → `created_time`,
  `id`, `ad_id`, `form_id`, `field_data` (array of `{name, values[]}`).
- **Form backfill (Bulk Read):** `GET /{form_id}/leads?fields=
  created_time,id,ad_id,form_id,field_data` with `paging.cursors.after`
  pagination. A form can serve many ads.
- **Page subscription:** `POST /{page-id}/subscribed_apps` with
  `subscribed_fields=leadgen` and the Page token installs the app for
  leadgen events.
- **Webhook payload:** `object: "page"`, `entry[].changes[]` with
  `field: "leadgen"` and `value: {leadgen_id, page_id, form_id,
  adgroup_id, ad_id, created_time}`. Note `campaign_id` is not in the
  documented webhook value; when Meta returns it on the lead node we
  store it, otherwise campaign attribution comes from the ad/form ids.
- **Verification:** GET with `hub.mode=subscribe`, `hub.verify_token`,
  `hub.challenge` — echo the challenge when the token matches
  `META_WEBHOOK_VERIFY_TOKEN`.
- **Signature:** POSTs carry `X-Hub-Signature-256: sha256=<hmac>` —
  HMAC-SHA256 of the raw body with the app secret; compared with
  `hash_equals`. Respond 200 quickly; process asynchronously.
- **Permissions:** `leads_retrieval` is required to read leads. Full
  ad-level data also needs `ads_management`, `pages_manage_ads`,
  `pages_show_list`, `pages_read_engagement`; installing the webhook
  additionally needs `pages_manage_metadata` and a person who can
  ADVERTISE on the Page. Leads Access Manager settings on the Page can
  further restrict which admins' tokens work. `leads_retrieval` was
  added to the default `META_SCOPES`; the ads permissions are an App
  Review decision for Atif (wider scope = wider review).

## Lead engine design (this codebase)

- **One ingestion path** (`LeadIngestionService`) for manual entry,
  CSV/XLSX import, website forms and Meta Lead Ads — validation,
  normalization and dedupe can never diverge between sources.
- **Normalization:** phones → E.164 (Pakistan default: `03XX-…` and
  `92…` become `+92…`), emails lowercased/validated.
- **Duplicate rules (prompt §13):** the original lead is preserved.
  A repeat enquiry matching on phone/WhatsApp/email *and* the same
  person (email match or similar name) becomes a **touchpoint** on the
  original, filling only blank fields. The same phone with a clearly
  different name creates a new lead flagged `possible_duplicate_of`
  for human review — **never auto-merged**. A repeated Meta lead id is
  an ignored duplicate event. Every outcome is counted in import
  reports and recorded in the lead's activity timeline.
- **Pipeline:** 8 default stages per prompt §13 (New → … → Converted /
  Lost), provisioned per organization; admins can add stages.
- **Sources:** system sources (manual, import, website form, Meta Lead
  Ads, walk-in, referral) + custom sources; every touchpoint records
  source, campaign, UTM snapshot and the raw payload.
- **Assignment (§14):** ordered rules (city → property type → source →
  default) configured by client admins, then round-robin across active
  sales agents/managers (pointer kept in workspace settings); all
  assignments/reassignments are in the activity history. Agent summary
  endpoint powers "my leads / due today / overdue" dashboards.
- **Follow-ups:** per-lead scheduling with owner, due/overdue listing.
  (In-app notification delivery builds on this in Phase 5.)
- **Website forms:** per-workspace forms with an unguessable public
  key, public GET (metadata) + POST submit endpoint, honeypot field,
  per-IP throttle (30/min), UTM + landing page capture, configured
  success message.
- **Import:** CSV natively; XLSX via a minimal built-in reader (first
  sheet, shared/inline strings) to avoid a heavy dependency. Flexible
  header synonyms; per-row outcomes + stored import report.
- **Webhooks:** events stored first (`webhook_events`, unique on
  provider + leadgen id), processed by a queued job that resolves the
  Page → workspace, fetches the lead, maps standard fields, and puts
  unknown form questions into `custom_fields`. Unknown Page → event
  marked `ignored` with the reason; missing/expired token → `failed`
  with the reason, retryable after reconnect.

## Tests

`LeadGenerationTest` (10 tests, Graph API faked): phone normalization
+ default stage, touchpoint dedupe, possible-duplicate flagging,
round-robin + city rule, CSV import report, website form + honeypot +
UTM, webhook verification/signature/end-to-end + safe redelivery,
backfill skipping known leads, follow-ups + agent summary, tenant
isolation.
