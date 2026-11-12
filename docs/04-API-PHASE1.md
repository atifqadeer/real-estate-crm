# 04 — API Design (Phase 1 surface)

Base: `/api/v1`. JSON envelope: `{ "data": …, "meta": {…pagination} }`;
errors: `{ "error": { "code", "message", "details" } }`.
Auth: `Authorization: Bearer <Sanctum token>`.
Tenant context: `X-Organization-Id: <id>` header on tenant-scoped calls;
validated against membership by middleware (403 if not a member,
404 for cross-tenant resource ids).

## Auth
- `POST /auth/register` — name, email, password (agency self-signup creates the agency org + super_admin membership)
- `POST /auth/login` — email, password → token + user + memberships
- `POST /auth/logout` (auth)
- `GET  /auth/me` (auth) — user + organizations with roles

## Organizations
- `GET    /organizations` (auth) — only organizations the user belongs to
- `POST   /organizations` (agency admin) — create a client workspace
- `GET    /organizations/{id}` (tenant) 
- `PUT    /organizations/{id}` (tenant, `organizations.update`)
- `DELETE /organizations/{id}` (tenant, `organizations.delete`, soft delete)

## Members & invitations
- `GET    /organizations/{id}/members` (tenant, `members.view`)
- `POST   /organizations/{id}/invitations` (tenant, `members.invite`) — email + role
- `POST   /invitations/{token}/accept` — sets password, activates membership
- `PUT    /organizations/{id}/members/{userId}` (tenant, `members.update`) — role/status
- `DELETE /organizations/{id}/members/{userId}` (tenant, `members.remove`)

## Settings, audit, dashboard
- `GET /organizations/{id}/settings` · `PUT …/settings` (`settings.view/update`)
- `GET /organizations/{id}/audit-logs` (`audit.view`, paginated)
- `GET /organizations/{id}/dashboard` (`dashboard.view`) — tenant-scoped
  counts: members, (later phases) leads/posts/campaigns appear here as
  those modules land; Phase 1 returns workspace + membership + activity
  summary only, never fabricated metrics.

## Later-phase endpoints

Defined in the prompt §19 (social-accounts, social-posts, leads,
campaigns, webhooks). They are **not** stubbed in Phase 1 — no
non-functional endpoints (prompt §21).

Rate limiting: `throttle:api` default; stricter on auth + invitations.
All list endpoints: pagination, `?search=`, `?sort=`, filters per resource.
