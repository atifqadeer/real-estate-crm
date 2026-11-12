# 11 — Phase 6: Campaigns & Analytics

Meta Marketing API facts verified against official documentation on
2026-11-12 (Ads Insights API guide, updated 2026-04-27; permissions
reference, updated 2026-10-19; Ads Action Stats reference).

## Verified provider facts

- **Insights:** `GET /act_<AD_ACCOUNT_ID>/insights` with `fields`
  (campaign_id, campaign_name, spend, impressions, reach, clicks,
  actions, cost_per_action_type), `level` ∈ account/campaign/adset/ad,
  `time_range {since, until}` (overrides `date_preset`), and
  `time_increment=1` for one row per day (`date_start`/`date_stop` per
  row). Spend is in the **ad account's currency** (`account_currency`).
- **Lead conversions** live in the `actions` array: `lead` = all
  offsite + On-Facebook leads (broadest); `onsite_conversion.
  lead_grouped` = On-Facebook leads only. The sync accepts both,
  preferring whichever Meta returns for the row.
- **Campaigns list:** `GET /act_<id>/campaigns` → id, name, objective
  (e.g. OUTCOME_LEADS), status (ACTIVE/PAUSED/…). Budget fields on the
  campaign node (`lifetime_budget`) are integer **minor units** — the
  sync divides by 100 and only adopts Meta's budget when no local
  budget is set.
- **Permission:** `ads_read` is required for insights. Managing only
  your own ad accounts needs standard access; **other businesses' ad
  accounts require Advanced Access (now labelled "Full Access") via
  App Review + Business Verification** — the same gate as client Pages.
- **Limits:** no published numeric row limit; synchronous queries can
  time out on big ranges (async report runs are the fallback), insights
  refresh ~every 15 minutes and settle after 28 days. This build syncs
  date-bounded windows (default last 30 days) synchronously.

## Campaigns (prompt §15)

- Full campaign record: objective, platform (meta/google/tiktok/
  offline/other), external campaign id, Meta ad account, dates, budget
  + currency (PKR default), status, target location, property
  category, landing page, UTM tracking params, internal owner.
- Ad groups (ad sets) and ads with creative references.
- **Daily spend records** with source `manual` (first-release path, per
  the prompt) or `meta_api`. Provider metrics (impressions/reach/
  clicks/conversions) are **nullable and stay null when not supplied —
  missing is never reported as zero** (§16).
- **Meta sync** upserts campaigns by external id and daily spend by
  (campaign, date, level, external id) — re-syncs are idempotent. A
  missing `ads_read` grant produces an explicit, actionable error, not
  a silent empty sync.
- **Budget alerts:** at 80% and 100% of budget, the campaign owner gets
  a `campaign.budget_threshold` notification — once per threshold
  (§17's remaining item).

## Attribution (§15)

A lead is attributed to a campaign by the first matching method:
**manual link** (`leads.campaign_id`, sets `attribution_method=manual`)
→ **Meta campaign id** match → **UTM campaign** match. Metrics count
**distinct leads once** and expose the method mix
(`attribution_methods`) instead of pretending deterministic
attribution. First-touch attribution is preserved by the Phase 4
ingestion design (original source fields are never overwritten; later
enquiries become touchpoints, which the campaign detail can grow into).

## Metrics

Per campaign (optionally date-ranged): spend, impressions/reach/clicks
(null-aware), Meta-reported conversions, attributed leads, qualified
leads (stage at or beyond Qualified, excluding Lost), won leads,
**CPL**, **cost per qualified lead**, lead→customer conversion %,
budget used %. Campaign comparison endpoint renders any 2+ campaigns
side by side.

## Analytics & exports (§16)

- **Client overview:** leads received, qualified + rate, conversions,
  funnel by stage, leads by source/campaign/city/property type, agent
  performance (leads + won), follow-ups due today/overdue, posts
  published/failed/scheduled, social account health, campaign spend +
  overall CPL. Preset ranges (today/week/month/last-30) or custom
  from/to. `social_engagement` is returned as **null — not provided**:
  per-post Meta insights are not fetched yet, and the API refuses to
  present that as zero.
- **Agency overview** (agency-type workspace): totals across all
  client organizations, per-client performance rows, connected
  accounts, agency team workload (open tasks). Implemented by running
  the same client overview per tenant with explicit organization
  filters (tenant scope bypassed deliberately inside the service,
  which re-applies `organization_id` on every query).
- **Exports:** leads, campaign-performance, funnel, agent-performance
  and publishing-history datasets in **CSV, XLSX and PDF** — all three
  writers are dependency-free (minimal XLSX via ZipArchive, minimal
  text PDF writer). Exports require `analytics.export` and are
  **audit-logged** with report, format and range.

## Implementation notes / gotchas found this phase

- SQLite stores `date`-cast attributes as datetimes; all spend
  lookups/ranges therefore use `whereDate`, and update-or-create for
  spend uses an explicit whereDate lookup (plain attribute equality
  missed existing rows and hit the unique index).
- PHP arrow functions capture by value — the first attribution draft
  silently returned no leads because `$map` writes inside `fn()` were
  lost; the shipped code uses plain loops.
- Organization type enum is `agency` / `real_estate_client`.

## Tests

`CampaignsAnalyticsTest` (10 tests, Graph API faked): campaign CRUD +
defaults, spend + metrics math with all three attribution methods,
missing-vs-zero provider metrics, budget alerts firing exactly once,
Meta sync (campaign upsert, minor-unit budget adoption, daily rows,
lead-action conversions, idempotency), honest ads_read error, client
overview math, agency aggregation across tenants, all three export
formats + audit entry + unknown-report 404, compare, tenant isolation.
