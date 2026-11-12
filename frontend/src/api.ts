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

// --- Phase 3: media, content, publishing ---
export interface MediaAsset {
  id: number; kind: 'image' | 'video'; public_url: string | null; original_name: string;
  mime_type: string; size_bytes: number; width: number | null; height: number | null; created_at: string;
}
export interface PostTarget {
  id: number; social_account_id: number; account_name?: string; platform: string; status: string;
  platform_post_id: string | null; platform_url: string | null; error_message: string | null;
  caption_override: string | null; hashtags_override: string | null;
}
export interface ContentPost {
  id: number; title: string | null; caption: string | null; hashtags: string | null;
  link_url: string | null; cta_text: string | null; first_comment: string | null;
  internal_notes: string | null; status: string; approval_required: boolean;
  scheduled_at: string | null; published_at: string | null; author?: string | null;
  targets: PostTarget[]; media: { id: number; kind: string; url: string | null; name: string }[];
  approvals?: { action: string; comment: string | null; actor: string | null; at: string }[];
}
export interface Destination {
  account: { id: number; platform: string; display_name: string; username: string | null; status: string };
  eligible: boolean; reasons: string[]; capabilities: Record<string, unknown>;
}
export interface ContentTemplate { id: number; name: string; category: string; caption: string | null; hashtags: string | null; is_system: boolean }

export const mediaList = (orgId: number) => api<{ data: MediaAsset[] }>(`/organizations/${orgId}/media?per_page=30`, orgId).then(r => r.data);
export const mediaDelete = (orgId: number, id: number) => api<any>(`/organizations/${orgId}/media/${id}`, orgId, { method: 'DELETE' });
export async function mediaUpload(orgId: number, file: File): Promise<MediaAsset> {
  const t = token();
  const form = new FormData();
  form.append('file', file);
  const res = await fetch(`${API}/organizations/${orgId}/media`, {
    method: 'POST',
    headers: { Accept: 'application/json', ...(t ? { Authorization: `Bearer ${t}` } : {}), 'X-Organization-Id': String(orgId) },
    body: form,
  });
  const body = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(body?.error?.message ?? body?.message ?? `Upload failed (${res.status})`);
  return body.data as MediaAsset;
}
export const postList = (orgId: number) => api<{ data: ContentPost[] }>(`/organizations/${orgId}/content/posts?per_page=20`, orgId).then(r => r.data);
export const postGet = (orgId: number, id: number) => api<ContentPost>(`/organizations/${orgId}/content/posts/${id}`, orgId);
export const postCreate = (orgId: number, payload: any) => api<ContentPost>(`/organizations/${orgId}/content/posts`, orgId, { method: 'POST', body: JSON.stringify(payload) });
export const postUpdate = (orgId: number, id: number, payload: any) => api<ContentPost>(`/organizations/${orgId}/content/posts/${id}`, orgId, { method: 'PUT', body: JSON.stringify(payload) });
export const postDelete = (orgId: number, id: number) => api<any>(`/organizations/${orgId}/content/posts/${id}`, orgId, { method: 'DELETE' });
export const postDuplicate = (orgId: number, id: number) => api<ContentPost>(`/organizations/${orgId}/content/posts/${id}/duplicate`, orgId, { method: 'POST' });
export const postDestinations = (orgId: number) => api<Destination[]>(`/organizations/${orgId}/content/destinations`, orgId);
export const postPreviews = (orgId: number, id: number) => api<any[]>(`/organizations/${orgId}/content/posts/${id}/previews`, orgId);
export const postAction = (orgId: number, id: number, action: string, payload?: any) =>
  api<ContentPost>(`/organizations/${orgId}/content/posts/${id}/${action}`, orgId, { method: 'POST', body: JSON.stringify(payload ?? {}) });
export const postRetryTarget = (orgId: number, postId: number, targetId: number) =>
  api<ContentPost>(`/organizations/${orgId}/content/posts/${postId}/targets/${targetId}/retry`, orgId, { method: 'POST' });
export const postCalendar = (orgId: number, from: string, to: string) =>
  api<ContentPost[]>(`/organizations/${orgId}/content/calendar?from=${from}&to=${to}`, orgId);
export const templateList = (orgId: number) => api<ContentTemplate[]>(`/organizations/${orgId}/templates`, orgId);
export const templateCreate = (orgId: number, payload: any) => api<ContentTemplate>(`/organizations/${orgId}/templates`, orgId, { method: 'POST', body: JSON.stringify(payload) });
export const templateDelete = (orgId: number, id: number) => api<any>(`/organizations/${orgId}/templates/${id}`, orgId, { method: 'DELETE' });
export const templateSeed = (orgId: number) => api<{ created: number }>(`/organizations/${orgId}/templates/seed-starters`, orgId, { method: 'POST' });

