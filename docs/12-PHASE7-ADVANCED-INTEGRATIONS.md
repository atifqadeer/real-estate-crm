# 12 — Phase 7: Advanced Integrations & Commercialization

Platform facts verified against official documentation on 2026-11-12
(LinkedIn/Microsoft Learn, TikTok, YouTube, X, Pinterest, Google Ads).

## LinkedIn — SHIPPED (connect + publish)

- **OAuth:** `GET https://www.linkedin.com/oauth/v2/authorization`,
  token exchange `POST https://www.linkedin.com/oauth/v2/accessToken`
  (form-encoded). Member tokens live 60 days; refresh tokens 365.
  Scopes: `openid profile w_member_social r_organization_social
  w_organization_social`.
- **Page discovery:** `GET /rest/organizationAcls?q=roleAssignee&
  role=ADMINISTRATOR&projection=(elements*(organization~(...)))`.
  LinkedIn's Community Management integration rules require the
  member to be an **ADMINISTRATOR** of the Company Page to post —
  discovery is filtered to that role on purpose.
- **Publishing:** `POST /rest/posts` with headers `LinkedIn-Version:
  YYYYMM` (config `LINKEDIN_VERSION`, default 202605 — versions sunset
  roughly every year; 202511 sunsets 2026-11-16) and
  `X-Restli-Protocol-Version: 2.0.0`. Body: author
  `urn:li:organization:{id}`, commentary, visibility PUBLIC,
  distribution MAIN_FEED, lifecycleState PUBLISHED. Success = 201 +
  post URN in the `x-restli-id` header. Single image via Images API:
  `POST /rest/images?action=initializeUpload` → PUT binary to the
  returned upload URL → reference `content.media.id` (the image URN).
  Video and multi-image posts are rejected with clear reasons for now.
- **The gate:** the LinkedIn app must be approved for the **Community
  Management API** product — Development tier (500 calls/day/app) to
  build and test, Standard tier (screencast + technical sign-off) for
  production. Without the server env vars the connect button returns
  an honest 503 explaining exactly this.

## Platforms deliberately NOT shipped (verified reasons)

- **TikTok:** Content Posting API — unaudited apps can only post
  privately ("unaudited_client_can_only_post_to_private_accounts");
  public posting requires passing TikTok's app audit. Scopes exist
  (`video.publish`, `video.upload`, `user.info.basic`) but shipping a
  connector whose posts are invisible would mislead clients.
- **X:** API is now pay-per-use credit billing — $0.015 per post
  create, $0.20 when the post contains a URL. Not commercially
  justified for this product today.
- **YouTube:** `videos.insert` draws on a separate 100 uploads/day
  bucket (1 unit per upload; other endpoints share 10,000 units/day);
  quota increases require a YouTube compliance audit. Note, correcting
  a common assumption: current docs state uploads from unverified
  projects are **not** restricted to private.
- **Pinterest:** API v5 Trial access creates sandbox pins visible only
  to the creator; Standard access (public pins) requires a reviewed
  demo video. Low demand from Pakistani real-estate clients.
- **Google Ads:** developer tokens were **sunset 2026-09-09**; access
  is now per Google Cloud project — Test (automatic) / Explorer
  (2,880 production ops/day) / Basic (15,000/day, brand verification)
  / Standard (unlimited, manual audit ~10 business days). Campaign
  sync in this product currently targets Meta Ads (Phase 6); a Google
  Ads read-only sync can be added on the same Campaigns shape once a
  project reaches Explorer/Basic.
- **Property portals (Zameen.com, OLX, Graana):** no approved public
  API for third-party posting was available to build against; portal
  enquiries enter the CRM through website forms, CSV import, or manual
  entry (all shipped in Phase 4). No scraping, per the prompt.

## AI content assistant (section 10)

- Provider-agnostic: any OpenAI-compatible chat-completions endpoint
  (`AI_API_KEY`, `AI_API_URL`, `AI_MODEL`, default gpt-4o-mini).
  Unconfigured → the endpoint returns an honest 422 naming the missing
  env var; nothing is fabricated.
- The system prompt hard-codes the prompt's guardrails: use ONLY the
  supplied property facts; never invent prices, features, approvals,
  legal/NOC status, possession, returns or availability. English or
  Urdu (Urdu script) output, 3 variations with hashtags + CTA.
- Every suggestion is returned with `ai_generated` and `needs_review`
  flags, is stored in `ai_content_generations` for history, and the
  only path into a post is a human clicking "Use this caption" in the
  composer and then going through the normal approval/publish flow.
  There is no AI-to-publish path.

## Subscription packages & billing

- Packages belong to the agency org (`service_packages.limits` JSON:
  max_users, max_social_accounts, max_leads_per_month,
  max_posts_per_month). Subscriptions assign a package to a client org
  for a period; a new assignment supersedes the previous current one.
- **Enforcement** (`PlanLimitService::assertCan`) runs at lead
  creation, post creation, and social connect. A limit blocks only
  when a current subscription's package defines it — no package (or a
  blank limit) means unlimited, so existing workspaces never break.
- **Invoices** are issued against the client org with sequential
  numbers (INV-0001…), due dates, and a manual payment lifecycle
  (draft → sent → paid/overdue/void) recording the method — bank
  transfer, JazzCash, EasyPaisa, cash, card. No payment gateway is
  simulated; connecting a real gateway later slots into the same
  invoice record.
- Permissions: `billing.view` (agency_admin, client_admin,
  owners/super_admin), `billing.manage` (agency_admin, owners).

## White-label branding

Per-workspace branding lives in `client_settings.settings.branding`
(brand_name, logo_url, primary_color — hex validated). The React shell
applies it live: sidebar title, logo and sidebar background color swap
per workspace. Settings updates merge the branding sub-object so
partial edits never wipe other settings.

## Advanced reporting

Client analytics now include an equal-length **previous-period**
comparison: leads, qualified, won and spend for the window directly
before the selected range, with percentage deltas (null when the
previous window has no baseline — never a fake 0% or ∞).

## Tests

`AdvancedIntegrationsTest` (11 tests, providers faked): LinkedIn
unconfigured 503 guidance, full OAuth flow (state round-trip, page
discovery, token encrypted at rest + absent from API payloads,
permission rows), text publish via the Posts API (author URN +
version headers asserted), honest video rejection, test-connection
401 → expired, AI unconfigured 422, AI suggestions marked for review
with guardrail language asserted in the outbound prompt + history
stored, package/subscription/limit enforcement (3rd lead blocked),
agency-only package routes, invoice lifecycle with JazzCash payment,
branding round-trip + invalid color 422, period comparison math.
