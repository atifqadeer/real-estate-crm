# 17 — UI & flow optimization (2026-11-26)

Frontend-only pass over the SPA, driven by a full click-through audit
of every main view. No backend changes.

## Flow fixes

- **Sidebar grouped by workflow** instead of one flat list of 14:
  Overview → Leads & Sales (Leads, Lead Campaigns, WhatsApp Inbox) →
  Marketing (Connected Accounts, Content, Campaigns, Analytics) →
  Workspace (Clients, Users and Roles, Billing) → Settings
  (Organization, Platform [agency staff], Audit). This is the order
  work actually moves through the product.
- **Dashboard teaches the flow**: a "Getting started" card with the
  four real steps — connect a Facebook Page → create a lead campaign →
  publish a post → work your leads — each ticked from live data with a
  button to the right screen, and hidden once all four are done.
  Stat cards now show real totals (leads, connected accounts, active
  lead campaigns, posts, members, invitations) instead of only
  members/invitations, and the stale Phase-1 placeholder text
  ("appear here when Phases 2–6 ship") is removed.
- **Empty states lead somewhere**: an empty Leads list explains how
  leads arrive and offers Create a lead campaign / Import / Connect
  Facebook; the Content empty state offers "Create your first post".
  A filtered search with no matches says so, distinctly.
- **Loading states**: Connected Accounts and the Leads list no longer
  flash their empty states while data is loading.
- **Login feedback**: the Sign in button shows "Signing in…" and
  disables while working, and an unreachable backend now says so in
  plain words ("Cannot reach the server — is `php artisan serve`
  running?") instead of a raw browser error.

## Bug fixed along the way

`postList` in api.ts unwrapped the paginator twice, resolving to
`undefined` — the Content page would crash once posts loaded. It now
returns the array `api()` already unwraps. Dashboard totals use the
paginator's real `total` (new `leadTotal`/`postTotal` helpers).

## Verified

Frontend builds clean; browser-verified as client owner and agency
admin: grouped nav, live counts (9 leads / 2 campaigns / 2 posts on
the demo data), guide step states, empty-state CTAs navigating
correctly, platforms rendering, no console errors.
