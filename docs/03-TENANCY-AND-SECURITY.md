# 03 — Tenant Isolation, Roles & Security

## Roles (prompt §3)

Agency-level: `super_admin`, `agency_admin`, `agency_marketing_manager`,
`agency_marketing_executive`, `agency_account_manager`, `agency_analyst`.

Client-level: `client_owner`, `client_admin`, `client_marketing_approver`,
`client_sales_manager`, `client_sales_agent`, `client_readonly`.

## How per-organization roles work

Spatie Permission is used with its **teams feature**, where the "team" is
the organization. A role assignment therefore reads
(user, organization, role). The `ResolveTenant` middleware sets the
Spatie team id to the active organization on every request, so
`$user->hasPermissionTo(...)` always answers for *this* workspace only.
Agency staff reach a client workspace only through an explicit membership
on that client organization (created by an agency admin or the client
owner) — there is no implicit "agency sees everything" backdoor except
`super_admin`, whose cross-tenant actions are audit-logged.

## Permission groups (Phase 1 subset)

- `organizations.view/create/update/delete`
- `members.view/invite/update/remove`
- `settings.view/update`
- `audit.view`
- `dashboard.view`
- Later phases add `social.*`, `posts.*`, `leads.*`, `campaigns.*`,
  `reports.*`, `exports.*` under the same scheme.

Default mapping (seeded): client_owner = all client permissions;
client_admin = all except organization delete; sales roles get dashboard
+ (later) leads; client_readonly = view-only; agency roles scoped to
assigned workspaces via memberships.

## Tenant isolation rules (enforced, not cosmetic)

1. Middleware resolves tenant from membership — an `X-Organization-Id`
   from the browser is a *request*, never proof (prompt §5).
2. `TenantScope` global scope on tenant-owned models filters by the
   current organization id; cross-tenant rows return 404, not 403
   (do not leak existence).
3. Policies check action permission within the active membership.
4. Jobs/events carry `organization_id` and re-validate on handling.
5. Files live under `org/{id}/…`; downloads go through authorization +
   signed URLs; exports are tenant-scoped and audit-logged.
6. Webhooks (later phases) resolve tenant from the verified provider
   account id — never from payload-supplied org ids.

## Credential & secret handling

- Social tokens (Phase 2): Laravel encrypted casts, separate
  `social_account_tokens` table, never returned by any API resource,
  never logged; token operations (connect/disconnect/refresh) are
  audit-logged without values.
- Invitation tokens: only SHA-256 hash stored.
- Secrets only via environment/secret manager; `.env.example` contains
  placeholders only.

## Audit logging (Phase 1)

`AuditLogger` service writes `audit_logs` for: organization created/
updated, member invited/role-changed/removed, settings updated,
invitation accepted, login (via auth event). Metadata is redacted
(no tokens, passwords, or full PII payloads).

## Acceptance tests that prove isolation (Phase 1 gate)

- Client A user cannot GET/PUT Client B's organization or settings (404).
- Client A user cannot list Client B's members.
- Agency staff without membership on Client B gets 403/404 on Client B.
- A user with different roles in A and B gets A's permissions in A only.
- Audit rows are written for member + settings changes.
