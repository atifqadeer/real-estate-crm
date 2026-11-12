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
