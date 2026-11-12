const API = import.meta.env.VITE_API_URL ?? 'http://127.0.0.1:8000/api/v1';

export interface Membership {
  organization_id: number;
  business_name: string;
  type: 'agency' | 'real_estate_client';
  status: string;
  roles: string[];
  permissions: string[];
}

export interface Me { id: number; name: string; email: string; memberships: Membership[] }

const token = () => localStorage.getItem('crm_token');
export const setToken = (t: string | null) => t ? localStorage.setItem('crm_token', t) : localStorage.removeItem('crm_token');
export const hasToken = () => !!token();

export async function api<T>(path: string, orgId?: number, init: RequestInit = {}): Promise<T> {
  const res = await fetch(`${API}${path}`, {
    ...init,
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      ...(token() ? { Authorization: `Bearer ${token()}` } : {}),
      ...(orgId ? { 'X-Organization-Id': String(orgId) } : {}),
      ...(init.headers ?? {}),
    },
  });
  if (!res.ok) {
    const body = await res.json().catch(() => ({}));
    throw new Error(body?.error?.message ?? body?.message ?? `Request failed (${res.status})`);
  }
  const body = await res.json();
  return (body.data ?? body) as T;
}

export const login = (email: string, password: string) =>
  api<{ token: string; user: Me }>('/auth/login', undefined, { method: 'POST', body: JSON.stringify({ email, password }) });

export const me = () => api<Me>('/auth/me');

export interface Dashboard {
  organization: { id: number; business_name: string; type: string; city: string | null; currency: string; timezone: string };
  members_count: number;
  pending_invitations: number;
  recent_activity: { action: string; created_at: string; user: string | null }[];
}

export const dashboard = (orgId: number) => api<Dashboard>(`/organizations/${orgId}/dashboard`, orgId);
export const members = (orgId: number) => api<any[]>(`/organizations/${orgId}/members`, orgId);
export const auditLogs = (orgId: number) => api<any[]>(`/organizations/${orgId}/audit-logs`, orgId);
export const getSettings = (orgId: number) => api<any>(`/organizations/${orgId}/settings`, orgId);
export const saveSettings = (orgId: number, payload: any) =>
  api<any>(`/organizations/${orgId}/settings`, orgId, { method: 'PUT', body: JSON.stringify(payload) });
export const inviteMember = (orgId: number, email: string, role: string) =>
  api<any>(`/organizations/${orgId}/invitations`, orgId, { method: 'POST', body: JSON.stringify({ email, role }) });
export const createOrganization = (payload: any) =>
  api<any>('/organizations', undefined, { method: 'POST', body: JSON.stringify(payload) });

// --- Phase 2: social account connections ---
export interface SocialPlatform {
  id: number; key: string; name: string; is_connectable: boolean;
  capabilities: Record<string, unknown> | null; notes: string | null;
}
export interface SocialAccount {
  id: number; platform: string; external_id: string; username: string | null;
  display_name: string; profile_picture_url: string | null; status: string;
  connected_by: string | null; last_synced_at: string | null; last_error: string | null;
  metadata: Record<string, unknown> | null;
  permissions: { permission: string; status: string }[];
  token: { type: string; expires_at: string | null; scopes: string[] | null } | null;
}
export const socialPlatforms = (orgId: number) => api<SocialPlatform[]>(`/organizations/${orgId}/social/platforms`, orgId);
export const socialAccounts = (orgId: number) => api<SocialAccount[]>(`/organizations/${orgId}/social/accounts`, orgId);
export const socialConnect = (orgId: number, platform: string, rerequest = false) =>
  api<{ url: string }>(`/organizations/${orgId}/social/connect/${platform}${rerequest ? '?rerequest=1' : ''}`, orgId);
export const socialTest = (orgId: number, accountId: number) =>
  api<SocialAccount>(`/organizations/${orgId}/social/accounts/${accountId}/test`, orgId, { method: 'POST' });
export const socialDisconnect = (orgId: number, accountId: number) =>
  api<any>(`/organizations/${orgId}/social/accounts/${accountId}`, orgId, { method: 'DELETE' });
export const socialLogs = (orgId: number) => api<any>(`/organizations/${orgId}/social/logs`, orgId);
