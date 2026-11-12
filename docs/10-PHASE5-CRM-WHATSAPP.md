# 10 — Phase 5: CRM Pipeline & WhatsApp

WhatsApp Cloud API facts verified against Meta's official documentation
on 2026-11-12 (send-messages guide updated 2026-11-04, templates,
webhooks reference, mark-as-read, phone numbers).

## Verified provider facts

- **Send:** `POST /{phone-number-id}/messages` with
  `messaging_product: "whatsapp"`, `recipient_type: "individual"`,
  `to`, `type`. Text: `text: {body}`. Template:
  `type: "template"`, `template: {name, language: {code}, components:
  [{type: "body", parameters: [{type: "text", text}]}]}` — positional
  `{{1}}` parameters (named parameters also exist). A send response
  with `messages[0].id` means *accepted*, not delivered.
- **24-hour customer service window:** starts/resets whenever the
  customer messages (or calls). While open, free-form "service"
  messages are allowed; when closed, **only pre-approved template
  messages** may be sent. Messages may only go to users who opted in.
- **Templates:** `GET /{waba-id}/message_templates?fields=name,
  language,status,category,components` (cursor paging). Templates
  must be **APPROVED** before use; categories MARKETING / UTILITY /
  AUTHENTICATION.
- **Phone number read:** `GET /{phone-number-id}` →
  `display_phone_number`, `verified_name`, `quality_rating`
  (GREEN/YELLOW/RED), used to verify credentials at connect time.
- **Webhooks:** `object: "whatsapp_business_account"`,
  `entry[].changes[]` with `field: "messages"`; `value.messages[]`
  (`from`, `id`, `timestamp`, `type`, `text.body`), `value.contacts[]`
  (`profile.name`, `wa_id`), `value.metadata.phone_number_id`;
  `value.statuses[]` (`id`, `status` ∈ sent/delivered/read/failed/
  played, `recipient_id`). Same `hub.*` verification and
  `X-Hub-Signature-256` HMAC scheme as Lead Ads. **Heads-up from
  Meta's Nov 2026 docs:** contacts are moving to a business-scoped
  `user_id` (BSUID) as primary identifier and `wa_id` is deprecated
  from 2027-01-15 — worth planning a migration; `wa_id` still works
  today and is what this build uses.
- **Mark as read:** POST to the same messages endpoint with
  `status: "read"` + `message_id`; the inbox does this best-effort
  when a conversation is opened.

## What was built

**CRM pipeline**
- Kanban board endpoint + UI: stages as columns, drag-and-drop moves
  (HTML5 DnD) with an activity-timeline entry per move, "only mine"
  filter, won/lost column tinting, priority + possible-duplicate flags.
- Saved filter views (private or workspace-shared) over the leads list.
- Lead detail already shipped in Phase 4 (timeline, touchpoints,
  follow-ups); it now also hosts an inline WhatsApp chat panel.

**Tasks** — workspace tasks optionally linked to a lead, assignee +
  due date + priority, completion tracking, assignment notifications.

**Notifications (prompt §17)** — in-app notification center (bell in
the sidebar, unread badge, 60s refresh) fed by real events:
lead assigned/reassigned, task assigned, follow-up due (hourly sweep)
and overdue (each fires once per follow-up), content approved /
rejected / changes requested, post published / partially published /
failed (once per post per outcome), social account disconnected,
social token expired, WhatsApp message received. Per-user,
per-workspace **preferences** can mute any type; the service checks
them before writing. Hook points for email/WhatsApp delivery can be
added at the NotificationService boundary later.

**WhatsApp (prompt §12C)**
- Connect: WABA ID + phone number ID + permanent token, verified live
  against Meta before saving; token stored encrypted, never returned
  by the API. (The Embedded Signup flow is the alternative onboarding
  for production; it needs a Meta app configuration ID — the manual
  system-user-token connect here is fully functional.)
- Template sync from the WABA; only APPROVED templates can be sent.
- Conversations matched to leads by phone/WhatsApp number; unknown
  senders become leads through the same ingestion/dedupe path with
  source "WhatsApp".
- Sending rules enforced server-side: opted-out contacts cannot be
  messaged at all; free-form text requires an open 24h window,
  otherwise the API refuses with template guidance; template sends
  work any time.
- Inbound webhooks: signature-verified; messages stored (redelivery
  deduped by `wamid`), window (re)opened, unread counts, statuses
  applied to outbound messages, assignee notified.
- **Consent/opt-out:** inbound STOP / UNSUBSCRIBE (plus common local
  variants) sets the opt-out on the conversation and the lead, logs it
  in the lead timeline, and blocks all further sends; START opts back
  in. Channel consent is recorded on the lead (`consent_whatsapp`).

## Tests

`CrmWhatsAppTest` (11 tests, Graph API faked): pipeline board + move,
saved-filter visibility, tasks + notification, assignment notification
+ preference opt-out, follow-up due/overdue sweep firing once,
WhatsApp connect verification + token encryption/hiding, template
sync + APPROVED-only sending, 24h window enforcement across time
travel, inbound webhook creating lead/conversation/notification +
status updates + redelivery safety, opt-out/opt-in flow, tenant
isolation.
