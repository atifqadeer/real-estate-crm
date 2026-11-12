# 08 — Phase 3: Content & Publishing

Endpoints verified against Meta's official documentation on 2026-11-12:
- Instagram Content Publishing (updated 2026-10-20) + IG User Media reference
- Pages API "Posts" guide (updated 2026-04-17), Page Photos reference,
  Page Feed reference, Video API publishing guide

## Verified provider facts used by the adapters

**Facebook Page**
- Text/link: `POST /{page-id}/feed` with `message`, optional `link`
  (one of the two is required). Native scheduling exists
  (`published=false` + `scheduled_publish_time`, 10 min – 75 days) but
  this product uses its **own scheduler + queue** so timing, retries and
  the approval gate behave identically across platforms.
- Single photo: `POST /{page-id}/photos` with `url` + `message`
  → returns `{id, post_id}`.
- Multiple photos (max 10): upload each with `published=false`, then
  `POST /feed` with `attached_media[i] = {"media_fbid": ...}`.
- Video: Resumable Upload API — `POST /{app-id}/uploads` (session),
  upload bytes to `graph-video.facebook.com/.../upload:<session>`,
  then `POST graph-video /{page-id}/videos` with
  `fbuploader_video_file_chunk`, `title`, `description`.
- First comment: `POST /{post-id}/comments` (best-effort; its failure
  never fails the post).
- The Posts guide also lists a `publish_video` permission for video;
  confirm at App Review whether your app still needs it — the code
  reports any permission failure as a clear permanent error.

**Instagram (Facebook Login path)**
- Container workflow: `POST /{ig-id}/media` → poll
  `GET /{container}?fields=status_code` (`IN_PROGRESS`, `FINISHED`,
  `ERROR`, `EXPIRED`, `PUBLISHED`) → `POST /{ig-id}/media_publish`
  with `creation_id`. Docs recommend polling ~1/min for ≤5 min; our
  job polls briefly and the bounded retry resumes the same container.
- Media must sit on a **publicly accessible URL** — Meta fetches it.
  Production must serve media from the public domain/S3 (`APP_URL`);
  localhost URLs cannot work live.
- Caption max **2,200** characters. Carousel up to **20** items
  (child containers with `is_carousel_item=true`, parent
  `media_type=CAROUSEL`). A video becomes a Reel (`media_type=REELS`).
- Daily publishing limit: Meta's page states both 100 posts/24h
  (rate-limit section) and 50/24h (carousel limitations) — treat 50 as
  the safe planning number until Meta reconciles its docs.
- Containers expire after 24 hours; 400 containers / 24h max.
- Page Publishing Authorization (PPA) may be required per Page.

## Engine design (this codebase)

- `PublisherInterface` per platform (`FacebookPublisher`,
  `InstagramPublisher`): `validateContent`, `validateAccount`,
  `publish`, `getCapabilities`. Unsupported operations return explicit
  errors — never silent no-ops.
- **Independent destination jobs** (`PublishPostTarget`): one account =
  one job = one status. A failure on Instagram never blocks Facebook;
  the post aggregates to published / partially_published / failed.
- **Idempotency:** a target with a `platform_post_id` is never
  re-published; Instagram retries reuse the stored container id (and
  media id, once known) instead of creating duplicates.
- **Retry:** max 3 attempts; backoff 60s → 300s → 900s; only transient
  errors (network, 5xx, rate limit) retry — auth/permission/validation/
  policy errors fail immediately with the provider's message stored.
  Manual retry resets the budget after the user fixes the cause.
- **Attempts log:** every attempt records start/finish, status,
  permanence, and redacted provider payloads (never tokens).
- **Scheduling:** stored in UTC, shown in the viewer's timezone;
  `ProcessScheduledPosts` runs every minute, takes a per-post cache
  lock, and dispatches due posts; cancelled posts are re-checked at
  job time so a late dispatch cannot publish them.
- **Approval gate:** a post publishes only when approval is not
  required, or it is in `approved` status. Requirement comes from the
  per-post flag OR the workspace setting `content.approval_required`.
  Reject / request-changes require a comment; every action is recorded
  with actor + timestamp (`content_post_approvals`).
- **Media:** JPEG/PNG/WebP ≤ 10 MB, MP4/MOV ≤ 100 MB; dimensions read
  at upload; tenant-scoped storage under `org-{id}/`; production should
  point the `public` disk at S3 (see `.env.example` notes in docs/06).

## Tests

`ContentPublishingTest` (10 tests, Graph API faked): FB publish +
idempotent re-run, IG container flow, partial failure isolation,
approval gate end-to-end (incl. comment-required and workspace rule is
covered by the same guard path), scheduling due/not-due/cancel,
transient retry then success, IG validation failure, media validation +
tenant isolation, templates, previews.
