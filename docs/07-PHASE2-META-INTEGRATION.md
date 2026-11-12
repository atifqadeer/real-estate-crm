# 07 — Phase 2: Social Account Connections (Meta)

Verified against Meta's official documentation on 2026-11-12:
- "Manually Build a Login Flow" — developers.facebook.com/documentation/facebook-login/guides/advanced/manual-flow (updated 2026-06-30; current Graph API version **v27.0**)
- "Long-lived access tokens" — developers.facebook.com/documentation/facebook-login/guides/access-tokens/get-long-lived

## Verified Meta facts (official docs)

1. **OAuth dialog:** `GET https://www.facebook.com/{version}/dialog/oauth`
   with `client_id`, `redirect_uri`, `state` (app-generated, CSRF),
   optional `response_type=code` (default), `scope`, and
   `auth_type=rerequest` for declined permissions. Denial returns
   `error=access_denied&error_reason=user_denied` to the redirect URI.
2. **Code exchange (server-side only):**
   `GET https://graph.facebook.com/{version}/oauth/access_token` with
   `client_id`, `client_secret`, `redirect_uri` (**must be identical** to
   the one that started the flow), `code`. The app secret must never
   appear in client-side code.
3. **Token inspection:** `GET https://graph.facebook.com/debug_token`
   with `input_token` + app access token (`{app-id}|{app-secret}`).
   Response `data` includes `app_id`, `user_id`, `is_valid`, `expires_at`,
   and `scopes` — used by our Test Connection and permission sync.
4. **Long-lived user token:** exchange via the same endpoint with
   `grant_type=fb_exchange_token`; lasts ~60 days; an **expired token
   cannot be exchanged** (user must reconnect). Our refresh job
   re-exchanges *before* expiry.
5. **Page discovery + Page tokens:** `GET /{version}/{user-id}/accounts`
   with the long-lived user token returns the Pages the person manages,
   each with a Page access token. **Page tokens obtained this way have
   no expiration date** (they die if the person changes password, loses
   the Page role, or removes the app) — which is why Test Connection and
   status detection matter more than expiry dates.
6. **Redirect URI** must be registered exactly under Facebook Login →
   Settings → Valid OAuth Redirect URIs in the App Dashboard.
7. **Instagram (Facebook Login path):** the Instagram account must be a
   **professional account (Business or Creator)** linked to a Facebook
   Page; it is discovered per Page via the `instagram_business_account`
   field and is called with the **Page token** on graph.facebook.com.
   Personal Instagram accounts cannot connect. (The separate
   "Instagram API with Instagram Login" path on graph.instagram.com
   exists but is not used in this phase.)

## Access levels & App Review (the commercial gate)

- Every permission has **Standard Access** (works only for people with a
  role on the app — admins/developers/testers — and their assets) and
  **Advanced Access** (works for the public / client accounts).
- Serving real estate clients therefore requires, per permission:
  **App Review approval for Advanced Access** + a **verified business**
  (Business Verification on the agency's Business Manager portfolio),
  a public privacy policy URL, app icon, and a data-deletion callback.
- Each permission is reviewed independently with its own justification
  and screencast. Requesting permissions the app does not use is the
  most common rejection cause — our requested set is deliberately tight.

## Permissions requested at connect (env: META_SCOPES)

| Permission | Used for | Phase |
|---|---|---|
| `pages_show_list` | Discover the Pages a person manages | 2 |
| `pages_read_engagement` | Read Page info/content; dependency of manage_posts | 2–3 |
| `pages_manage_posts` | Create/edit/delete Page posts | 3 |
| `instagram_basic` | Discover/read the linked IG professional account | 2 |
| `instagram_content_publish` | Publish to Instagram | 3 |

Declined permissions are recorded per account (`social_account_permissions`
with status `declined`) and surface as `permission_required` status;
reconnect uses `auth_type=rerequest`.

## What Atif must do in the Meta App Dashboard (one-time)

1. developers.facebook.com → Create App (Business type), connect the
   agency's Business Manager portfolio.
2. Add the **Facebook Login** product; set **Valid OAuth Redirect URIs**
   to exactly: `https://<api-domain>/api/v1/social/meta/callback`
   (local dev: `http://localhost:8000/api/v1/social/meta/callback`).
3. Settings → Basic: copy **App ID** and **App Secret** into the server
   `.env` (`META_APP_ID`, `META_APP_SECRET`) — never into chat or git.
4. Add privacy policy URL + app icon; complete **Business Verification**.
5. App Review: submit the five permissions above with screencasts of
   the connect → discover → (Phase 3) publish flow.
6. While in Development/Standard Access, add client testers as app
   testers if you want to pilot before Advanced Access lands.

## Implementation map (this codebase)

- `app/Services/Meta/MetaGraphClient.php` — thin Graph API client (v27.0 default, env-overridable)
- `app/Services/Meta/MetaConnectionService.php` — OAuth URL + state, callback handling, Page/IG discovery, test-connection, resync
- `app/Jobs/RefreshMetaTokens.php` — daily re-exchange of long-lived user tokens expiring within 7 days, then account resync
- Tables: `social_platforms`, `social_accounts`, `social_account_tokens`
  (encrypted casts), `social_account_permissions`,
  `social_oauth_identities` (the connecting person's long-lived token,
  encrypted), `social_account_logs`, `social_webhook_subscriptions`
- Statuses: connected / disconnected / expired / permission_required /
  error / pending — driven by debug_token + node checks, never guessed
- Tests mock the Graph API with Laravel `Http::fake` (clearly labelled);
  a **live** connection test still requires Atif's real Meta app
  credentials and, for client Pages, Advanced Access.