// --- Phase 4: leads ---
export interface LeadStage { id: number; name: string; slug: string; is_won: boolean; is_lost: boolean; is_system: boolean }
export interface LeadSourceT { id: number; key: string; name: string; is_system: boolean }
export interface Lead {
  id: number; full_name: string; phone_e164: string | null; whatsapp_e164: string | null; email: string | null;
  city: string | null; area: string | null; property_type: string | null; property_reference: string | null;
  intent: string | null; preferred_location: string | null; budget_min: string | null; budget_max: string | null;
  priority: string; campaign_name: string | null; ad_name: string | null; meta_form_id: string | null;
  utm_source: string | null; utm_campaign: string | null; notes: string | null;
  custom_fields: Record<string, string> | null; possible_duplicate_of: number | null;
  created_at: string; source?: { id: number; name: string; key: string } | null;
  stage?: { id: number; name: string; slug: string } | null; assignee?: { id: number; name: string } | null;
  assigned_to: number | null; lead_pipeline_stage_id: number | null;
  touchpoints?: any[]; activities?: any[]; follow_ups?: any[];
  possible_duplicate_of_lead?: { id: number; full_name: string; phone_e164: string | null } | null;
}
export interface LeadFormT { id: number; name: string; public_key: string; property_reference: string | null; intent: string | null; success_message: string; is_active: boolean; submissions_count: number }
export interface FollowUp { id: number; lead_id: number; due_at: string; note: string | null; status: string; lead?: { id: number; full_name: string; phone_e164: string | null }; user?: { id: number; name: string } }

export const leadStages = (orgId: number) => api<LeadStage[]>(`/organizations/${orgId}/lead-stages`, orgId);
export const leadStageCreate = (orgId: number, payload: any) => api<LeadStage>(`/organizations/${orgId}/lead-stages`, orgId, { method: 'POST', body: JSON.stringify(payload) });
export const leadSources = (orgId: number) => api<LeadSourceT[]>(`/organizations/${orgId}/lead-sources`, orgId);
export const leadList = (orgId: number, params = '') => api<{ data: Lead[]; total: number }>(`/organizations/${orgId}/leads${params}`, orgId);
export const leadGet = (orgId: number, id: number) => api<Lead>(`/organizations/${orgId}/leads/${id}`, orgId);
export const leadCreate = (orgId: number, payload: any) => api<{ data: Lead; outcome: string }>(`/organizations/${orgId}/leads`, orgId, { method: 'POST', body: JSON.stringify(payload) });
export const leadUpdate = (orgId: number, id: number, payload: any) => api<Lead>(`/organizations/${orgId}/leads/${id}`, orgId, { method: 'PUT', body: JSON.stringify(payload) });
export const leadDelete = (orgId: number, id: number) => api<any>(`/organizations/${orgId}/leads/${id}`, orgId, { method: 'DELETE' });
export const leadAddNote = (orgId: number, id: number, note: string, markContacted: boolean) =>
  api<any>(`/organizations/${orgId}/leads/${id}/notes`, orgId, { method: 'POST', body: JSON.stringify({ note, mark_contacted: markContacted }) });
export const leadMySummary = (orgId: number) => api<Record<string, number>>(`/organizations/${orgId}/leads/my-summary`, orgId);
export const leadBackfill = (orgId: number, payload: any) => api<any>(`/organizations/${orgId}/leads/meta/backfill`, orgId, { method: 'POST', body: JSON.stringify(payload) });
export const leadForms = (orgId: number) => api<LeadFormT[]>(`/organizations/${orgId}/lead-forms`, orgId);
export const leadFormCreate = (orgId: number, payload: any) => api<LeadFormT>(`/organizations/${orgId}/lead-forms`, orgId, { method: 'POST', body: JSON.stringify(payload) });
export const leadFormUpdate = (orgId: number, id: number, payload: any) => api<LeadFormT>(`/organizations/${orgId}/lead-forms/${id}`, orgId, { method: 'PUT', body: JSON.stringify(payload) });
export const leadFormDelete = (orgId: number, id: number) => api<any>(`/organizations/${orgId}/lead-forms/${id}`, orgId, { method: 'DELETE' });
export const followUpList = (orgId: number, scope = '') => api<{ data: FollowUp[] }>(`/organizations/${orgId}/follow-ups${scope}`, orgId);
export const followUpCreate = (orgId: number, payload: any) => api<FollowUp>(`/organizations/${orgId}/follow-ups`, orgId, { method: 'POST', body: JSON.stringify(payload) });
export const followUpComplete = (orgId: number, id: number) => api<FollowUp>(`/organizations/${orgId}/follow-ups/${id}/complete`, orgId, { method: 'POST' });
export const leadRules = (orgId: number) => api<any[]>(`/organizations/${orgId}/lead-rules`, orgId);
export const leadRuleCreate = (orgId: number, payload: any) => api<any>(`/organizations/${orgId}/lead-rules`, orgId, { method: 'POST', body: JSON.stringify(payload) });
export const leadRuleDelete = (orgId: number, id: number) => api<any>(`/organizations/${orgId}/lead-rules/${id}`, orgId, { method: 'DELETE' });
export const socialSubscribeLeads = (orgId: number, accountId: number) =>
  api<any>(`/organizations/${orgId}/social/accounts/${accountId}/subscribe-leads`, orgId, { method: 'POST' });
export async function leadImportUpload(orgId: number, file: File): Promise<any> {
  const t = token();
  const form = new FormData();
  form.append('file', file);
  const res = await fetch(`${API}/organizations/${orgId}/lead-imports`, {
    method: 'POST',
    headers: { Accept: 'application/json', ...(t ? { Authorization: `Bearer ${t}` } : {}), 'X-Organization-Id': String(orgId) },
    body: form,
  });
  const body = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(body?.error?.message ?? body?.message ?? `Import failed (${res.status})`);
  return body;
}
