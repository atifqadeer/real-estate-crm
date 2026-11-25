# 15 — Phase 8: Lead Campaigns (multi-industry lead capture)

Requested 2026-11-25: make the CRM grab leads from social accounts and
Google, Bitrix24-style, for any business — not just real estate — via a
setup page where a campaign is initialised with an industry
("real estate", "automobile", or custom) and keywords.

## What was built

- **Lead Campaigns** (new sidebar page): per-workspace lead-generation
  profiles with name, industry vertical, keywords, optional locations,
  source selection, active/paused status and a lead count.
- **Industry presets** with starter keywords: Real Estate, Automobile,
  Healthcare, Education, Retail & E-commerce, Home Services, and
  **Custom** (any business type, typed by the admin). Keywords are
  fully editable per campaign.
- **Automatic tagging:** every incoming lead — Meta Lead Ads, website
  form, WhatsApp, CSV/XLSX import, manual entry, webhook — passes
  through `LeadCampaignMatcher`. The first active campaign that
  (a) listens to that source, (b) matches a keyword in the enquiry
  text (notes, custom fields, location/property fields, UTM/ad text),
  and (c) passes its optional location filter, is tagged on the lead.
  A campaign with no keywords is a catch-all for its sources.
  Tagged leads show a campaign badge in the Leads list and can be
  filtered by `lead_campaign_id`.
- **Per-campaign public webhook:** each campaign gets an unguessable
  URL (`POST /api/v1/public/lead-campaigns/{key}`) that accepts:
  - the **Google Ads Lead Form** payload (`user_column_data`), which
    is how leads are genuinely grabbed from Google, and
  - a flat JSON payload for Zapier, Make, or a custom website.
  Webhook leads are tagged to that campaign directly. The key can be
  regenerated from the setup page (old URL stops working).
- New `webhook` lead source; new `LeadCampaign` model/table
  (`lead_campaigns`), separate from the Phase 6 ad `Campaign`
  (spend/metrics) model. Management requires `leads.configure`;
  viewing requires `leads.view`. Tenant isolation enforced and tested.

## Honest limits (platform rules, not missing code)

- **No platform allows keyword scraping of public posts.** Facebook,
  Instagram, LinkedIn and Google offer no API to search public posts
  by keyword and harvest the posters as leads; tools claiming this
  scrape against the platforms' terms and get accounts banned.
  Bitrix24 itself captures leads the same way this CRM does: from
  your own ad lead forms, pages, messengers, website forms and
  webhooks. Keyword capture here therefore applies to enquiries that
  arrive through your own channels.
- **Google leads** arrive via Google Ads Lead Form assets pointing at
  the campaign webhook (or via Zapier/Make). There is no Google API
  for "search leads".
- **Social leads** still require the Phase 4 setup: a Meta app, the
  `leads_retrieval` permission (App Review for real client Pages),
  Pages connected and subscribed to leadgen, and the queue worker
  running. This phase does not change those requirements.
- WhatsApp keyword tagging applies to leads created from WhatsApp
  (e.g. "start chat for lead" / inbound lead creation paths that go
  through the ingestion service), not to arbitrary chat messages.

## Tests

`LeadCampaignsTest` (9 tests): presets, create/list + permission gate,
tenant isolation, keyword tagging, source filter, location filter,
paused campaign, webhook flat payload, webhook Google Ads payload,
webhook rejection cases. Full suite: **82 passed**.

## Preview verification (2026-11-25)

Ran the real stack locally (SQLite demo seed), logged in as
owner@clienta.demo and screenshotted the Lead Campaigns page, the
create form, and the Leads list. Two demo campaigns (Real Estate —
Lahore, Automobile — Karachi) each captured exactly the right test
lead; a general enquiry stayed untagged.

The preview also exposed a **pre-existing frontend bug**: the Leads
list crashed (`Cannot read properties of undefined (reading 'map')`)
because `api()` already unwraps the paginator's `data` array while
`LeadsListTab` expected the raw paginator. Fixed in `LeadsListTab`
(accept both shapes); verified fixed in the browser afterwards.
