import { useEffect, useState } from 'react';
import {
  login, me, dashboard, members, auditLogs, getSettings, saveSettings,
  inviteMember, createOrganization, setToken, hasToken,
  socialPlatforms, socialAccounts, socialConnect, socialTest, socialDisconnect,
  mediaList, mediaUpload, mediaDelete, postList, postGet, postCreate, postUpdate, postDelete,
  postDuplicate, postDestinations, postPreviews, postAction, postRetryTarget, postCalendar,
  templateList, templateCreate, templateDelete, templateSeed,
  leadStages, leadStageCreate, leadSources, leadList, leadGet, leadCreate, leadUpdate, leadDelete, leadAddNote,
  leadMySummary, leadBackfill, leadForms, leadFormCreate, leadFormUpdate, leadFormDelete,
  followUpList, followUpCreate, followUpComplete, leadRules, leadRuleCreate, leadRuleDelete,
  leadImportUpload, socialSubscribeLeads,
  pipelineBoard, leadMove, savedFilters, savedFilterCreate, savedFilterDelete,
  taskList, taskCreate, taskUpdate, taskDelete,
  notificationList, notificationReadAll, notificationRead, notificationPreferences, notificationPreferencesUpdate,
  waAccounts, waConnect, waDisconnect, waTemplates, waSyncTemplates, waConversations, waConversation,
  waStartForLead, waSendText, waSendTemplate,
  campaignList, campaignGet, campaignCreate, campaignUpdate, campaignDelete, campaignAddSpend,
  campaignDeleteSpend, campaignAddGroup, campaignAddAd, campaignCompare, campaignSyncMeta,
  analyticsOverview, analyticsAgency, analyticsExportDownload,
  billingOverview, billingPackages, billingPackageCreate, billingPackageDelete, billingSubscriptions,
  billingSubscribe, billingSubscriptionStatus, billingInvoiceCreate, billingInvoiceUpdate, aiSuggest,
  type Me, type Dashboard, type SocialPlatform, type SocialAccount,
  type MediaAsset, type ContentPost, type Destination, type ContentTemplate,
  type Lead, type LeadStage, type LeadSourceT, type LeadFormT, type FollowUp,
  type PipelineColumn, type SavedFilter, type LeadTaskT, type AppNotification,
  type WhatsAppAccountT, type WhatsAppTemplateT, type WhatsAppConversationT,
  type CampaignT, type CampaignMetrics,
} from './api';

// Shell rule (prompt): only modules that exist are shown — no fake buttons.
type View = 'dashboard' | 'clients' | 'social' | 'content' | 'leads' | 'whatsapp' | 'campaigns' | 'analytics' | 'billing' | 'members' | 'settings' | 'audit';

const NAV: { id: View; label: string; permission: string }[] = [
  { id: 'dashboard', label: 'Dashboard', permission: 'dashboard.view' },
  { id: 'clients', label: 'Clients / Organizations', permission: 'organizations.view' },
  { id: 'social', label: 'Connected Accounts', permission: 'social.view' },
  { id: 'content', label: 'Content & Publishing', permission: 'content.view' },
  { id: 'leads', label: 'Leads', permission: 'leads.view' },
  { id: 'whatsapp', label: 'WhatsApp Inbox', permission: 'whatsapp.view' },
  { id: 'campaigns', label: 'Campaigns', permission: 'campaigns.view' },
  { id: 'analytics', label: 'Analytics', permission: 'analytics.view' },
  { id: 'billing', label: 'Billing & Packages', permission: 'billing.view' },
  { id: 'members', label: 'Users and Roles', permission: 'members.view' },
  { id: 'settings', label: 'Organization Settings', permission: 'settings.view' },
  { id: 'audit', label: 'Audit Logs', permission: 'audit.view' },
];

export default function App() {
  const [user, setUser] = useState<Me | null>(null);
  const [orgId, setOrgId] = useState<number | null>(null);
  const [view, setView] = useState<View>('dashboard');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  useEffect(() => {
    // Meta OAuth returns to the SPA root with ?meta=connected|denied|error
    const params = new URLSearchParams(window.location.search);
    const meta = params.get('meta');
    if (meta) {
      setView('social');
      if (meta === 'connected') setNotice(`Meta connected — ${params.get('accounts') ?? ''} Facebook Page(s) discovered, with any linked Instagram accounts.`);
      else setError(`Meta connection ${meta}: ${params.get('message') ?? 'please try again.'}`);
      window.history.replaceState({}, '', window.location.pathname);
    }
    if (hasToken()) me().then(u => { setUser(u); setOrgId(u.memberships[0]?.organization_id ?? null); }).catch(() => setToken(null));
  }, []);

  if (!user) return <Login onDone={(u) => { setUser(u); setOrgId(u.memberships[0]?.organization_id ?? null); }} />;

  const membership = user.memberships.find(m => m.organization_id === orgId) ?? user.memberships[0];
  const perms = membership?.permissions ?? [];
  const can = (p: string) => perms.includes(p);

  // White-label branding (Phase 7): workspace brand name / logo / color.
  const [branding, setBranding] = useState<{ brand_name?: string; logo_url?: string; primary_color?: string }>({});
  useEffect(() => {
    if (!membership) return;
    if (!can('settings.view')) { setBranding({}); return; }
    getSettings(membership.organization_id)
      .then((s: any) => setBranding(s?.settings?.branding ?? {}))
      .catch(() => setBranding({}));
  }, [membership?.organization_id]);

  return (
    <div className="flex min-h-screen bg-slate-100 text-slate-900">
      <aside className="w-64 shrink-0 text-slate-100 p-4 flex flex-col gap-4" style={{ backgroundColor: branding.primary_color ?? '#0f172a' }}>
        <div>
          {branding.logo_url && <img src={branding.logo_url} alt="" className="mb-1 h-9 w-auto rounded bg-white/90 p-0.5" />}
          <div className="text-lg font-bold">{branding.brand_name ?? 'Agency CRM'}</div>
          <div className="text-xs text-slate-300/80">Real Estate Marketing · Pakistan</div>
        </div>
        <label className="text-xs text-slate-400">Workspace
          <select className="mt-1 w-full rounded bg-slate-800 p-2 text-sm text-white"
            value={membership?.organization_id}
            onChange={e => { setOrgId(Number(e.target.value)); setView('dashboard'); }}>
            {user.memberships.map(m => <option key={m.organization_id} value={m.organization_id}>{m.business_name}</option>)}
          </select>
        </label>
        <nav className="flex flex-col gap-1">
          {NAV.filter(n => can(n.permission)).map(n => (
            <button key={n.id} onClick={() => setView(n.id)}
              className={`rounded px-3 py-2 text-left text-sm ${view === n.id ? 'bg-emerald-600 text-white' : 'hover:bg-slate-800'}`}>
              {n.label}
            </button>
          ))}
        </nav>
        {membership && <NotificationBell orgId={membership.organization_id} setError={setError} />}
        <div className="mt-auto text-xs text-slate-400">
          <div>{user.name}</div><div>{user.email}</div>
          <div className="mt-1">Role: {membership?.roles.join(', ')}</div>
          <button className="mt-2 underline" onClick={() => { setToken(null); setUser(null); }}>Sign out</button>
        </div>
      </aside>
      <main className="flex-1 p-6">
        {error && <div className="mb-4 rounded bg-red-100 p-3 text-sm text-red-800">{error}</div>}
        {notice && <div className="mb-4 rounded bg-emerald-100 p-3 text-sm text-emerald-900">{notice}</div>}
        {membership && view === 'dashboard' && <DashboardView orgId={membership.organization_id} setError={setError} />}
        {membership && view === 'social' && <SocialView orgId={membership.organization_id}
          canConnect={can('social.connect')} canTest={can('social.test')} canDisconnect={can('social.disconnect')} setError={setError} />}
        {membership && view === 'content' && <ContentView orgId={membership.organization_id} can={can} setError={setError} />}
        {membership && view === 'leads' && <LeadsView orgId={membership.organization_id} can={can} setError={setError} />}
        {membership && view === 'whatsapp' && <WhatsAppView orgId={membership.organization_id} can={can} setError={setError} />}
        {membership && view === 'campaigns' && <CampaignsView orgId={membership.organization_id} can={can} setError={setError} />}
        {membership && view === 'analytics' && <AnalyticsView orgId={membership.organization_id} orgType={membership.type} can={can} setError={setError} />}
        {membership && view === 'billing' && <BillingView orgId={membership.organization_id} orgType={membership.type} can={can} setError={setError}
          clientOptions={user.memberships.filter(m => m.type === 'real_estate_client').map(m => ({ id: m.organization_id, business_name: m.business_name }))} />}
        {membership && view === 'clients' && <ClientsView user={user} canCreate={can('organizations.create')} setError={setError}
          onCreated={() => me().then(setUser)} />}
        {membership && view === 'members' && <MembersView orgId={membership.organization_id} canInvite={can('members.invite')} setError={setError} />}
        {membership && view === 'settings' && <SettingsView orgId={membership.organization_id} canUpdate={can('settings.update')} setError={setError} />}
        {membership && view === 'audit' && <AuditView orgId={membership.organization_id} setError={setError} />}
      </main>
    </div>
  );
}

function Login({ onDone }: { onDone: (u: Me) => void }) {
  const [email, setEmail] = useState('admin@agency.demo');
  const [password, setPassword] = useState('password');
  const [err, setErr] = useState('');
  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-100">
      <form className="w-96 rounded-xl bg-white p-6 shadow"
        onSubmit={async e => { e.preventDefault(); try { const r = await login(email, password); setToken(r.token); onDone(r.user); } catch (ex: any) { setErr(ex.message); } }}>
        <h1 className="text-xl font-bold">Agency CRM</h1>
        <p className="mb-4 text-sm text-slate-500">Sign in to your workspace</p>
        {err && <div className="mb-3 rounded bg-red-100 p-2 text-sm text-red-800">{err}</div>}
        <label className="block text-sm">Email<input className="mt-1 w-full rounded border p-2" value={email} onChange={e => setEmail(e.target.value)} /></label>
        <label className="mt-3 block text-sm">Password<input type="password" className="mt-1 w-full rounded border p-2" value={password} onChange={e => setPassword(e.target.value)} /></label>
        <button className="mt-4 w-full rounded bg-emerald-600 py-2 text-white">Sign in</button>
        <p className="mt-3 text-xs text-slate-400">Demo seed accounts use password "password" (local development only).</p>
      </form>
    </div>
  );
}

function DashboardView({ orgId, setError }: { orgId: number; setError: (s: string) => void }) {
  const [d, setD] = useState<Dashboard | null>(null);
  useEffect(() => { dashboard(orgId).then(setD).catch(e => setError(e.message)); }, [orgId]);
  if (!d) return <p>Loading…</p>;
  return (
    <div>
      <h2 className="text-2xl font-bold">{d.organization.business_name}</h2>
      <p className="text-sm text-slate-500">{d.organization.city ?? ''} · {d.organization.currency} · {d.organization.timezone}</p>
      <div className="mt-4 grid grid-cols-2 gap-4 md:grid-cols-3">
        <Card label="Active members" value={d.members_count} />
        <Card label="Pending invitations" value={d.pending_invitations} />
      </div>
      <h3 className="mt-6 font-semibold">Recent activity</h3>
      <ul className="mt-2 divide-y rounded bg-white">
        {d.recent_activity.map((a, i) => <li key={i} className="p-3 text-sm">{a.action} — {a.user ?? 'system'} · {new Date(a.created_at).toLocaleString()}</li>)}
        {d.recent_activity.length === 0 && <li className="p-3 text-sm text-slate-500">No activity yet.</li>}
      </ul>
      <p className="mt-4 text-xs text-slate-500">Leads, posts and campaign metrics appear here when Phases 2–6 ship. Nothing is simulated.</p>
    </div>
  );
}

const Card = ({ label, value }: { label: string; value: number }) => (
  <div className="rounded-xl bg-white p-4 shadow-sm"><div className="text-3xl font-bold">{value}</div><div className="text-sm text-slate-500">{label}</div></div>
);

function ClientsView({ user, canCreate, setError, onCreated }: { user: Me; canCreate: boolean; setError: (s: string) => void; onCreated: () => void }) {
  const [name, setName] = useState(''); const [city, setCity] = useState('Karachi');
  return (
    <div>
      <h2 className="text-2xl font-bold">Clients / Organizations</h2>
      <ul className="mt-4 divide-y rounded bg-white">
        {user.memberships.map(m => <li key={m.organization_id} className="p-3 text-sm"><b>{m.business_name}</b> — {m.type} · your role: {m.roles.join(', ')}</li>)}
      </ul>
      {canCreate && (
        <form className="mt-6 rounded bg-white p-4" onSubmit={async e => { e.preventDefault(); try { await createOrganization({ business_name: name, city, contact_person: name, email: `${name.toLowerCase().replace(/[^a-z0-9]+/g, '')}@client.demo` }); setName(''); onCreated(); } catch (ex: any) { setError(ex.message); } }}>
          <h3 className="font-semibold">Create client workspace</h3>
          <div className="mt-2 flex gap-2">
            <input className="flex-1 rounded border p-2" placeholder="Business name" value={name} onChange={e => setName(e.target.value)} required />
            <input className="w-40 rounded border p-2" placeholder="City" value={city} onChange={e => setCity(e.target.value)} />
            <button className="rounded bg-emerald-600 px-4 text-white">Create</button>
          </div>
        </form>
      )}
    </div>
  );
}

function MembersView({ orgId, canInvite, setError }: { orgId: number; canInvite: boolean; setError: (s: string) => void }) {
  const [rows, setRows] = useState<any[]>([]); const [email, setEmail] = useState(''); const [role, setRole] = useState('client_sales_agent');
  const load = () => members(orgId).then(setRows).catch(e => setError(e.message));
  useEffect(() => { load(); }, [orgId]);
  return (
    <div>
      <h2 className="text-2xl font-bold">Users and Roles</h2>
      <table className="mt-4 w-full rounded bg-white text-sm"><thead><tr className="text-left text-slate-500"><th className="p-3">Name</th><th>Email</th><th>Roles</th><th>Status</th></tr></thead>
        <tbody>{rows.map(r => <tr key={r.id} className="border-t"><td className="p-3">{r.user?.name}</td><td>{r.user?.email}</td><td>{(r.roles ?? []).join(', ')}</td><td>{r.status}</td></tr>)}</tbody></table>
      {canInvite && (
        <form className="mt-4 flex gap-2" onSubmit={async e => { e.preventDefault(); try { await inviteMember(orgId, email, role); setEmail(''); } catch (ex: any) { setError(ex.message); } }}>
          <input className="flex-1 rounded border p-2" placeholder="Invite by email" value={email} onChange={e => setEmail(e.target.value)} required />
          <select className="rounded border p-2" value={role} onChange={e => setRole(e.target.value)}>
            {['client_owner','client_admin','client_marketing_approver','client_sales_manager','client_sales_agent','client_readonly','agency_marketing_manager','agency_marketing_executive','agency_account_manager','agency_analyst'].map(r => <option key={r}>{r}</option>)}
          </select>
          <button className="rounded bg-emerald-600 px-4 text-white">Invite</button>
        </form>
      )}
    </div>
  );
}

function SettingsView({ orgId, canUpdate, setError }: { orgId: number; canUpdate: boolean; setError: (s: string) => void }) {
  const [s, setS] = useState<any>(null);
  useEffect(() => { getSettings(orgId).then(setS).catch(e => setError(e.message)); }, [orgId]);
  if (!s) return <p>Loading…</p>;
  const branding = s.settings?.branding ?? {};
  const setBranding = (patch: any) => setS({ ...s, settings: { ...(s.settings ?? {}), branding: { ...branding, ...patch } } });
  return (
    <form onSubmit={async e => { e.preventDefault(); try { await saveSettings(orgId, { lead_assignment_mode: s.lead_assignment_mode, data_retention_days: s.data_retention_days, settings: s.settings }); } catch (ex: any) { setError(ex.message); } }}>
      <h2 className="text-2xl font-bold">Organization Settings</h2>
      <fieldset className="mt-4 rounded-xl bg-white p-4 shadow-sm">
        <legend className="px-1 text-sm font-semibold">White-label branding</legend>
        <label className="mt-1 block text-sm">Brand name (shown in the sidebar instead of "Agency CRM")
          <input disabled={!canUpdate} className="mt-1 w-full rounded border p-2" placeholder="e.g. Acme Properties CRM" value={branding.brand_name ?? ''} onChange={e => setBranding({ brand_name: e.target.value })} /></label>
        <label className="mt-3 block text-sm">Logo URL
          <input disabled={!canUpdate} className="mt-1 w-full rounded border p-2" placeholder="https://…" value={branding.logo_url ?? ''} onChange={e => setBranding({ logo_url: e.target.value })} /></label>
        <label className="mt-3 block text-sm">Primary color
          <input disabled={!canUpdate} type="color" className="mt-1 h-10 w-24 rounded border p-1" value={branding.primary_color ?? '#0f172a'} onChange={e => setBranding({ primary_color: e.target.value })} /></label>
      </fieldset>
      <label className="mt-4 block text-sm">Lead assignment mode
        <select disabled={!canUpdate} className="mt-1 rounded border p-2" value={s.lead_assignment_mode} onChange={e => setS({ ...s, lead_assignment_mode: e.target.value })}>
          {['manual','round_robin','location','category'].map(m => <option key={m}>{m}</option>)}
        </select></label>
      <label className="mt-3 block text-sm">Data retention (days, blank = no limit)
        <input disabled={!canUpdate} className="mt-1 rounded border p-2" value={s.data_retention_days ?? ''} onChange={e => setS({ ...s, data_retention_days: e.target.value === '' ? null : Number(e.target.value) })} /></label>
      {canUpdate && <button className="mt-4 rounded bg-emerald-600 px-4 py-2 text-white">Save settings</button>}
    </form>
  );
}

function AuditView({ orgId, setError }: { orgId: number; setError: (s: string) => void }) {
  const [rows, setRows] = useState<any[]>([]);
  useEffect(() => { auditLogs(orgId).then(r => setRows((r as any).data ?? r)).catch(e => setError(e.message)); }, [orgId]);
  return (
    <div><h2 className="text-2xl font-bold">Audit Logs</h2>
      <ul className="mt-4 divide-y rounded bg-white">{rows.map(r => <li key={r.id} className="p-3 text-sm">{r.action} — {r.user?.name ?? 'system'} · {new Date(r.created_at).toLocaleString()}</li>)}</ul></div>
  );
}

const STATUS_STYLES: Record<string, string> = {
  connected: 'bg-emerald-100 text-emerald-800',
  disconnected: 'bg-slate-200 text-slate-700',
  expired: 'bg-red-100 text-red-800',
  permission_required: 'bg-amber-100 text-amber-800',
  error: 'bg-red-100 text-red-800',
  pending: 'bg-slate-200 text-slate-700',
};

function SocialView({ orgId, canConnect, canTest, canDisconnect, setError }: {
  orgId: number; canConnect: boolean; canTest: boolean; canDisconnect: boolean; setError: (s: string) => void;
}) {
  const [platforms, setPlatforms] = useState<SocialPlatform[]>([]);
  const [accounts, setAccounts] = useState<SocialAccount[]>([]);
  const [busy, setBusy] = useState('');

  const load = () => {
    socialPlatforms(orgId).then(setPlatforms).catch(e => setError(e.message));
    socialAccounts(orgId).then(setAccounts).catch(e => setError(e.message));
  };
  useEffect(load, [orgId]);

  const connect = async (platformKey: string, rerequest = false) => {
    try {
      const r = await socialConnect(orgId, platformKey, rerequest);
      window.location.href = r.url; // off to Meta's official OAuth dialog
    } catch (e: any) { setError(e.message); }
  };

  return (
    <div>
      <h2 className="text-2xl font-bold">Connected Accounts</h2>
      <p className="text-sm text-slate-500">Social accounts belong to this workspace only. Tokens are stored encrypted on the server and never shown here.</p>

      <h3 className="mt-6 font-semibold">Platforms</h3>
      <div className="mt-2 grid gap-3 md:grid-cols-2">
        {platforms.map(p => (
          <div key={p.key} className="rounded-xl bg-white p-4 shadow-sm">
            <div className="flex items-center justify-between">
              <b>{p.name}</b>
              {p.is_connectable
                ? <span className="rounded bg-emerald-100 px-2 py-0.5 text-xs text-emerald-800">Available</span>
                : <span className="rounded bg-slate-200 px-2 py-0.5 text-xs text-slate-600">Not yet available</span>}
            </div>
            {p.notes && <p className="mt-1 text-xs text-slate-500">{p.notes}</p>}
            {p.is_connectable && canConnect && (p.key === 'facebook' || p.key === 'instagram') && (
              <button className="mt-3 rounded bg-blue-600 px-3 py-1.5 text-sm text-white" onClick={() => connect('facebook')}>
                Connect with Facebook
              </button>
            )}
          </div>
        ))}
      </div>

      <h3 className="mt-6 font-semibold">Accounts in this workspace</h3>
      {accounts.length === 0 && <p className="mt-2 rounded bg-white p-4 text-sm text-slate-500">No accounts connected yet. Use “Connect with Facebook” above — Pages the person manages are discovered automatically, along with any linked Instagram professional accounts.</p>}
      <div className="mt-2 flex flex-col gap-3">
        {accounts.map(a => (
          <div key={a.id} className="rounded-xl bg-white p-4 shadow-sm">
            <div className="flex flex-wrap items-center gap-3">
              {a.profile_picture_url && <img src={a.profile_picture_url} alt="" className="h-10 w-10 rounded-full" />}
              <div className="min-w-48">
                <div className="font-semibold">{a.display_name} <span className="text-xs font-normal text-slate-400">({a.platform})</span></div>
                <div className="text-xs text-slate-500">{a.username ? `@${a.username} · ` : ''}ID {a.external_id}{a.connected_by ? ` · connected by ${a.connected_by}` : ''}</div>
              </div>
              <span className={`rounded px-2 py-0.5 text-xs ${STATUS_STYLES[a.status] ?? STATUS_STYLES.pending}`}>{a.status.replace('_', ' ')}</span>
              <div className="ml-auto flex gap-2">
                {canTest && a.status !== 'disconnected' && (
                  <button disabled={busy === `test-${a.id}`} className="rounded border px-3 py-1.5 text-sm"
                    onClick={async () => { setBusy(`test-${a.id}`); try { const r = await socialTest(orgId, a.id); setAccounts(prev => prev.map(x => x.id === r.id ? r : x)); } catch (e: any) { setError(e.message); } setBusy(''); }}>
                    Test connection
                  </button>
                )}
                {canConnect && a.platform === 'facebook' && a.status === 'connected' && (
                  <button className="rounded border px-3 py-1.5 text-sm" title="Install the app on this Page so new Meta Lead Ads leads arrive automatically"
                    onClick={async () => { try { const r = await socialSubscribeLeads(orgId, a.id); window.alert(r.message ?? 'Subscribed to Lead Ads.'); } catch (e: any) { setError(e.message); } }}>
                    Subscribe to Lead Ads
                  </button>
                )}
                {canConnect && (a.status === 'expired' || a.status === 'permission_required' || a.status === 'error' || a.status === 'disconnected') && (
                  <button className="rounded bg-blue-600 px-3 py-1.5 text-sm text-white" onClick={() => connect('facebook', a.status === 'permission_required')}>Reconnect</button>
                )}
                {canDisconnect && a.status !== 'disconnected' && (
                  <button className="rounded border border-red-300 px-3 py-1.5 text-sm text-red-700"
                    onClick={async () => { if (!window.confirm(`Disconnect ${a.display_name}? Its stored token will be deleted. Scheduled/published history is kept.`)) return; try { await socialDisconnect(orgId, a.id); load(); } catch (e: any) { setError(e.message); } }}>
                    Disconnect
                  </button>
                )}
              </div>
            </div>
            {a.last_error && <p className="mt-2 text-xs text-red-700">{a.last_error}</p>}
            <div className="mt-2 text-xs text-slate-500">
              Last synced: {a.last_synced_at ? new Date(a.last_synced_at).toLocaleString() : '—'}
              {a.token && <> · Token: {a.token.type}, {a.token.expires_at ? `expires ${new Date(a.token.expires_at).toLocaleDateString()}` : 'no provider expiry'}</>}
            </div>
            {a.permissions.length > 0 && (
              <div className="mt-2 flex flex-wrap gap-1">
                {a.permissions.map(p => (
                  <span key={p.permission} className={`rounded px-1.5 py-0.5 text-[11px] ${p.status === 'granted' ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700 line-through'}`}>{p.permission}</span>
                ))}
              </div>
            )}
          </div>
        ))}
      </div>
    </div>
  );
}

// ---------------- Phase 3: Content & Publishing ----------------

const POST_STATUS_STYLES: Record<string, string> = {
  draft: 'bg-slate-200 text-slate-700',
  pending_approval: 'bg-amber-100 text-amber-800',
  changes_requested: 'bg-orange-100 text-orange-800',
  approved: 'bg-sky-100 text-sky-800',
  rejected: 'bg-red-100 text-red-800',
  scheduled: 'bg-violet-100 text-violet-800',
  publishing: 'bg-blue-100 text-blue-800',
  published: 'bg-emerald-100 text-emerald-800',
  partially_published: 'bg-yellow-100 text-yellow-800',
  failed: 'bg-red-100 text-red-800',
  cancelled: 'bg-slate-200 text-slate-500',
};

function Chip({ status }: { status: string }) {
  return <span className={`rounded px-2 py-0.5 text-xs ${POST_STATUS_STYLES[status] ?? POST_STATUS_STYLES.draft}`}>{status.replaceAll('_', ' ')}</span>;
}

function ContentView({ orgId, can, setError }: { orgId: number; can: (p: string) => boolean; setError: (s: string) => void }) {
  const [tab, setTab] = useState<'posts' | 'calendar' | 'media' | 'templates'>('posts');
  const [posts, setPosts] = useState<ContentPost[]>([]);
  const [editing, setEditing] = useState<ContentPost | null>(null);
  const [composing, setComposing] = useState(false);
  const [templateSeed, setTemplateSeed] = useState<{ caption: string; hashtags: string } | null>(null);

  const load = () => postList(orgId).then(setPosts).catch(e => setError(e.message));
  useEffect(() => { if (tab === 'posts') load(); }, [orgId, tab]);

  return (
    <div>
      <div className="flex items-center justify-between">
        <h2 className="text-2xl font-bold">Content &amp; Publishing</h2>
        {can('content.create') && tab === 'posts' && (
          <button className="rounded bg-emerald-600 px-4 py-2 text-sm text-white" onClick={() => { setEditing(null); setComposing(true); }}>+ New Post</button>
        )}
      </div>
      <div className="mt-3 flex gap-2 text-sm">
        {(['posts', 'calendar', 'media', 'templates'] as const).map(t => (
          <button key={t} onClick={() => setTab(t)} className={`rounded px-3 py-1.5 capitalize ${tab === t ? 'bg-slate-900 text-white' : 'bg-white shadow-sm'}`}>{t}</button>
        ))}
      </div>

      {tab === 'posts' && !composing && !editing && (
        <div className="mt-4 flex flex-col gap-3">
          {posts.length === 0 && <p className="rounded bg-white p-4 text-sm text-slate-500">No posts yet in this workspace.</p>}
          {posts.map(p => (
            <PostCard key={p.id} post={p} orgId={orgId} can={can} setError={setError} reload={load}
              onEdit={async () => { try { setEditing(await postGet(orgId, p.id)); } catch (e: any) { setError(e.message); } }} />
          ))}
        </div>
      )}
      {tab === 'posts' && (composing || editing) && (
        <Composer orgId={orgId} initial={editing} templateSeed={templateSeed} setError={setError}
          onDone={() => { setComposing(false); setEditing(null); setTemplateSeed(null); load(); }} />
      )}
      {tab === 'calendar' && <CalendarTab orgId={orgId} setError={setError} />}
      {tab === 'media' && <MediaTab orgId={orgId} can={can} setError={setError} />}
      {tab === 'templates' && <TemplatesTab orgId={orgId} can={can} setError={setError}
        onApply={t => { setTemplateSeed({ caption: t.caption ?? '', hashtags: t.hashtags ?? '' }); setTab('posts'); setEditing(null); setComposing(true); }} />}
    </div>
  );
}

function PostCard({ post, orgId, can, setError, reload, onEdit }: {
  post: ContentPost; orgId: number; can: (p: string) => boolean; setError: (s: string) => void; reload: () => void; onEdit: () => void;
}) {
  const act = async (fn: () => Promise<unknown>, confirmMsg?: string) => {
    if (confirmMsg && !window.confirm(confirmMsg)) return;
    try { await fn(); reload(); } catch (e: any) { setError(e.message); }
  };
  const editable = ['draft', 'changes_requested', 'rejected'].includes(post.status);
  return (
    <div className="rounded-xl bg-white p-4 shadow-sm">
      <div className="flex flex-wrap items-center gap-2">
        <b>{post.title || '(untitled)'}</b>
        <Chip status={post.status} />
        {post.scheduled_at && post.status === 'scheduled' && <span className="text-xs text-slate-500">🕒 {new Date(post.scheduled_at).toLocaleString()}</span>}
        {post.published_at && <span className="text-xs text-slate-500">Published {new Date(post.published_at).toLocaleString()}</span>}
        <span className="ml-auto flex flex-wrap gap-1.5 text-xs">
          {editable && can('content.edit') && <button className="rounded border px-2 py-1" onClick={onEdit}>Edit</button>}
          {editable && can('content.submit') && <button className="rounded border px-2 py-1" onClick={() => act(() => postAction(orgId, post.id, 'submit'))}>Submit for approval</button>}
          {post.status === 'pending_approval' && can('content.approve') && <>
            <button className="rounded bg-emerald-600 px-2 py-1 text-white" onClick={() => act(() => postAction(orgId, post.id, 'approve', {}))}>Approve</button>
            <button className="rounded border border-orange-300 px-2 py-1 text-orange-700" onClick={() => { const c = window.prompt('What needs to change? (required)'); if (c) act(() => postAction(orgId, post.id, 'request-changes', { comment: c })); }}>Request changes</button>
            <button className="rounded border border-red-300 px-2 py-1 text-red-700" onClick={() => { const c = window.prompt('Rejection reason (required)'); if (c) act(() => postAction(orgId, post.id, 'reject', { comment: c })); }}>Reject</button>
          </>}
          {['draft', 'approved', 'changes_requested'].includes(post.status) && can('content.publish') &&
            <button className="rounded bg-blue-600 px-2 py-1 text-white" onClick={() => act(() => postAction(orgId, post.id, 'publish'), 'Publish this post to all selected destinations now?')}>Publish now</button>}
          {['draft', 'approved'].includes(post.status) && can('content.schedule') &&
            <button className="rounded border px-2 py-1" onClick={() => { const when = window.prompt('Schedule for (YYYY-MM-DD HH:mm, your local time)'); if (when) act(() => postAction(orgId, post.id, 'schedule', { scheduled_at: new Date(when.replace(' ', 'T')).toISOString() })); }}>Schedule…</button>}
          {post.status === 'scheduled' && can('content.schedule') &&
            <button className="rounded border border-red-300 px-2 py-1 text-red-700" onClick={() => act(() => postAction(orgId, post.id, 'cancel-schedule'), 'Cancel this scheduled post?')}>Cancel schedule</button>}
          {can('content.create') && <button className="rounded border px-2 py-1" onClick={() => act(() => postDuplicate(orgId, post.id))}>Duplicate</button>}
          {editable && can('content.delete') && <button className="rounded border border-red-300 px-2 py-1 text-red-700" onClick={() => act(() => postDelete(orgId, post.id), 'Delete this post?')}>Delete</button>}
        </span>
      </div>
      {post.caption && <p className="mt-1 whitespace-pre-wrap text-sm text-slate-600">{post.caption.slice(0, 220)}{post.caption.length > 220 ? '…' : ''}</p>}
      <div className="mt-2 flex flex-col gap-1">
        {post.targets.map(t => (
          <div key={t.id} className="flex flex-wrap items-center gap-2 text-xs text-slate-600">
            <span className="font-medium">{t.account_name ?? t.platform}</span>
            <Chip status={t.status} />
            {t.platform_url && <a className="text-blue-600 underline" href={t.platform_url} target="_blank" rel="noreferrer">View post</a>}
            {t.error_message && <span className="text-red-600">{t.error_message}</span>}
            {t.status === 'failed' && can('content.publish') &&
              <button className="rounded border px-2 py-0.5" onClick={() => act(() => postRetryTarget(orgId, post.id, t.id))}>Retry</button>}
          </div>
        ))}
        {post.targets.length === 0 && <span className="text-xs text-slate-400">No destinations selected</span>}
      </div>
    </div>
  );
}

function Composer({ orgId, initial, templateSeed, setError, onDone }: {
  orgId: number; initial: ContentPost | null; templateSeed: { caption: string; hashtags: string } | null;
  setError: (s: string) => void; onDone: () => void;
}) {
  const [form, setForm] = useState({
    title: initial?.title ?? '', caption: initial?.caption ?? templateSeed?.caption ?? '',
    hashtags: initial?.hashtags ?? templateSeed?.hashtags ?? '', link_url: initial?.link_url ?? '',
    cta_text: initial?.cta_text ?? '', first_comment: initial?.first_comment ?? '',
    internal_notes: initial?.internal_notes ?? '', approval_required: initial?.approval_required ?? false,
  });
  const [media, setMedia] = useState<MediaAsset[]>([]);
  const [selectedMedia, setSelectedMedia] = useState<number[]>(initial?.media.map(m => m.id) ?? []);
  const [destinations, setDestinations] = useState<Destination[]>([]);
  const [selectedTargets, setSelectedTargets] = useState<number[]>(initial?.targets.map(t => t.social_account_id) ?? []);
  const [overrides, setOverrides] = useState<Record<number, string>>({});
  const [previews, setPreviews] = useState<any[] | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    mediaList(orgId).then(setMedia).catch(e => setError(e.message));
    postDestinations(orgId).then(setDestinations).catch(e => setError(e.message));
    if (initial) {
      const ov: Record<number, string> = {};
      initial.targets.forEach(t => { if (t.caption_override) ov[t.social_account_id] = t.caption_override; });
      setOverrides(ov);
    }
  }, [orgId]);

  const payload = () => ({
    ...form, link_url: form.link_url || null,
    media_ids: selectedMedia,
    targets: selectedTargets.map(id => ({ social_account_id: id, caption_override: overrides[id] || null })),
  });

  const save = async () => {
    setSaving(true);
    try {
      const saved = initial ? await postUpdate(orgId, initial.id, payload()) : await postCreate(orgId, payload());
      const pv = await postPreviews(orgId, saved.id);
      setPreviews(pv);
      if (!initial) { /* stay open to show previews; user closes manually */ }
    } catch (e: any) { setError(e.message); }
    setSaving(false);
  };

  const set = (k: string, v: unknown) => setForm(f => ({ ...f, [k]: v }));
  const input = 'w-full rounded border px-2 py-1.5 text-sm';

  return (
    <div className="mt-4 rounded-xl bg-white p-4 shadow-sm">
      <h3 className="font-semibold">{initial ? 'Edit post' : 'New post'}</h3>
      <div className="mt-3 grid gap-3 md:grid-cols-2">
        <label className="text-sm md:col-span-2">Internal title<input className={input} value={form.title} onChange={e => set('title', e.target.value)} placeholder="e.g. DHA Phase 6 listing — launch" /></label>
        <label className="text-sm md:col-span-2">Caption<textarea className={input} rows={5} value={form.caption} onChange={e => set('caption', e.target.value)} /></label>
        <AiAssistantPanel orgId={orgId} setError={setError} onUse={(caption, hashtags, cta) => {
          set('caption', caption);
          if (hashtags) set('hashtags', hashtags);
          if (cta) set('cta_text', cta);
        }} />
        <label className="text-sm">Hashtags<input className={input} value={form.hashtags} onChange={e => set('hashtags', e.target.value)} placeholder="#RealEstate #Lahore" /></label>
        <label className="text-sm">Website / property URL<input className={input} value={form.link_url} onChange={e => set('link_url', e.target.value)} /></label>
        <label className="text-sm">Call to action<input className={input} value={form.cta_text} onChange={e => set('cta_text', e.target.value)} placeholder="Call now / Book a viewing" /></label>
        <label className="text-sm">First comment (Facebook)<input className={input} value={form.first_comment} onChange={e => set('first_comment', e.target.value)} /></label>
        <label className="text-sm md:col-span-2">Internal notes<textarea className={input} rows={2} value={form.internal_notes} onChange={e => set('internal_notes', e.target.value)} /></label>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.approval_required} onChange={e => set('approval_required', e.target.checked)} /> Requires client approval before publishing</label>
      </div>

      <h4 className="mt-4 font-medium">Media</h4>
      {media.length === 0 && <p className="text-xs text-slate-500">No media in the library yet — upload in the Media tab first.</p>}
      <div className="mt-2 flex flex-wrap gap-2">
        {media.map(m => (
          <label key={m.id} className={`cursor-pointer rounded border p-1 ${selectedMedia.includes(m.id) ? 'border-emerald-500 bg-emerald-50' : ''}`}>
            {m.public_url && m.kind === 'image'
              ? <img src={m.public_url} alt={m.original_name} className="h-16 w-16 object-cover" />
              : <div className="flex h-16 w-16 items-center justify-center bg-slate-100 text-xs">🎬 video</div>}
            <div className="mt-1 flex items-center gap-1 text-[11px]"><input type="checkbox" checked={selectedMedia.includes(m.id)}
              onChange={e => setSelectedMedia(s => e.target.checked ? [...s, m.id] : s.filter(x => x !== m.id))} />{m.original_name.slice(0, 14)}</div>
          </label>
        ))}
      </div>

      <h4 className="mt-4 font-medium">Destinations</h4>
      <div className="mt-2 flex flex-col gap-2">
        {destinations.map(d => (
          <div key={d.account.id} className="rounded border p-2">
            <label className={`flex items-center gap-2 text-sm ${!d.eligible ? 'opacity-60' : ''}`}>
              <input type="checkbox" disabled={!d.eligible} checked={selectedTargets.includes(d.account.id)}
                onChange={e => setSelectedTargets(s => e.target.checked ? [...s, d.account.id] : s.filter(x => x !== d.account.id))} />
              <b>{d.account.display_name}</b><span className="text-xs text-slate-500">{d.account.platform}{d.account.username ? ` · @${d.account.username}` : ''}</span>
              {!d.eligible && <span className="text-xs text-red-600">Not eligible: {d.reasons.join('; ')}</span>}
            </label>
            {selectedTargets.includes(d.account.id) && (
              <input className={`${input} mt-2`} placeholder={`Caption override for this ${d.account.platform} account (optional)`}
                value={overrides[d.account.id] ?? ''} onChange={e => setOverrides(o => ({ ...o, [d.account.id]: e.target.value }))} />
            )}
          </div>
        ))}
        {destinations.length === 0 && <p className="text-xs text-slate-500">No connected accounts in this workspace yet.</p>}
      </div>

      {previews && (
        <div className="mt-4 rounded bg-slate-50 p-3">
          <h4 className="font-medium">Destination previews</h4>
          {previews.map(pv => (
            <div key={pv.target_id} className="mt-2 rounded bg-white p-3 text-sm shadow-sm">
              <b>{pv.account_name}</b> <span className="text-xs text-slate-400">({pv.platform})</span>
              <p className="mt-1 whitespace-pre-wrap">{pv.text || <i className="text-slate-400">(no text)</i>}</p>
              {pv.link_url && <p className="text-xs text-blue-600">{pv.link_url}</p>}
              <p className="text-xs text-slate-500">{pv.media.length} media item(s)</p>
              {pv.errors.map((err: string) => <p key={err} className="text-xs text-red-600">⚠ {err}</p>)}
            </div>
          ))}
        </div>
      )}

      <div className="mt-4 flex gap-2">
        <button disabled={saving} className="rounded bg-slate-900 px-4 py-2 text-sm text-white" onClick={save}>{initial ? 'Save changes & preview' : 'Save draft & preview'}</button>
        <button className="rounded border px-4 py-2 text-sm" onClick={onDone}>Done</button>
      </div>
    </div>
  );
}

function CalendarTab({ orgId, setError }: { orgId: number; setError: (s: string) => void }) {
  const [cursor, setCursor] = useState(() => new Date());
  const [posts, setPosts] = useState<ContentPost[]>([]);
  const year = cursor.getFullYear(), month = cursor.getMonth();
  const first = new Date(year, month, 1), last = new Date(year, month + 1, 0);
  useEffect(() => {
    postCalendar(orgId, first.toISOString(), last.toISOString()).then(setPosts).catch(e => setError(e.message));
  }, [orgId, year, month]);
  const byDay = new Map<number, ContentPost[]>();
  posts.forEach(p => {
    const d = new Date((p.status === 'scheduled' ? p.scheduled_at : p.published_at ?? p.scheduled_at) as string);
    if (d.getMonth() === month) byDay.set(d.getDate(), [...(byDay.get(d.getDate()) ?? []), p]);
  });
  const cells: (number | null)[] = [...Array(first.getDay()).fill(null), ...Array.from({ length: last.getDate() }, (_, i) => i + 1)];
  return (
    <div className="mt-4">
      <div className="flex items-center gap-3">
        <button className="rounded border bg-white px-2 py-1" onClick={() => setCursor(new Date(year, month - 1, 1))}>←</button>
        <b>{cursor.toLocaleString(undefined, { month: 'long', year: 'numeric' })}</b>
        <button className="rounded border bg-white px-2 py-1" onClick={() => setCursor(new Date(year, month + 1, 1))}>→</button>
        <span className="text-xs text-slate-500">Times shown in your browser timezone; stored in UTC.</span>
      </div>
      <div className="mt-3 grid grid-cols-7 gap-1">
        {['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map(d => <div key={d} className="p-1 text-center text-xs font-semibold text-slate-500">{d}</div>)}
        {cells.map((day, i) => (
          <div key={i} className="min-h-20 rounded bg-white p-1 shadow-sm">
            {day && <><div className="text-xs text-slate-400">{day}</div>
              {(byDay.get(day) ?? []).map(p => (
                <div key={p.id} className="mt-1 rounded bg-slate-50 p-1 text-[11px]">
                  <Chip status={p.status} /> <span>{p.title || p.caption?.slice(0, 24) || 'Post'}</span>
                  <div className="text-slate-400">{p.targets.map(t => t.platform).join(', ')}</div>
                </div>
              ))}</>}
          </div>
        ))}
      </div>
    </div>
  );
}

function MediaTab({ orgId, can, setError }: { orgId: number; can: (p: string) => boolean; setError: (s: string) => void }) {
  const [assets, setAssets] = useState<MediaAsset[]>([]);
  const [busy, setBusy] = useState(false);
  const load = () => mediaList(orgId).then(setAssets).catch(e => setError(e.message));
  useEffect(() => { load(); }, [orgId]);
  return (
    <div className="mt-4">
      {can('media.upload') && (
        <label className="inline-block cursor-pointer rounded bg-emerald-600 px-4 py-2 text-sm text-white">
          {busy ? 'Uploading…' : 'Upload image or video'}
          <input type="file" accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime" className="hidden"
            onChange={async e => { const f = e.target.files?.[0]; if (!f) return; setBusy(true); try { await mediaUpload(orgId, f); load(); } catch (err: any) { setError(err.message); } setBusy(false); e.target.value = ''; }} />
        </label>
      )}
      <p className="mt-1 text-xs text-slate-500">JPEG/PNG/WebP up to 10 MB, MP4/MOV up to 100 MB. Publishing requires the file to be reachable at a public URL — on a production domain/S3 this works automatically; a localhost URL cannot be fetched by Meta.</p>
      <div className="mt-3 grid grid-cols-2 gap-3 md:grid-cols-4">
        {assets.map(a => (
          <div key={a.id} className="rounded-xl bg-white p-2 shadow-sm">
            {a.kind === 'image' && a.public_url
              ? <img src={a.public_url} alt={a.original_name} className="h-32 w-full rounded object-cover" />
              : <div className="flex h-32 w-full items-center justify-center rounded bg-slate-100 text-2xl">🎬</div>}
            <div className="mt-1 truncate text-xs">{a.original_name}</div>
            <div className="text-[11px] text-slate-400">{a.kind} · {(a.size_bytes / 1024).toFixed(0)} KB{a.width ? ` · ${a.width}×${a.height}` : ''}</div>
            {can('media.delete') && <button className="mt-1 text-xs text-red-600" onClick={async () => { if (window.confirm('Delete this media file?')) { try { await mediaDelete(orgId, a.id); load(); } catch (e: any) { setError(e.message); } } }}>Delete</button>}
          </div>
        ))}
        {assets.length === 0 && <p className="text-sm text-slate-500">Library is empty.</p>}
      </div>
    </div>
  );
}

function TemplatesTab({ orgId, can, setError, onApply }: {
  orgId: number; can: (p: string) => boolean; setError: (s: string) => void; onApply: (t: ContentTemplate) => void;
}) {
  const [templates, setTemplates] = useState<ContentTemplate[]>([]);
  const [form, setForm] = useState({ name: '', category: '', caption: '', hashtags: '' });
  const load = () => templateList(orgId).then(setTemplates).catch(e => setError(e.message));
  useEffect(() => { load(); }, [orgId]);
  const input = 'w-full rounded border px-2 py-1.5 text-sm';
  return (
    <div className="mt-4">
      {can('content.create') && templates.length === 0 && (
        <button className="rounded bg-slate-900 px-3 py-2 text-sm text-white" onClick={async () => { try { await templateSeed(orgId); load(); } catch (e: any) { setError(e.message); } }}>
          Load starter templates (16 real-estate templates)
        </button>
      )}
      <div className="mt-3 grid gap-3 md:grid-cols-3">
        {templates.map(t => (
          <div key={t.id} className="rounded-xl bg-white p-3 shadow-sm">
            <div className="flex items-center justify-between"><b className="text-sm">{t.name}</b>{t.is_system && <span className="rounded bg-slate-100 px-1.5 text-[10px] text-slate-500">starter</span>}</div>
            <div className="text-xs text-slate-400">{t.category}</div>
            <p className="mt-1 line-clamp-3 whitespace-pre-wrap text-xs text-slate-600">{t.caption}</p>
            <div className="mt-2 flex gap-2 text-xs">
              {can('content.create') && <button className="rounded bg-blue-600 px-2 py-1 text-white" onClick={() => onApply(t)}>Use in new post</button>}
              {can('content.delete') && <button className="rounded border border-red-300 px-2 py-1 text-red-700" onClick={async () => { if (window.confirm('Delete template?')) { try { await templateDelete(orgId, t.id); load(); } catch (e: any) { setError(e.message); } } }}>Delete</button>}
            </div>
          </div>
        ))}
      </div>
      {can('content.create') && (
        <div className="mt-4 rounded-xl bg-white p-4 shadow-sm">
          <h4 className="font-medium">New template</h4>
          <div className="mt-2 grid gap-2 md:grid-cols-2">
            <input className={input} placeholder="Name" value={form.name} onChange={e => setForm({ ...form, name: e.target.value })} />
            <input className={input} placeholder="Category" value={form.category} onChange={e => setForm({ ...form, category: e.target.value })} />
            <textarea className={`${input} md:col-span-2`} rows={3} placeholder="Caption — use {placeholders} for details you fill per post" value={form.caption} onChange={e => setForm({ ...form, caption: e.target.value })} />
            <input className={`${input} md:col-span-2`} placeholder="Hashtags" value={form.hashtags} onChange={e => setForm({ ...form, hashtags: e.target.value })} />
          </div>
          <button className="mt-2 rounded bg-emerald-600 px-3 py-1.5 text-sm text-white" onClick={async () => { try { await templateCreate(orgId, form); setForm({ name: '', category: '', caption: '', hashtags: '' }); load(); } catch (e: any) { setError(e.message); } }}>Save template</button>
        </div>
      )}
    </div>
  );
}

// ---------------- Phase 4: Lead Generation ----------------

function LeadsView({ orgId, can, setError }: { orgId: number; can: (p: string) => boolean; setError: (s: string) => void }) {
  const [tab, setTab] = useState<'leads' | 'pipeline' | 'my' | 'tasks' | 'import' | 'forms' | 'followups' | 'setup'>('leads');
  return (
    <div>
      <h2 className="text-2xl font-bold">Leads</h2>
      <div className="mt-3 flex flex-wrap gap-2 text-sm">
        {([['leads', 'All leads'], ['pipeline', 'Pipeline'], ['my', 'My dashboard'], ['tasks', 'Tasks'], ['import', 'Import'], ['forms', 'Website forms'], ['followups', 'Follow-ups'], ['setup', 'Setup']] as const).map(([t, label]) => (
          <button key={t} onClick={() => setTab(t)} className={`rounded px-3 py-1.5 ${tab === t ? 'bg-slate-900 text-white' : 'bg-white shadow-sm'}`}>{label}</button>
        ))}
      </div>
      {tab === 'leads' && <LeadsListTab orgId={orgId} can={can} setError={setError} />}
      {tab === 'pipeline' && <PipelineTab orgId={orgId} can={can} setError={setError} />}
      {tab === 'tasks' && <TasksTab orgId={orgId} can={can} setError={setError} />}
      {tab === 'my' && <MyLeadsTab orgId={orgId} setError={setError} />}
      {tab === 'import' && <LeadImportTab orgId={orgId} can={can} setError={setError} />}
      {tab === 'forms' && <LeadFormsTab orgId={orgId} can={can} setError={setError} />}
      {tab === 'followups' && <FollowUpsTab orgId={orgId} can={can} setError={setError} />}
      {tab === 'setup' && <LeadSetupTab orgId={orgId} can={can} setError={setError} />}
    </div>
  );
}

function LeadsListTab({ orgId, can, setError }: { orgId: number; can: (p: string) => boolean; setError: (s: string) => void }) {
  const [leads, setLeads] = useState<Lead[]>([]);
  const [stages, setStages] = useState<LeadStage[]>([]);
  const [sources, setSources] = useState<LeadSourceT[]>([]);
  const [search, setSearch] = useState('');
  const [stageFilter, setStageFilter] = useState('');
  const [onlyDup, setOnlyDup] = useState(false);
  const [detail, setDetail] = useState<Lead | null>(null);
  const [form, setForm] = useState({ full_name: '', phone: '', email: '', city: '', intent: '', property_type: '', budget_max: '' });
  const [outcome, setOutcome] = useState('');
  const [filters, setFilters] = useState<SavedFilter[]>([]);
  const loadFilters = () => savedFilters(orgId).then(setFilters).catch(() => {});
  useEffect(() => { loadFilters(); }, [orgId]);
  const applyFilter = (f: SavedFilter) => {
    setSearch(f.criteria.search ?? '');
    setStageFilter(f.criteria.stage_id ? String(f.criteria.stage_id) : '');
    setOnlyDup(!!f.criteria.possible_duplicates);
    setTimeout(load, 0);
  };

  const load = () => {
    const params = new URLSearchParams();
    if (search) params.set('search', search);
    if (stageFilter) params.set('stage_id', stageFilter);
    if (onlyDup) params.set('possible_duplicates', '1');
    leadList(orgId, `?${params.toString()}`).then(r => setLeads(r.data)).catch(e => setError(e.message));
  };
  useEffect(() => { load(); }, [orgId, stageFilter, onlyDup]);
  useEffect(() => { leadStages(orgId).then(setStages).catch(() => {}); leadSources(orgId).then(setSources).catch(() => {}); }, [orgId]);

  const input = 'rounded border px-2 py-1.5 text-sm';
  return (
    <div className="mt-4">
      <div className="flex flex-wrap items-center gap-2">
        <input className={input} placeholder="Search name, phone, email, city…" value={search} onChange={e => setSearch(e.target.value)} onKeyDown={e => e.key === 'Enter' && load()} />
        <select className={input} value={stageFilter} onChange={e => setStageFilter(e.target.value)}>
          <option value="">All stages</option>
          {stages.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
        </select>
        <label className="flex items-center gap-1 text-sm"><input type="checkbox" checked={onlyDup} onChange={e => setOnlyDup(e.target.checked)} /> Possible duplicates only</label>
        <button className="rounded border bg-white px-3 py-1.5 text-sm" onClick={load}>Search</button>
        <button className="rounded border bg-white px-3 py-1.5 text-sm" onClick={async () => {
          const name = window.prompt('Name this filter view:'); if (!name) return;
          const shared = window.confirm('Share this view with the whole workspace? (OK = shared, Cancel = private)');
          try { await savedFilterCreate(orgId, { name, is_shared: shared, criteria: { search, stage_id: stageFilter ? Number(stageFilter) : null, possible_duplicates: onlyDup } }); loadFilters(); } catch (e: any) { setError(e.message); }
        }}>Save view</button>
      </div>
      {filters.length > 0 && (
        <div className="mt-2 flex flex-wrap gap-1.5">
          {filters.map(f => (
            <span key={f.id} className="flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs">
              <button className="font-medium" onClick={() => applyFilter(f)}>{f.name}</button>
              {f.is_shared && <span className="text-slate-400">shared</span>}
              <button className="text-slate-400 hover:text-red-600" title="Delete view" onClick={async () => { try { await savedFilterDelete(orgId, f.id); loadFilters(); } catch (e: any) { setError(e.message); } }}>✕</button>
            </span>
          ))}
        </div>
      )}

      {can('leads.create') && (
        <form className="mt-3 flex flex-wrap items-end gap-2 rounded-xl bg-white p-3 shadow-sm" onSubmit={async e => {
          e.preventDefault();
          try {
            const r = await leadCreate(orgId, { ...form, budget_max: form.budget_max ? Number(form.budget_max) : null, source_key: 'manual' });
            setOutcome(r.outcome === 'created' ? 'Lead created.' : r.outcome === 'touchpoint' ? 'Matched an existing lead — saved as a touchpoint instead of a duplicate.' : 'Created but flagged as a possible duplicate for review.');
            setForm({ full_name: '', phone: '', email: '', city: '', intent: '', property_type: '', budget_max: '' });
            load();
          } catch (err: any) { setError(err.message); }
        }}>
          <label className="text-xs">Full name<input required className={`${input} block`} value={form.full_name} onChange={e => setForm({ ...form, full_name: e.target.value })} /></label>
          <label className="text-xs">Phone<input className={`${input} block`} placeholder="03XX-XXXXXXX" value={form.phone} onChange={e => setForm({ ...form, phone: e.target.value })} /></label>
          <label className="text-xs">Email<input className={`${input} block`} value={form.email} onChange={e => setForm({ ...form, email: e.target.value })} /></label>
          <label className="text-xs">City<input className={`${input} block`} value={form.city} onChange={e => setForm({ ...form, city: e.target.value })} /></label>
          <label className="text-xs">Intent<select className={`${input} block`} value={form.intent} onChange={e => setForm({ ...form, intent: e.target.value })}><option value="">—</option><option value="buy">Buy</option><option value="sell">Sell</option><option value="rent">Rent</option><option value="invest">Invest</option></select></label>
          <label className="text-xs">Max budget (PKR)<input className={`${input} block`} type="number" value={form.budget_max} onChange={e => setForm({ ...form, budget_max: e.target.value })} /></label>
          <button className="rounded bg-emerald-600 px-3 py-1.5 text-sm text-white">Add lead</button>
          {outcome && <span className="text-xs text-emerald-700">{outcome}</span>}
        </form>
      )}

      <div className="mt-3 overflow-x-auto rounded-xl bg-white shadow-sm">
        <table className="w-full text-left text-sm">
          <thead><tr className="border-b text-xs text-slate-500">
            <th className="p-2">Name</th><th className="p-2">Contact</th><th className="p-2">City / Interest</th><th className="p-2">Source</th><th className="p-2">Stage</th><th className="p-2">Assigned</th><th className="p-2">Received</th><th className="p-2"></th>
          </tr></thead>
          <tbody>
            {leads.map(l => (
              <tr key={l.id} className="border-b last:border-0 hover:bg-slate-50">
                <td className="p-2 font-medium">{l.full_name}{l.possible_duplicate_of && <span className="ml-1 rounded bg-orange-100 px-1 text-[10px] text-orange-800">possible duplicate</span>}</td>
                <td className="p-2 text-xs">{l.phone_e164 ?? '—'}{l.email ? <div>{l.email}</div> : null}</td>
                <td className="p-2 text-xs">{l.city ?? ''}{l.preferred_location ? ` · ${l.preferred_location}` : ''}{l.property_reference ? <div>{l.property_reference}</div> : null}</td>
                <td className="p-2 text-xs">{l.source?.name ?? '—'}{l.campaign_name ? <div className="text-slate-400">{l.campaign_name}</div> : null}</td>
                <td className="p-2"><span className="rounded bg-slate-100 px-2 py-0.5 text-xs">{l.stage?.name ?? '—'}</span></td>
                <td className="p-2 text-xs">{l.assignee?.name ?? <span className="text-slate-400">Unassigned</span>}</td>
                <td className="p-2 text-xs text-slate-500">{new Date(l.created_at).toLocaleDateString()}</td>
                <td className="p-2"><button className="text-xs text-blue-600 underline" onClick={async () => { try { setDetail(await leadGet(orgId, l.id)); } catch (e: any) { setError(e.message); } }}>Open</button></td>
              </tr>
            ))}
            {leads.length === 0 && <tr><td colSpan={8} className="p-4 text-center text-slate-400">No leads found.</td></tr>}
          </tbody>
        </table>
      </div>
      <p className="mt-1 text-xs text-slate-400">Sources in this workspace: {sources.map(s => s.name).join(' · ') || '—'}</p>
      {detail && <LeadDetail orgId={orgId} lead={detail} stages={stages} can={can} setError={setError} onClose={() => { setDetail(null); load(); }} onChange={setDetail} />}
    </div>
  );
}

function LeadDetail({ orgId, lead, stages, can, setError, onClose, onChange }: {
  orgId: number; lead: Lead; stages: LeadStage[]; can: (p: string) => boolean; setError: (s: string) => void;
  onClose: () => void; onChange: (l: Lead) => void;
}) {
  const [note, setNote] = useState('');
  const [fuDate, setFuDate] = useState('');
  const refresh = async () => { try { onChange(await leadGet(orgId, lead.id)); } catch (e: any) { setError(e.message); } };
  return (
    <div className="fixed inset-0 z-20 flex items-start justify-center overflow-y-auto bg-black/40 p-4" onClick={onClose}>
      <div className="w-full max-w-2xl rounded-xl bg-white p-5 shadow-xl" onClick={e => e.stopPropagation()}>
        <div className="flex items-start justify-between">
          <div>
            <h3 className="text-lg font-bold">{lead.full_name}</h3>
            <div className="text-sm text-slate-500">{lead.phone_e164 ?? ''} {lead.whatsapp_e164 ? `· WhatsApp ${lead.whatsapp_e164}` : ''} {lead.email ? `· ${lead.email}` : ''}</div>
            <div className="text-xs text-slate-400">Source: {lead.source?.name ?? '—'}{lead.ad_name ? ` · Ad: ${lead.ad_name}` : ''}{lead.utm_campaign ? ` · UTM campaign: ${lead.utm_campaign}` : ''}</div>
            {lead.possible_duplicate_of_lead && <div className="mt-1 rounded bg-orange-50 p-2 text-xs text-orange-800">⚠ Possible duplicate of {lead.possible_duplicate_of_lead.full_name} ({lead.possible_duplicate_of_lead.phone_e164}) — same phone, different name. Review before merging; nothing was auto-merged.</div>}
          </div>
          <button className="rounded border px-2 py-1 text-sm" onClick={onClose}>Close</button>
        </div>

        <div className="mt-3 grid gap-2 text-sm md:grid-cols-3">
          <div><span className="text-slate-400">City:</span> {lead.city ?? '—'}</div>
          <div><span className="text-slate-400">Area:</span> {lead.area ?? lead.preferred_location ?? '—'}</div>
          <div><span className="text-slate-400">Intent:</span> {lead.intent ?? '—'}</div>
          <div><span className="text-slate-400">Property:</span> {lead.property_type ?? '—'} {lead.property_reference ?? ''}</div>
          <div><span className="text-slate-400">Budget:</span> {lead.budget_min ?? ''}{lead.budget_max ? ` – ${lead.budget_max}` : ''} PKR</div>
          <div><span className="text-slate-400">Priority:</span> {lead.priority}</div>
        </div>
        {lead.custom_fields && Object.keys(lead.custom_fields).length > 0 && (
          <div className="mt-2 rounded bg-slate-50 p-2 text-xs">
            <b>Form answers:</b> {Object.entries(lead.custom_fields).map(([k, v]) => <div key={k}>{k}: {String(v)}</div>)}
          </div>
        )}
        {lead.notes && <p className="mt-2 whitespace-pre-wrap rounded bg-slate-50 p-2 text-sm">{lead.notes}</p>}

        {can('leads.edit') && (
          <div className="mt-3 flex flex-wrap items-center gap-2 text-sm">
            <label>Stage:
              <select className="ml-1 rounded border px-2 py-1" value={lead.lead_pipeline_stage_id ?? ''} onChange={async e => { try { await leadUpdate(orgId, lead.id, { lead_pipeline_stage_id: Number(e.target.value) }); refresh(); } catch (err: any) { setError(err.message); } }}>
                {stages.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
              </select>
            </label>
            {can('leads.delete') && <button className="rounded border border-red-300 px-2 py-1 text-red-700" onClick={async () => { if (window.confirm('Delete this lead?')) { try { await leadDelete(orgId, lead.id); onClose(); } catch (e: any) { setError(e.message); } } }}>Delete lead</button>}
          </div>
        )}

        {can('leads.edit') && (
          <div className="mt-3 flex gap-2">
            <input className="flex-1 rounded border px-2 py-1.5 text-sm" placeholder="Add a note / call outcome…" value={note} onChange={e => setNote(e.target.value)} />
            <button className="rounded bg-slate-900 px-3 py-1.5 text-sm text-white" onClick={async () => { if (!note) return; try { await leadAddNote(orgId, lead.id, note, true); setNote(''); refresh(); } catch (e: any) { setError(e.message); } }}>Add note</button>
          </div>
        )}
        {can('leads.followups') && (
          <div className="mt-2 flex gap-2">
            <input type="datetime-local" className="rounded border px-2 py-1.5 text-sm" value={fuDate} onChange={e => setFuDate(e.target.value)} />
            <button className="rounded border px-3 py-1.5 text-sm" onClick={async () => { if (!fuDate) return; try { await followUpCreate(orgId, { lead_id: lead.id, due_at: new Date(fuDate).toISOString() }); setFuDate(''); refresh(); } catch (e: any) { setError(e.message); } }}>Schedule follow-up</button>
          </div>
        )}

        {can('whatsapp.view') && <LeadWhatsAppPanel orgId={orgId} lead={lead} can={can} setError={setError} />}

        <div className="mt-4 grid gap-4 md:grid-cols-2">
          <div>
            <h4 className="text-sm font-semibold">Touchpoints ({lead.touchpoints?.length ?? 0})</h4>
            <div className="mt-1 flex flex-col gap-1 text-xs text-slate-600">
              {(lead.touchpoints ?? []).map((t: any) => <div key={t.id} className="rounded bg-slate-50 p-1.5">{t.source?.name ?? 'Source'} · {new Date(t.created_at).toLocaleString()}{t.campaign_name ? ` · ${t.campaign_name}` : ''}</div>)}
            </div>
          </div>
          <div>
            <h4 className="text-sm font-semibold">Activity</h4>
            <div className="mt-1 flex max-h-56 flex-col gap-1 overflow-y-auto text-xs text-slate-600">
              {(lead.activities ?? []).slice().reverse().map((a: any) => <div key={a.id} className="rounded bg-slate-50 p-1.5"><b>{a.type.replaceAll('_', ' ')}</b> · {new Date(a.created_at).toLocaleString()}<div>{a.description}</div></div>)}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

function MyLeadsTab({ orgId, setError }: { orgId: number; setError: (s: string) => void }) {
  const [summary, setSummary] = useState<Record<string, number> | null>(null);
  useEffect(() => { leadMySummary(orgId).then(setSummary).catch(e => setError(e.message)); }, [orgId]);
  if (!summary) return <p className="mt-4 text-sm text-slate-500">Loading…</p>;
  const cards: [string, number][] = [
    ['My leads', summary.my_total], ['New', summary.my_new], ['Contacted', summary.my_contacted],
    ['Qualified / Interested', summary.my_qualified], ['Viewings scheduled', summary.my_viewings],
    ['Converted', summary.my_converted], ['Lost', summary.my_lost],
    ['Follow-ups due today', summary.followups_due_today], ['Follow-ups overdue', summary.followups_overdue],
    ['Unassigned (all)', summary.unassigned_total],
  ];
  return (
    <div className="mt-4 grid grid-cols-2 gap-3 md:grid-cols-5">
      {cards.map(([label, value]) => (
        <div key={label} className="rounded-xl bg-white p-4 shadow-sm">
          <div className="text-2xl font-bold">{value}</div>
          <div className="text-xs text-slate-500">{label}</div>
        </div>
      ))}
    </div>
  );
}

function LeadImportTab({ orgId, can, setError }: { orgId: number; can: (p: string) => boolean; setError: (s: string) => void }) {
  const [report, setReport] = useState<any>(null);
  const [busy, setBusy] = useState(false);
  const [accounts, setAccounts] = useState<SocialAccount[]>([]);
  const [backfill, setBackfill] = useState<{ account: string; form: string }>({ account: '', form: '' });
  const [backfillResult, setBackfillResult] = useState<any>(null);
  useEffect(() => { socialAccounts(orgId).then(a => setAccounts(a.filter(x => x.platform === 'facebook'))).catch(() => {}); }, [orgId]);

  return (
    <div className="mt-4 flex flex-col gap-4">
      <div className="rounded-xl bg-white p-4 shadow-sm">
        <h3 className="font-semibold">Import leads from CSV / XLSX</h3>
        <p className="text-xs text-slate-500">Headers are matched flexibly (Full Name, Phone Number, Email, City, Budget…). Phones are normalized to +92 format; duplicates become touchpoints on the existing lead; same phone with a different name is flagged for review, never merged.</p>
        {can('leads.import') ? (
          <label className="mt-2 inline-block cursor-pointer rounded bg-emerald-600 px-4 py-2 text-sm text-white">
            {busy ? 'Importing…' : 'Choose .csv or .xlsx file'}
            <input type="file" accept=".csv,.xlsx,.txt" className="hidden" onChange={async e => {
              const f = e.target.files?.[0]; if (!f) return; setBusy(true);
              try { const r = await leadImportUpload(orgId, f); setReport(r.report); } catch (err: any) { setError(err.message); }
              setBusy(false); e.target.value = '';
            }} />
          </label>
        ) : <p className="mt-2 text-sm text-slate-400">You do not have import permission.</p>}
        {report && (
          <div className="mt-3 grid grid-cols-2 gap-2 text-sm md:grid-cols-5">
            {[['Rows', report.total], ['Created', report.created], ['Duplicates → touchpoints', report.touchpoints], ['Possible duplicates', report.possible_duplicates], ['Invalid rows', report.invalid]].map(([k, v]) => (
              <div key={k as string} className="rounded bg-slate-50 p-2"><b>{v as number}</b><div className="text-xs text-slate-500">{k}</div></div>
            ))}
            {report.errors?.length > 0 && <div className="col-span-full text-xs text-red-600">{report.errors.map((e: any) => `Row ${e.row}: ${e.error}`).join(' · ')}</div>}
          </div>
        )}
      </div>

      <div className="rounded-xl bg-white p-4 shadow-sm">
        <h3 className="font-semibold">Meta Lead Ads backfill</h3>
        <p className="text-xs text-slate-500">Pull existing leads of a lead form into this workspace (uses the Page token; leads already imported are skipped by their Meta lead ID).</p>
        <div className="mt-2 flex flex-wrap gap-2">
          <select className="rounded border px-2 py-1.5 text-sm" value={backfill.account} onChange={e => setBackfill({ ...backfill, account: e.target.value })}>
            <option value="">Select Facebook Page…</option>
            {accounts.map(a => <option key={a.id} value={a.id}>{a.display_name}</option>)}
          </select>
          <input className="rounded border px-2 py-1.5 text-sm" placeholder="Lead form ID" value={backfill.form} onChange={e => setBackfill({ ...backfill, form: e.target.value })} />
          <button disabled={!backfill.account || !backfill.form} className="rounded bg-blue-600 px-3 py-1.5 text-sm text-white disabled:opacity-50"
            onClick={async () => { try { setBackfillResult(await leadBackfill(orgId, { social_account_id: Number(backfill.account), form_id: backfill.form })); } catch (e: any) { setError(e.message); } }}>Run backfill</button>
        </div>
        {backfillResult && <p className="mt-2 text-sm">Fetched {backfillResult.fetched} · created {backfillResult.created} · touchpoints {backfillResult.touchpoints} · already known {backfillResult.duplicates}</p>}
      </div>
    </div>
  );
}

function LeadFormsTab({ orgId, can, setError }: { orgId: number; can: (p: string) => boolean; setError: (s: string) => void }) {
  const [forms, setForms] = useState<LeadFormT[]>([]);
  const [name, setName] = useState('');
  const [ref, setRef] = useState('');
  const load = () => leadForms(orgId).then(setForms).catch(e => setError(e.message));
  useEffect(() => { load(); }, [orgId]);
  const apiBase = (import.meta as any).env?.VITE_API_URL ?? 'http://127.0.0.1:8000/api/v1';
  return (
    <div className="mt-4">
      {can('leads.forms') && (
        <div className="flex flex-wrap gap-2 rounded-xl bg-white p-3 shadow-sm">
          <input className="rounded border px-2 py-1.5 text-sm" placeholder="Form name (e.g. Bahria Listing Enquiry)" value={name} onChange={e => setName(e.target.value)} />
          <input className="rounded border px-2 py-1.5 text-sm" placeholder="Property reference (optional)" value={ref} onChange={e => setRef(e.target.value)} />
          <button className="rounded bg-emerald-600 px-3 py-1.5 text-sm text-white" onClick={async () => { if (!name) return; try { await leadFormCreate(orgId, { name, property_reference: ref || null }); setName(''); setRef(''); load(); } catch (e: any) { setError(e.message); } }}>Create form</button>
        </div>
      )}
      <div className="mt-3 flex flex-col gap-3">
        {forms.map(f => (
          <div key={f.id} className="rounded-xl bg-white p-4 shadow-sm">
            <div className="flex flex-wrap items-center gap-2">
              <b>{f.name}</b>
              <span className={`rounded px-2 py-0.5 text-xs ${f.is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-500'}`}>{f.is_active ? 'active' : 'paused'}</span>
              <span className="text-xs text-slate-400">{f.submissions_count} submissions</span>
              <span className="ml-auto flex gap-1.5 text-xs">
                {can('leads.forms') && <button className="rounded border px-2 py-1" onClick={async () => { try { await leadFormUpdate(orgId, f.id, { is_active: !f.is_active }); load(); } catch (e: any) { setError(e.message); } }}>{f.is_active ? 'Pause' : 'Activate'}</button>}
                {can('leads.forms') && <button className="rounded border border-red-300 px-2 py-1 text-red-700" onClick={async () => { if (window.confirm('Delete this form?')) { try { await leadFormDelete(orgId, f.id); load(); } catch (e: any) { setError(e.message); } } }}>Delete</button>}
              </span>
            </div>
            <div className="mt-2 text-xs text-slate-600">
              <div>Public submit endpoint: <code className="rounded bg-slate-100 px-1">{apiBase}/public/forms/{f.public_key}/submit</code></div>
              <div className="mt-1">Embed on your site with a form POST to that URL (fields: full_name, phone, whatsapp, email, city, preferred_location, budget_min, budget_max, message, plus utm_* hidden fields). Submissions are deduplicated and auto-assigned like every other source.</div>
            </div>
          </div>
        ))}
        {forms.length === 0 && <p className="text-sm text-slate-500">No website forms yet.</p>}
      </div>
    </div>
  );
}

function FollowUpsTab({ orgId, can, setError }: { orgId: number; can: (p: string) => boolean; setError: (s: string) => void }) {
  const [scope, setScope] = useState('?scope=mine');
  const [items, setItems] = useState<FollowUp[]>([]);
  const load = () => followUpList(orgId, scope).then(r => setItems(r.data)).catch(e => setError(e.message));
  useEffect(() => { load(); }, [orgId, scope]);
  return (
    <div className="mt-4">
      <div className="flex gap-2 text-sm">
        {[['?scope=mine', 'Mine'], ['?scope=today', 'Due today'], ['?scope=overdue', 'Overdue'], ['', 'All']].map(([s, label]) => (
          <button key={label} onClick={() => setScope(s)} className={`rounded px-3 py-1.5 ${scope === s ? 'bg-slate-900 text-white' : 'bg-white shadow-sm'}`}>{label}</button>
        ))}
      </div>
      <div className="mt-3 flex flex-col gap-2">
        {items.map(f => {
          const overdue = f.status === 'pending' && new Date(f.due_at) < new Date();
          return (
            <div key={f.id} className="flex flex-wrap items-center gap-2 rounded-xl bg-white p-3 shadow-sm">
              <b className="text-sm">{f.lead?.full_name ?? `Lead #${f.lead_id}`}</b>
              <span className="text-xs text-slate-500">{f.lead?.phone_e164 ?? ''}</span>
              <span className={`rounded px-2 py-0.5 text-xs ${f.status === 'done' ? 'bg-emerald-100 text-emerald-800' : overdue ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800'}`}>{f.status === 'pending' && overdue ? 'overdue' : f.status}</span>
              <span className="text-xs text-slate-500">Due {new Date(f.due_at).toLocaleString()}</span>
              {f.note && <span className="text-xs text-slate-600">{f.note}</span>}
              {f.status === 'pending' && can('leads.followups') && (
                <button className="ml-auto rounded bg-emerald-600 px-2 py-1 text-xs text-white" onClick={async () => { try { await followUpComplete(orgId, f.id); load(); } catch (e: any) { setError(e.message); } }}>Mark done</button>
              )}
            </div>
          );
        })}
        {items.length === 0 && <p className="text-sm text-slate-500">Nothing here.</p>}
      </div>
    </div>
  );
}

function LeadSetupTab({ orgId, can, setError }: { orgId: number; can: (p: string) => boolean; setError: (s: string) => void }) {
  const [stages, setStages] = useState<LeadStage[]>([]);
  const [rules, setRules] = useState<any[]>([]);
  const [membersList, setMembersList] = useState<any[]>([]);
  const [stageName, setStageName] = useState('');
  const [rule, setRule] = useState({ match_type: 'city', match_value: '', assign_to_user_id: '' });
  const load = () => {
    leadStages(orgId).then(setStages).catch(e => setError(e.message));
    if (can('leads.configure')) leadRules(orgId).then(setRules).catch(() => {});
    members(orgId).then(m => setMembersList(m.filter(x => x.status === 'active'))).catch(() => {});
  };
  useEffect(() => { load(); }, [orgId]);
  const input = 'rounded border px-2 py-1.5 text-sm';
  return (
    <div className="mt-4 grid gap-4 md:grid-cols-2">
      <div className="rounded-xl bg-white p-4 shadow-sm">
        <h3 className="font-semibold">Pipeline stages</h3>
        <ol className="mt-2 list-decimal pl-5 text-sm">
          {stages.map(s => <li key={s.id}>{s.name} {s.is_won && <span className="text-emerald-600">(won)</span>} {s.is_lost && <span className="text-red-600">(lost)</span>}</li>)}
        </ol>
        {can('leads.configure') && (
          <div className="mt-3 flex gap-2">
            <input className={input} placeholder="New stage name" value={stageName} onChange={e => setStageName(e.target.value)} />
            <button className="rounded bg-slate-900 px-3 py-1.5 text-sm text-white" onClick={async () => { if (!stageName) return; try { await leadStageCreate(orgId, { name: stageName }); setStageName(''); load(); } catch (e: any) { setError(e.message); } }}>Add stage</button>
          </div>
        )}
        <p className="mt-2 text-xs text-slate-400">New leads are auto-assigned by round-robin across sales agents unless a rule below matches.</p>
      </div>
      <div className="rounded-xl bg-white p-4 shadow-sm">
        <h3 className="font-semibold">Assignment rules</h3>
        {!can('leads.configure') && <p className="text-sm text-slate-400">Only workspace admins can change assignment rules.</p>}
        {rules.map(r => (
          <div key={r.id} className="mt-2 flex items-center gap-2 text-sm">
            <span className="rounded bg-slate-100 px-2 py-0.5 text-xs">{r.match_type}{r.match_value ? `: ${r.match_value}` : ''}</span>
            <span>→ {r.assignee?.name ?? 'user'}</span>
            {can('leads.configure') && <button className="ml-auto text-xs text-red-600" onClick={async () => { try { await leadRuleDelete(orgId, r.id); load(); } catch (e: any) { setError(e.message); } }}>Delete</button>}
          </div>
        ))}
        {can('leads.configure') && (
          <div className="mt-3 flex flex-wrap gap-2">
            <select className={input} value={rule.match_type} onChange={e => setRule({ ...rule, match_type: e.target.value })}>
              <option value="city">City matches</option>
              <option value="property_type">Property type matches</option>
              <option value="source">Source key is</option>
              <option value="default">Default (all other leads)</option>
            </select>
            <input className={input} placeholder="Match value (e.g. Lahore)" value={rule.match_value} onChange={e => setRule({ ...rule, match_value: e.target.value })} />
            <select className={input} value={rule.assign_to_user_id} onChange={e => setRule({ ...rule, assign_to_user_id: e.target.value })}>
              <option value="">Assign to…</option>
              {membersList.map(m => <option key={m.user?.id ?? m.user_id} value={m.user?.id ?? m.user_id}>{m.user?.name ?? m.name}</option>)}
            </select>
            <button className="rounded bg-emerald-600 px-3 py-1.5 text-sm text-white" onClick={async () => {
              if (!rule.assign_to_user_id) return;
              try { await leadRuleCreate(orgId, { match_type: rule.match_type, match_value: rule.match_value || null, assign_to_user_id: Number(rule.assign_to_user_id) }); setRule({ match_type: 'city', match_value: '', assign_to_user_id: '' }); load(); } catch (e: any) { setError(e.message); }
            }}>Add rule</button>
          </div>
        )}
      </div>
    </div>
  );
}

// ---------------- Phase 5: notifications, pipeline, tasks, WhatsApp ----------------

function NotificationBell({ orgId, setError }: { orgId: number; setError: (s: string) => void }) {
  const [items, setItems] = useState<AppNotification[]>([]);
  const [unread, setUnread] = useState(0);
  const [open, setOpen] = useState(false);
  const [showPrefs, setShowPrefs] = useState(false);
  const [prefTypes, setPrefTypes] = useState<string[]>([]);
  const [disabled, setDisabled] = useState<string[]>([]);

  const load = () => notificationList(orgId).then(r => { setItems(r.data); setUnread(r.unread_count); }).catch(() => {});
  useEffect(() => { load(); const t = setInterval(load, 60000); return () => clearInterval(t); }, [orgId]);

  return (
    <div className="relative mb-3">
      <button className="flex w-full items-center gap-2 rounded bg-slate-800 px-3 py-2 text-left text-sm text-white" onClick={() => setOpen(o => !o)}>
        <span>🔔 Notifications</span>
        {unread > 0 && <span className="ml-auto rounded-full bg-red-500 px-2 py-0.5 text-xs font-bold">{unread}</span>}
      </button>
      {open && (
        <div className="absolute bottom-full left-0 z-30 mb-2 w-80 rounded-xl bg-white p-2 text-slate-800 shadow-2xl">
          <div className="flex items-center justify-between px-1">
            <b className="text-sm">Notifications</b>
            <span className="flex gap-2 text-xs">
              <button className="text-blue-600" onClick={async () => { try { await notificationReadAll(orgId); load(); } catch (e: any) { setError(e.message); } }}>Mark all read</button>
              <button className="text-slate-500" onClick={async () => {
                if (!showPrefs) { try { const p = await notificationPreferences(orgId); setPrefTypes(p.types); setDisabled(p.disabled_types); } catch (e: any) { setError(e.message); } }
                setShowPrefs(s => !s);
              }}>Preferences</button>
            </span>
          </div>
          {showPrefs && (
            <div className="mt-1 max-h-44 overflow-y-auto rounded bg-slate-50 p-2">
              <div className="text-[11px] text-slate-500">Mute types in this workspace:</div>
              {prefTypes.map(t => (
                <label key={t} className="flex items-center gap-1.5 text-xs">
                  <input type="checkbox" checked={disabled.includes(t)} onChange={async e => {
                    const next = e.target.checked ? [...disabled, t] : disabled.filter(x => x !== t);
                    setDisabled(next);
                    try { await notificationPreferencesUpdate(orgId, next); } catch (err: any) { setError(err.message); }
                  }} /> {t}
                </label>
              ))}
            </div>
          )}
          <div className="mt-1 flex max-h-72 flex-col gap-1 overflow-y-auto">
            {items.length === 0 && <p className="p-2 text-xs text-slate-400">No notifications.</p>}
            {items.map(n => (
              <button key={n.id} className={`rounded p-2 text-left text-xs ${n.read_at ? 'bg-white' : 'bg-blue-50'}`}
                onClick={async () => { try { await notificationRead(orgId, n.id); load(); } catch (e: any) { setError(e.message); } }}>
                <div className="font-medium">{n.title}</div>
                {n.body && <div className="text-slate-500">{n.body}</div>}
                <div className="text-[10px] text-slate-400">{n.type} · {new Date(n.created_at).toLocaleString()}</div>
              </button>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}

function PipelineTab({ orgId, can, setError }: { orgId: number; can: (p: string) => boolean; setError: (s: string) => void }) {
  const [columns, setColumns] = useState<PipelineColumn[]>([]);
  const [mine, setMine] = useState(false);
  const load = () => pipelineBoard(orgId, mine).then(setColumns).catch(e => setError(e.message));
  useEffect(() => { load(); }, [orgId, mine]);
  return (
    <div className="mt-4">
      <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={mine} onChange={e => setMine(e.target.checked)} /> Show only my leads</label>
      <div className="mt-3 flex gap-3 overflow-x-auto pb-3">
        {columns.map(col => (
          <div key={col.stage.id} className={`flex w-64 shrink-0 flex-col rounded-xl p-2 ${col.stage.is_won ? 'bg-emerald-50' : col.stage.is_lost ? 'bg-red-50' : 'bg-slate-100'}`}
            onDragOver={e => e.preventDefault()}
            onDrop={async e => {
              const leadId = Number(e.dataTransfer.getData('text/plain'));
              if (!leadId) return;
              try { await leadMove(orgId, leadId, col.stage.id); load(); } catch (err: any) { setError(err.message); }
            }}>
            <div className="flex items-center justify-between px-1 py-1">
              <b className="text-sm">{col.stage.name}</b>
              <span className="rounded-full bg-white px-2 text-xs shadow-sm">{col.count}</span>
            </div>
            <div className="flex flex-col gap-2">
              {col.leads.map((l: any) => (
                <div key={l.id} draggable={can('leads.edit')}
                  onDragStart={e => e.dataTransfer.setData('text/plain', String(l.id))}
                  className="cursor-grab rounded-lg bg-white p-2.5 shadow-sm active:cursor-grabbing">
                  <div className="text-sm font-medium">{l.full_name}{l.possible_duplicate_of && <span className="ml-1 rounded bg-orange-100 px-1 text-[10px] text-orange-800">dup?</span>}</div>
                  <div className="text-xs text-slate-500">{l.phone_e164 ?? ''}</div>
                  <div className="text-xs text-slate-500">{[l.city, l.preferred_location].filter(Boolean).join(' · ')}</div>
                  {l.property_reference && <div className="text-xs text-slate-400">{l.property_reference}</div>}
                  <div className="mt-1 flex items-center justify-between text-[11px] text-slate-400">
                    <span>{l.source ?? ''}</span>
                    <span>{l.assignee ?? 'Unassigned'}</span>
                  </div>
                  {l.priority !== 'normal' && <span className={`mt-1 inline-block rounded px-1 text-[10px] ${l.priority === 'urgent' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700'}`}>{l.priority}</span>}
                </div>
              ))}
              {col.leads.length === 0 && <p className="p-2 text-center text-xs text-slate-400">Drop leads here</p>}
            </div>
          </div>
        ))}
      </div>
      <p className="text-xs text-slate-400">Drag a card to another column to change its stage. Every move is recorded in the lead's activity timeline.</p>
    </div>
  );
}

function TasksTab({ orgId, can, setError }: { orgId: number; can: (p: string) => boolean; setError: (s: string) => void }) {
  const [tasks, setTasks] = useState<LeadTaskT[]>([]);
  const [scope, setScope] = useState('?scope=mine');
  const [title, setTitle] = useState('');
  const load = () => taskList(orgId, scope).then(r => setTasks(r.data)).catch(e => setError(e.message));
  useEffect(() => { load(); }, [orgId, scope]);
  return (
    <div className="mt-4">
      <div className="flex flex-wrap items-center gap-2">
        {[['?scope=mine', 'My tasks'], ['', 'All tasks']].map(([s, label]) => (
          <button key={label} onClick={() => setScope(s)} className={`rounded px-3 py-1.5 text-sm ${scope === s ? 'bg-slate-900 text-white' : 'bg-white shadow-sm'}`}>{label}</button>
        ))}
        {can('tasks.create') && (
          <span className="ml-auto flex gap-2">
            <input className="rounded border px-2 py-1.5 text-sm" placeholder="New task title…" value={title} onChange={e => setTitle(e.target.value)} />
            <button className="rounded bg-emerald-600 px-3 py-1.5 text-sm text-white" onClick={async () => { if (!title) return; try { await taskCreate(orgId, { title }); setTitle(''); load(); } catch (e: any) { setError(e.message); } }}>Add task</button>
          </span>
        )}
      </div>
      <div className="mt-3 flex flex-col gap-2">
        {tasks.map(t => (
          <div key={t.id} className="flex flex-wrap items-center gap-2 rounded-xl bg-white p-3 shadow-sm">
            <input type="checkbox" checked={t.status === 'done'} disabled={!can('tasks.edit')}
              onChange={async e => { try { await taskUpdate(orgId, t.id, { status: e.target.checked ? 'done' : 'pending' }); load(); } catch (err: any) { setError(err.message); } }} />
            <b className={`text-sm ${t.status === 'done' ? 'text-slate-400 line-through' : ''}`}>{t.title}</b>
            {t.lead && <span className="rounded bg-slate-100 px-1.5 text-xs">{t.lead.full_name}</span>}
            {t.due_at && <span className={`text-xs ${t.status === 'pending' && new Date(t.due_at) < new Date() ? 'font-semibold text-red-600' : 'text-slate-500'}`}>Due {new Date(t.due_at).toLocaleString()}</span>}
            <span className="text-xs text-slate-400">{t.assignee?.name ?? ''}</span>
            {t.priority !== 'normal' && <span className="rounded bg-amber-100 px-1 text-[10px] text-amber-700">{t.priority}</span>}
            {can('tasks.delete') && <button className="ml-auto text-xs text-red-600" onClick={async () => { if (window.confirm('Delete task?')) { try { await taskDelete(orgId, t.id); load(); } catch (e: any) { setError(e.message); } } }}>Delete</button>}
          </div>
        ))}
        {tasks.length === 0 && <p className="text-sm text-slate-500">No tasks here.</p>}
      </div>
    </div>
  );
}

function WhatsAppChat({ orgId, conversationId, can, setError, onBack }: {
  orgId: number; conversationId: number; can: (p: string) => boolean; setError: (s: string) => void; onBack?: () => void;
}) {
  const [conv, setConv] = useState<WhatsAppConversationT | null>(null);
  const [text, setText] = useState('');
  const [templates, setTemplates] = useState<WhatsAppTemplateT[]>([]);
  const [tpl, setTpl] = useState('');
  const [params, setParams] = useState('');
  const load = () => waConversation(orgId, conversationId).then(setConv).catch(e => setError(e.message));
  useEffect(() => { load(); }, [orgId, conversationId]);
  useEffect(() => {
    if (conv?.account?.id) waTemplates(orgId, conv.account.id).then(t => setTemplates(t.filter(x => x.status === 'APPROVED'))).catch(() => {});
  }, [orgId, conv?.account?.id]);

  if (!conv) return <p className="p-3 text-sm text-slate-500">Loading conversation…</p>;
  const windowOpen = conv.window_expires_at ? new Date(conv.window_expires_at) > new Date() : false;
  const selectedTpl = templates.find(t => `${t.name}|${t.language}` === tpl);

  return (
    <div className="flex h-full flex-col">
      <div className="flex items-center gap-2 border-b p-3">
        {onBack && <button className="rounded border px-2 py-1 text-xs" onClick={onBack}>← All</button>}
        <div>
          <b className="text-sm">{conv.contact_name ?? conv.lead?.full_name ?? conv.contact_phone_e164}</b>
          <div className="text-[11px] text-slate-500">{conv.contact_phone_e164} · via {conv.account?.display_phone_number ?? 'WhatsApp'}</div>
        </div>
        <span className={`ml-auto rounded px-2 py-0.5 text-[11px] ${conv.opted_out_at ? 'bg-red-100 text-red-700' : windowOpen ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'}`}>
          {conv.opted_out_at ? 'Opted out — do not message' : windowOpen ? `24h window open until ${new Date(conv.window_expires_at!).toLocaleTimeString()}` : 'Window closed — template required'}
        </span>
      </div>
      <div className="flex flex-1 flex-col gap-1.5 overflow-y-auto bg-[#efeae2] p-3">
        {(conv.messages ?? []).map(m => (
          <div key={m.id} className={`max-w-[75%] rounded-lg p-2 text-sm shadow-sm ${m.direction === 'in' ? 'self-start bg-white' : 'self-end bg-emerald-100'}`}>
            {m.type === 'template' && <div className="text-[10px] font-semibold text-slate-500">Template: {m.template_name}</div>}
            <div className="whitespace-pre-wrap">{m.body ?? `(${m.type} message)`}</div>
            <div className="mt-0.5 text-right text-[10px] text-slate-400">
              {new Date(m.created_at).toLocaleTimeString()} · {m.direction === 'out' ? (m.status === 'failed' ? `failed: ${m.error ?? ''}` : m.status) : 'received'}
            </div>
          </div>
        ))}
        {(conv.messages ?? []).length === 0 && <p className="text-center text-xs text-slate-400">No messages yet. Send an approved template to start.</p>}
      </div>
      {can('whatsapp.send') && !conv.opted_out_at && (
        <div className="border-t p-3">
          {windowOpen ? (
            <div className="flex gap-2">
              <input className="flex-1 rounded border px-2 py-2 text-sm" placeholder="Type a reply…" value={text} onChange={e => setText(e.target.value)}
                onKeyDown={async e => { if (e.key === 'Enter' && text) { try { await waSendText(orgId, conv.id, text); setText(''); load(); } catch (err: any) { setError(err.message); } } }} />
              <button className="rounded bg-emerald-600 px-4 py-2 text-sm text-white" onClick={async () => { if (!text) return; try { await waSendText(orgId, conv.id, text); setText(''); load(); } catch (e: any) { setError(e.message); } }}>Send</button>
            </div>
          ) : (
            <div className="flex flex-col gap-2">
              <div className="flex flex-wrap gap-2">
                <select className="rounded border px-2 py-2 text-sm" value={tpl} onChange={e => setTpl(e.target.value)}>
                  <option value="">Choose an approved template…</option>
                  {templates.map(t => <option key={t.id} value={`${t.name}|${t.language}`}>{t.name} ({t.language}) · {t.category}</option>)}
                </select>
                <input className="flex-1 rounded border px-2 py-2 text-sm" placeholder="Template values, comma separated (for {{1}}, {{2}}…)" value={params} onChange={e => setParams(e.target.value)} />
                <button disabled={!selectedTpl} className="rounded bg-blue-600 px-4 py-2 text-sm text-white disabled:opacity-50"
                  onClick={async () => { if (!selectedTpl) return; try { await waSendTemplate(orgId, conv.id, { template_name: selectedTpl.name, language: selectedTpl.language, params: params ? params.split(',').map(s => s.trim()) : [] }); setParams(''); load(); } catch (e: any) { setError(e.message); } }}>Send template</button>
              </div>
              {selectedTpl && <p className="text-xs text-slate-500">{((selectedTpl.components ?? []).find((c: any) => c.type === 'BODY')?.text ?? '')}</p>}
              <p className="text-[11px] text-slate-400">Free-form replies unlock for 24 hours after the customer messages you.</p>
            </div>
          )}
        </div>
      )}
      {conv.opted_out_at && <p className="border-t p-3 text-xs text-red-600">This contact opted out (STOP). Sending is blocked until they opt back in.</p>}
    </div>
  );
}

function LeadWhatsAppPanel({ orgId, lead, can, setError }: { orgId: number; lead: Lead; can: (p: string) => boolean; setError: (s: string) => void }) {
  const [accounts, setAccounts] = useState<WhatsAppAccountT[]>([]);
  const [accountId, setAccountId] = useState('');
  const [conversationId, setConversationId] = useState<number | null>(null);
  useEffect(() => { waAccounts(orgId).then(a => { setAccounts(a.filter(x => x.status === 'connected')); }).catch(() => {}); }, [orgId]);
  if (accounts.length === 0) return null;
  return (
    <div className="mt-4 rounded-xl border border-emerald-200 bg-emerald-50/50 p-3">
      <h4 className="text-sm font-semibold">💬 WhatsApp</h4>
      {!conversationId ? (
        <div className="mt-2 flex flex-wrap gap-2">
          <select className="rounded border px-2 py-1.5 text-sm" value={accountId} onChange={e => setAccountId(e.target.value)}>
            <option value="">Send from number…</option>
            {accounts.map(a => <option key={a.id} value={a.id}>{a.verified_name ?? ''} {a.display_phone_number ?? ''}</option>)}
          </select>
          <button disabled={!accountId} className="rounded bg-emerald-600 px-3 py-1.5 text-sm text-white disabled:opacity-50"
            onClick={async () => { try { const c = await waStartForLead(orgId, lead.id, Number(accountId)); setConversationId(c.id); } catch (e: any) { setError(e.message); } }}>
            Open chat with this lead
          </button>
        </div>
      ) : (
        <div className="mt-2 h-96 overflow-hidden rounded-lg border bg-white">
          <WhatsAppChat orgId={orgId} conversationId={conversationId} can={can} setError={setError} />
        </div>
      )}
    </div>
  );
}

function WhatsAppView({ orgId, can, setError }: { orgId: number; can: (p: string) => boolean; setError: (s: string) => void }) {
  const [accounts, setAccounts] = useState<WhatsAppAccountT[]>([]);
  const [conversations, setConversations] = useState<WhatsAppConversationT[]>([]);
  const [activeConv, setActiveConv] = useState<number | null>(null);
  const [templates, setTemplates] = useState<Record<number, WhatsAppTemplateT[]>>({});
  const [form, setForm] = useState({ waba_id: '', phone_number_id: '', access_token: '' });

  const loadAccounts = () => waAccounts(orgId).then(setAccounts).catch(e => setError(e.message));
  const loadConversations = () => waConversations(orgId).then(r => setConversations(r.data)).catch(e => setError(e.message));
  useEffect(() => { loadAccounts(); loadConversations(); }, [orgId]);
  const input = 'w-full rounded border px-2 py-1.5 text-sm';

  return (
    <div>
      <h2 className="text-2xl font-bold">WhatsApp Inbox</h2>
      <div className="mt-4 grid gap-4 lg:grid-cols-3">
        <div className="flex flex-col gap-4">
          <div className="rounded-xl bg-white p-4 shadow-sm">
            <h3 className="font-semibold">Connected numbers</h3>
            {accounts.map(a => (
              <div key={a.id} className="mt-2 rounded border p-2 text-sm">
                <div className="flex items-center gap-2">
                  <b>{a.verified_name ?? 'WhatsApp number'}</b>
                  <span className={`rounded px-1.5 text-[10px] ${a.status === 'connected' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-500'}`}>{a.status}</span>
                  {a.quality_rating && <span className="text-[10px] text-slate-400">Quality: {a.quality_rating}</span>}
                </div>
                <div className="text-xs text-slate-500">{a.display_phone_number} · WABA {a.waba_id}</div>
                <div className="mt-1 flex flex-wrap gap-1.5 text-xs">
                  {can('whatsapp.connect') && a.status === 'connected' && (
                    <button className="rounded border px-2 py-1" onClick={async () => { try { const r = await waSyncTemplates(orgId, a.id); window.alert(`Synced ${r.synced} templates.`); const t = await waTemplates(orgId, a.id); setTemplates(prev => ({ ...prev, [a.id]: t })); } catch (e: any) { setError(e.message); } }}>Sync templates</button>
                  )}
                  {can('whatsapp.templates') && (
                    <button className="rounded border px-2 py-1" onClick={async () => { try { const t = await waTemplates(orgId, a.id); setTemplates(prev => ({ ...prev, [a.id]: t })); } catch (e: any) { setError(e.message); } }}>Show templates ({templates[a.id]?.length ?? '…'})</button>
                  )}
                  {can('whatsapp.connect') && a.status === 'connected' && (
                    <button className="rounded border border-red-300 px-2 py-1 text-red-700" onClick={async () => { if (window.confirm('Disconnect this WhatsApp number?')) { try { await waDisconnect(orgId, a.id); loadAccounts(); } catch (e: any) { setError(e.message); } } }}>Disconnect</button>
                  )}
                </div>
                {(templates[a.id] ?? []).map(t => (
                  <div key={t.id} className="mt-1 text-xs text-slate-600">{t.name} ({t.language}) · {t.category} · <span className={t.status === 'APPROVED' ? 'text-emerald-600' : 'text-amber-600'}>{t.status}</span></div>
                ))}
              </div>
            ))}
            {accounts.length === 0 && <p className="mt-1 text-sm text-slate-500">No WhatsApp number connected yet.</p>}
            {can('whatsapp.connect') && (
              <div className="mt-3 border-t pt-3">
                <h4 className="text-sm font-medium">Connect a number</h4>
                <p className="text-[11px] text-slate-500">Use a WhatsApp Business Platform number: enter its WABA ID, phone number ID and a permanent (system user) access token. The number is verified with Meta before anything is saved. In the Meta App Dashboard, point the WhatsApp webhook to <code>/api/v1/webhooks/whatsapp</code> with the same verify token as Lead Ads.</p>
                <div className="mt-2 flex flex-col gap-2">
                  <input className={input} placeholder="WhatsApp Business Account ID (WABA)" value={form.waba_id} onChange={e => setForm({ ...form, waba_id: e.target.value })} />
                  <input className={input} placeholder="Phone number ID" value={form.phone_number_id} onChange={e => setForm({ ...form, phone_number_id: e.target.value })} />
                  <input className={input} type="password" placeholder="Permanent access token" value={form.access_token} onChange={e => setForm({ ...form, access_token: e.target.value })} />
                  <button className="rounded bg-emerald-600 px-3 py-2 text-sm text-white" onClick={async () => {
                    try { await waConnect(orgId, form); setForm({ waba_id: '', phone_number_id: '', access_token: '' }); loadAccounts(); } catch (e: any) { setError(e.message); }
                  }}>Verify & connect</button>
                </div>
              </div>
            )}
          </div>
          <div className="rounded-xl bg-white p-4 shadow-sm">
            <h3 className="font-semibold">Conversations</h3>
            <div className="mt-2 flex flex-col gap-1">
              {conversations.map(c => (
                <button key={c.id} onClick={() => setActiveConv(c.id)} className={`rounded p-2 text-left text-sm ${activeConv === c.id ? 'bg-emerald-50' : 'hover:bg-slate-50'}`}>
                  <span className="flex items-center gap-2">
                    <b>{c.contact_name ?? c.lead?.full_name ?? c.contact_phone_e164}</b>
                    {c.unread_count > 0 && <span className="rounded-full bg-emerald-600 px-1.5 text-[10px] font-bold text-white">{c.unread_count}</span>}
                    {c.opted_out_at && <span className="text-[10px] text-red-600">opted out</span>}
                  </span>
                  <span className="block text-xs text-slate-400">{c.contact_phone_e164}{c.last_message_at ? ` · ${new Date(c.last_message_at).toLocaleString()}` : ''}</span>
                </button>
              ))}
              {conversations.length === 0 && <p className="text-sm text-slate-500">No conversations yet. When a customer messages your number, a lead and a conversation are created automatically.</p>}
            </div>
          </div>
        </div>
        <div className="overflow-hidden rounded-xl bg-white shadow-sm lg:col-span-2" style={{ minHeight: 480 }}>
          {activeConv
            ? <WhatsAppChat orgId={orgId} conversationId={activeConv} can={can} setError={setError} />
            : <div className="flex h-full items-center justify-center p-8 text-center text-sm text-slate-400">Select a conversation, or open one from a lead's detail page.</div>}
        </div>
      </div>
    </div>
  );
}

// ---------------- Phase 6: Campaigns & Analytics ----------------

const fmtMoney = (v: number | null | undefined, currency = 'PKR') =>
  v === null || v === undefined ? '—' : `${currency} ${Number(v).toLocaleString('en-PK', { maximumFractionDigits: 0 })}`;
const fmtNum = (v: number | null | undefined) => (v === null || v === undefined ? 'n/a' : v.toLocaleString('en-PK'));

function CampaignsView({ orgId, can, setError }: { orgId: number; can: (p: string) => boolean; setError: (s: string) => void }) {
  const [campaigns, setCampaigns] = useState<CampaignT[]>([]);
  const [detail, setDetail] = useState<(CampaignT & { metrics: CampaignMetrics }) | null>(null);
  const [compareIds, setCompareIds] = useState<number[]>([]);
  const [comparison, setComparison] = useState<{ campaign: any; metrics: CampaignMetrics }[] | null>(null);
  const [form, setForm] = useState({ name: '', platform: 'meta', objective: 'leads', budget: '', status: 'active', external_campaign_id: '', utm_campaign: '' });
  const [sync, setSync] = useState({ ad_account_id: '', since: '', until: '' });
  const [syncMsg, setSyncMsg] = useState('');

  const load = () => campaignList(orgId).then(setCampaigns).catch(e => setError(e.message));
  useEffect(() => { load(); }, [orgId]);
  const openDetail = async (id: number) => { try { setDetail(await campaignGet(orgId, id)); } catch (e: any) { setError(e.message); } };
  const input = 'rounded border px-2 py-1.5 text-sm';

  return (
    <div>
      <h2 className="text-2xl font-bold">Campaigns</h2>

      {can('campaigns.create') && (
        <form className="mt-3 flex flex-wrap items-end gap-2 rounded-xl bg-white p-3 shadow-sm" onSubmit={async e => {
          e.preventDefault();
          try {
            await campaignCreate(orgId, {
              name: form.name, platform: form.platform, objective: form.objective, status: form.status,
              budget: form.budget ? Number(form.budget) : null,
              external_campaign_id: form.external_campaign_id || null,
              tracking_params: form.utm_campaign ? { utm_source: form.platform, utm_medium: 'cpc', utm_campaign: form.utm_campaign } : null,
            });
            setForm({ name: '', platform: 'meta', objective: 'leads', budget: '', status: 'active', external_campaign_id: '', utm_campaign: '' });
            load();
          } catch (err: any) { setError(err.message); }
        }}>
          <label className="text-xs">Campaign name<input required className={`${input} block`} value={form.name} onChange={e => setForm({ ...form, name: e.target.value })} /></label>
          <label className="text-xs">Platform<select className={`${input} block`} value={form.platform} onChange={e => setForm({ ...form, platform: e.target.value })}><option value="meta">Meta</option><option value="google">Google</option><option value="tiktok">TikTok</option><option value="offline">Offline</option><option value="other">Other</option></select></label>
          <label className="text-xs">Budget (PKR)<input className={`${input} block`} type="number" value={form.budget} onChange={e => setForm({ ...form, budget: e.target.value })} /></label>
          <label className="text-xs">Meta campaign ID (optional)<input className={`${input} block`} value={form.external_campaign_id} onChange={e => setForm({ ...form, external_campaign_id: e.target.value })} /></label>
          <label className="text-xs">UTM campaign (optional)<input className={`${input} block`} placeholder="dha_launch" value={form.utm_campaign} onChange={e => setForm({ ...form, utm_campaign: e.target.value })} /></label>
          <button className="rounded bg-emerald-600 px-3 py-1.5 text-sm text-white">Create campaign</button>
        </form>
      )}

      {can('campaigns.spend') && (
        <div className="mt-3 flex flex-wrap items-end gap-2 rounded-xl bg-white p-3 shadow-sm">
          <b className="w-full text-sm">Sync from Meta Ads</b>
          <label className="text-xs">Ad account ID<input className={`${input} block`} placeholder="1234567890 or act_1234567890" value={sync.ad_account_id} onChange={e => setSync({ ...sync, ad_account_id: e.target.value })} /></label>
          <label className="text-xs">Since<input className={`${input} block`} type="date" value={sync.since} onChange={e => setSync({ ...sync, since: e.target.value })} /></label>
          <label className="text-xs">Until<input className={`${input} block`} type="date" value={sync.until} onChange={e => setSync({ ...sync, until: e.target.value })} /></label>
          <button className="rounded bg-blue-600 px-3 py-1.5 text-sm text-white" onClick={async () => {
            try { const r = await campaignSyncMeta(orgId, { ad_account_id: sync.ad_account_id, since: sync.since || undefined, until: sync.until || undefined }); setSyncMsg(`Synced ${r.campaigns} campaigns, ${r.spend_rows} daily spend rows.`); load(); }
            catch (e: any) { setError(e.message); }
          }}>Sync now</button>
          {syncMsg && <span className="text-xs text-emerald-700">{syncMsg}</span>}
          <span className="w-full text-[11px] text-slate-400">Uses the connected Meta token; the ad account must grant it the ads_read permission. Spend is stored in the ad account's currency — set each campaign's currency accordingly.</span>
        </div>
      )}

      <div className="mt-3 overflow-x-auto rounded-xl bg-white shadow-sm">
        <table className="w-full text-left text-sm">
          <thead><tr className="border-b text-xs text-slate-500">
            <th className="p-2"></th><th className="p-2">Campaign</th><th className="p-2">Status</th><th className="p-2">Spend</th>
            <th className="p-2">Leads</th><th className="p-2">Qualified</th><th className="p-2">CPL</th><th className="p-2">CPQL</th><th className="p-2">Conv. %</th><th className="p-2">Budget used</th><th className="p-2"></th>
          </tr></thead>
          <tbody>
            {campaigns.map(c => {
              const m = c.metrics;
              return (
                <tr key={c.id} className="border-b last:border-0 hover:bg-slate-50">
                  <td className="p-2"><input type="checkbox" checked={compareIds.includes(c.id)} onChange={e => setCompareIds(prev => e.target.checked ? [...prev, c.id] : prev.filter(x => x !== c.id))} /></td>
                  <td className="p-2 font-medium">{c.name}<div className="text-[11px] font-normal text-slate-400">{c.platform}{c.external_campaign_id ? ` · ${c.external_campaign_id}` : ''}</div></td>
                  <td className="p-2"><span className="rounded bg-slate-100 px-2 py-0.5 text-xs">{c.status}</span></td>
                  <td className="p-2">{fmtMoney(m?.spend, c.currency)}</td>
                  <td className="p-2">{m?.leads ?? 0}</td>
                  <td className="p-2">{m?.qualified_leads ?? 0}</td>
                  <td className="p-2">{fmtMoney(m?.cost_per_lead, c.currency)}</td>
                  <td className="p-2">{fmtMoney(m?.cost_per_qualified_lead, c.currency)}</td>
                  <td className="p-2">{m?.conversion_rate_percent ?? '—'}</td>
                  <td className="p-2">{m?.budget_used_percent != null ? `${m.budget_used_percent}%` : '—'}</td>
                  <td className="p-2"><button className="text-xs text-blue-600 underline" onClick={() => openDetail(c.id)}>Manage</button></td>
                </tr>
              );
            })}
            {campaigns.length === 0 && <tr><td colSpan={11} className="p-4 text-center text-slate-400">No campaigns yet.</td></tr>}
          </tbody>
        </table>
      </div>
      {compareIds.length >= 2 && (
        <button className="mt-2 rounded bg-slate-900 px-3 py-1.5 text-sm text-white" onClick={async () => { try { setComparison(await campaignCompare(orgId, compareIds)); } catch (e: any) { setError(e.message); } }}>
          Compare {compareIds.length} campaigns
        </button>
      )}
      {comparison && (
        <div className="mt-3 grid gap-3 md:grid-cols-2">
          {comparison.map(row => (
            <div key={row.campaign.id} className="rounded-xl bg-white p-4 shadow-sm">
              <b>{row.campaign.name}</b>
              <div className="mt-1 grid grid-cols-2 gap-1 text-sm">
                <span>Spend: {fmtMoney(row.metrics.spend)}</span><span>Leads: {row.metrics.leads}</span>
                <span>CPL: {fmtMoney(row.metrics.cost_per_lead)}</span><span>CPQL: {fmtMoney(row.metrics.cost_per_qualified_lead)}</span>
                <span>Qualified: {row.metrics.qualified_leads}</span><span>Won: {row.metrics.won_leads} ({row.metrics.conversion_rate_percent ?? '—'}%)</span>
                <span>Impressions: {fmtNum(row.metrics.impressions)}</span><span>Clicks: {fmtNum(row.metrics.clicks)}</span>
              </div>
            </div>
          ))}
        </div>
      )}
      {detail && <CampaignDetail orgId={orgId} campaign={detail} can={can} setError={setError} onClose={() => { setDetail(null); load(); }} onChange={setDetail} />}
    </div>
  );
}

function CampaignDetail({ orgId, campaign, can, setError, onClose, onChange }: {
  orgId: number; campaign: CampaignT & { metrics: CampaignMetrics }; can: (p: string) => boolean;
  setError: (s: string) => void; onClose: () => void; onChange: (c: any) => void;
}) {
  const [spend, setSpend] = useState({ date: new Date().toISOString().slice(0, 10), spend: '', impressions: '', clicks: '' });
  const [groupName, setGroupName] = useState('');
  const [adName, setAdName] = useState('');
  const m = campaign.metrics;
  const input = 'rounded border px-2 py-1.5 text-sm';
  const refresh = async () => { try { onChange(await campaignGet(orgId, campaign.id)); } catch (e: any) { setError(e.message); } };
  return (
    <div className="fixed inset-0 z-20 flex items-start justify-center overflow-y-auto bg-black/40 p-4" onClick={onClose}>
      <div className="w-full max-w-3xl rounded-xl bg-white p-5 shadow-xl" onClick={e => e.stopPropagation()}>
        <div className="flex items-start justify-between">
          <div>
            <h3 className="text-lg font-bold">{campaign.name}</h3>
            <div className="text-xs text-slate-500">{campaign.platform} · {campaign.status} · {campaign.objective ?? ''} {campaign.target_location ? `· ${campaign.target_location}` : ''}</div>
            <div className="text-xs text-slate-400">Attribution: {Object.entries(m.attribution_methods).map(([k, v]) => `${k}: ${v}`).join(' · ') || 'no attributed leads yet'} — leads are counted once, matched by manual link, Meta campaign ID, or UTM campaign.</div>
          </div>
          <button className="rounded border px-2 py-1 text-sm" onClick={onClose}>Close</button>
        </div>

        <div className="mt-3 grid grid-cols-2 gap-2 text-sm md:grid-cols-4">
          {[['Spend', fmtMoney(m.spend, campaign.currency)], ['Impressions', fmtNum(m.impressions)], ['Reach', fmtNum(m.reach)], ['Clicks', fmtNum(m.clicks)],
            ['Leads', String(m.leads)], ['Qualified', String(m.qualified_leads)], ['Won', String(m.won_leads)], ['Meta-reported conversions', fmtNum(m.conversions_reported)],
            ['Cost / lead', fmtMoney(m.cost_per_lead, campaign.currency)], ['Cost / qualified', fmtMoney(m.cost_per_qualified_lead, campaign.currency)],
            ['Conversion', m.conversion_rate_percent != null ? `${m.conversion_rate_percent}%` : '—'], ['Budget used', m.budget_used_percent != null ? `${m.budget_used_percent}% of ${fmtMoney(m.budget, campaign.currency)}` : '—'],
          ].map(([k, v]) => (
            <div key={k} className="rounded bg-slate-50 p-2"><div className="text-base font-semibold">{v}</div><div className="text-[11px] text-slate-500">{k}</div></div>
          ))}
        </div>
        <p className="mt-1 text-[11px] text-slate-400">"n/a" means the provider/manual entry did not supply that metric — it is not zero.</p>

        {can('campaigns.spend') && (
          <form className="mt-3 flex flex-wrap items-end gap-2 rounded-lg bg-slate-50 p-3" onSubmit={async e => {
            e.preventDefault();
            try {
              await campaignAddSpend(orgId, campaign.id, { date: spend.date, spend: Number(spend.spend), impressions: spend.impressions ? Number(spend.impressions) : null, clicks: spend.clicks ? Number(spend.clicks) : null });
              setSpend({ ...spend, spend: '', impressions: '', clicks: '' }); refresh();
            } catch (err: any) { setError(err.message); }
          }}>
            <b className="w-full text-sm">Add daily spend (manual)</b>
            <label className="text-xs">Date<input className={`${input} block`} type="date" value={spend.date} onChange={e => setSpend({ ...spend, date: e.target.value })} /></label>
            <label className="text-xs">Spend<input required className={`${input} block`} type="number" value={spend.spend} onChange={e => setSpend({ ...spend, spend: e.target.value })} /></label>
            <label className="text-xs">Impressions (optional)<input className={`${input} block`} type="number" value={spend.impressions} onChange={e => setSpend({ ...spend, impressions: e.target.value })} /></label>
            <label className="text-xs">Clicks (optional)<input className={`${input} block`} type="number" value={spend.clicks} onChange={e => setSpend({ ...spend, clicks: e.target.value })} /></label>
            <button className="rounded bg-emerald-600 px-3 py-1.5 text-sm text-white">Save spend</button>
          </form>
        )}

        <div className="mt-3 max-h-44 overflow-y-auto">
          <table className="w-full text-left text-xs">
            <thead><tr className="border-b text-slate-500"><th className="p-1">Date</th><th className="p-1">Spend</th><th className="p-1">Impr.</th><th className="p-1">Clicks</th><th className="p-1">Conv.</th><th className="p-1">Source</th><th className="p-1"></th></tr></thead>
            <tbody>
              {(campaign.spend_records ?? []).map((r: any) => (
                <tr key={r.id} className="border-b last:border-0">
                  <td className="p-1">{String(r.date).slice(0, 10)}</td><td className="p-1">{fmtMoney(Number(r.spend), campaign.currency)}</td>
                  <td className="p-1">{fmtNum(r.impressions)}</td><td className="p-1">{fmtNum(r.clicks)}</td><td className="p-1">{fmtNum(r.conversions)}</td>
                  <td className="p-1">{r.source}</td>
                  <td className="p-1">{can('campaigns.spend') && <button className="text-red-600" onClick={async () => { try { await campaignDeleteSpend(orgId, campaign.id, r.id); refresh(); } catch (e: any) { setError(e.message); } }}>✕</button>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {can('campaigns.edit') && (
          <div className="mt-3 grid gap-3 md:grid-cols-2">
            <div className="rounded-lg bg-slate-50 p-3">
              <b className="text-sm">Ad groups / ad sets</b>
              {(campaign.ad_groups ?? []).map((g: any) => <div key={g.id} className="text-sm">{g.name} <span className="text-xs text-slate-400">{g.external_id ?? ''}</span></div>)}
              <div className="mt-2 flex gap-2">
                <input className={input} placeholder="New ad group name" value={groupName} onChange={e => setGroupName(e.target.value)} />
                <button className="rounded bg-slate-900 px-2 py-1 text-xs text-white" onClick={async () => { if (!groupName) return; try { await campaignAddGroup(orgId, campaign.id, { name: groupName }); setGroupName(''); refresh(); } catch (e: any) { setError(e.message); } }}>Add</button>
              </div>
            </div>
            <div className="rounded-lg bg-slate-50 p-3">
              <b className="text-sm">Ads / creatives</b>
              {(campaign.ads ?? []).map((a: any) => <div key={a.id} className="text-sm">{a.name} <span className="text-xs text-slate-400">{a.creative_reference ?? ''}</span></div>)}
              <div className="mt-2 flex gap-2">
                <input className={input} placeholder="New ad name" value={adName} onChange={e => setAdName(e.target.value)} />
                <button className="rounded bg-slate-900 px-2 py-1 text-xs text-white" onClick={async () => { if (!adName) return; try { await campaignAddAd(orgId, campaign.id, { name: adName }); setAdName(''); refresh(); } catch (e: any) { setError(e.message); } }}>Add</button>
              </div>
            </div>
          </div>
        )}

        {can('campaigns.edit') && (
          <div className="mt-3 flex gap-2 text-sm">
            <select className={input} value={campaign.status} onChange={async e => { try { await campaignUpdate(orgId, campaign.id, { status: e.target.value }); refresh(); } catch (err: any) { setError(err.message); } }}>
              <option value="draft">draft</option><option value="active">active</option><option value="paused">paused</option><option value="completed">completed</option>
            </select>
            {can('campaigns.delete') && <button className="rounded border border-red-300 px-3 py-1.5 text-red-700" onClick={async () => { if (window.confirm('Delete this campaign and its spend records?')) { try { await campaignDelete(orgId, campaign.id); onClose(); } catch (e: any) { setError(e.message); } } }}>Delete campaign</button>}
          </div>
        )}
      </div>
    </div>
  );
}

function AnalyticsView({ orgId, orgType, can, setError }: { orgId: number; orgType: string; can: (p: string) => boolean; setError: (s: string) => void }) {
  const [range, setRange] = useState('last30');
  const [overview, setOverview] = useState<any>(null);
  const [agency, setAgency] = useState<any>(null);
  const qs = range === 'last30' ? '' : `?range=${range}`;
  const load = () => {
    analyticsOverview(orgId, qs).then(setOverview).catch(e => setError(e.message));
    if (orgType === 'agency') analyticsAgency(orgId, qs).then(setAgency).catch(() => setAgency(null));
  };
  useEffect(() => { load(); }, [orgId, range]);

  const reports: [string, string][] = [['leads', 'Leads'], ['campaign-performance', 'Campaign performance'], ['funnel', 'Funnel'], ['agent-performance', 'Agent performance'], ['publishing-history', 'Publishing history']];
  return (
    <div>
      <h2 className="text-2xl font-bold">Analytics</h2>
      <div className="mt-3 flex gap-2 text-sm">
        {[['last30', 'Last 30 days'], ['today', 'Today'], ['week', 'This week'], ['month', 'This month']].map(([r, label]) => (
          <button key={r} onClick={() => setRange(r)} className={`rounded px-3 py-1.5 ${range === r ? 'bg-slate-900 text-white' : 'bg-white shadow-sm'}`}>{label}</button>
        ))}
        <span className="ml-2 self-center text-xs text-slate-400">{overview ? `${overview.range.from} → ${overview.range.to}` : ''}</span>
      </div>

      {overview?.previous_period && (
        <div className="mt-3 flex flex-wrap gap-x-5 gap-y-1 rounded-xl bg-white px-4 py-2 text-sm shadow-sm">
          <span className="text-slate-500">vs previous period ({overview.previous_period.range.from} → {overview.previous_period.range.to}):</span>
          {([['Leads', 'leads'], ['Qualified', 'qualified'], ['Won', 'won'], ['Spend', 'spend']] as const).map(([label, key]) => {
            const d = overview.previous_period.deltas_percent[key];
            return <span key={key}>{label} <b className={d === null ? '' : d >= 0 ? 'text-emerald-600' : 'text-red-600'}>{d === null ? 'n/a' : `${d > 0 ? '+' : ''}${d}%`}</b></span>;
          })}
        </div>
      )}
      {overview && (
        <>
          <div className="mt-4 grid grid-cols-2 gap-3 md:grid-cols-4">
            {[['Leads received', overview.leads_received], ['Qualified', `${overview.qualified_leads} (${overview.qualified_rate_percent ?? '—'}%)`],
              ['Converted', `${overview.won_leads} (${overview.conversion_rate_percent ?? '—'}%)`], ['Campaign spend', fmtMoney(overview.campaign_spend)],
              ['Cost per lead', fmtMoney(overview.cost_per_lead)], ['Posts published', overview.posts_published],
              ['Posts failed', overview.posts_failed], ['Follow-ups overdue', overview.follow_ups_overdue],
            ].map(([k, v]) => (
              <div key={k as string} className="rounded-xl bg-white p-4 shadow-sm"><div className="text-2xl font-bold">{v as any}</div><div className="text-xs text-slate-500">{k}</div></div>
            ))}
          </div>

          <div className="mt-4 grid gap-4 lg:grid-cols-2">
            <div className="rounded-xl bg-white p-4 shadow-sm">
              <h3 className="font-semibold">Conversion funnel</h3>
              <div className="mt-2 flex flex-col gap-1">
                {overview.funnel.map((f: any, i: number) => {
                  const max = Math.max(1, ...overview.funnel.map((x: any) => x.count));
                  return (
                    <div key={f.slug} className="flex items-center gap-2 text-sm">
                      <span className="w-36 text-xs">{i + 1}. {f.stage}</span>
                      <span className="h-4 rounded bg-emerald-500" style={{ width: `${Math.max(2, (f.count / max) * 100)}%` }} />
                      <b>{f.count}</b>
                    </div>
                  );
                })}
              </div>
              <h3 className="mt-4 font-semibold">Leads by source</h3>
              {overview.leads_by_source.map((s: any) => <div key={s.source} className="flex justify-between text-sm"><span>{s.source}</span><b>{s.count}</b></div>)}
              <h3 className="mt-4 font-semibold">Leads by city</h3>
              {overview.leads_by_city.map((s: any) => <div key={s.city} className="flex justify-between text-sm"><span>{s.city}</span><b>{s.count}</b></div>)}
            </div>
            <div className="flex flex-col gap-4">
              <div className="rounded-xl bg-white p-4 shadow-sm">
                <h3 className="font-semibold">Campaign performance</h3>
                {overview.leads_by_campaign.length === 0 && <p className="text-sm text-slate-400">No campaign activity in this range.</p>}
                {overview.leads_by_campaign.map((c: any) => (
                  <div key={c.campaign_id} className="mt-1 flex flex-wrap justify-between gap-2 border-b py-1 text-sm last:border-0">
                    <span>{c.name}</span>
                    <span className="text-xs text-slate-500">Spend {fmtMoney(c.spend)} · Leads {c.leads} · CPL {fmtMoney(c.cost_per_lead)} · Conv {c.conversion_rate_percent ?? '—'}%</span>
                  </div>
                ))}
                <h3 className="mt-4 font-semibold">Agent performance</h3>
                {overview.agent_performance.map((a: any) => <div key={a.agent} className="flex justify-between text-sm"><span>{a.agent}</span><span className="text-xs text-slate-500">{a.leads} leads · {a.won} won</span></div>)}
                <h3 className="mt-4 font-semibold">Social accounts</h3>
                {overview.social_account_health.map((s: any) => <div key={s.status} className="flex justify-between text-sm"><span>{s.status}</span><b>{s.count}</b></div>)}
                <p className="mt-1 text-[11px] text-slate-400">Post engagement metrics: {overview.social_engagement === null ? 'not provided by the platform yet (shown as missing, not zero)' : ''}</p>
              </div>
              {can('analytics.export') && (
                <div className="rounded-xl bg-white p-4 shadow-sm">
                  <h3 className="font-semibold">Export reports</h3>
                  <p className="text-[11px] text-slate-400">Exports use the selected range and are recorded in the audit log.</p>
                  <div className="mt-2 flex flex-col gap-1.5">
                    {reports.map(([report, label]) => (
                      <div key={report} className="flex items-center gap-2 text-sm">
                        <span className="w-44">{label}</span>
                        {['csv', 'xlsx', 'pdf'].map(f => (
                          <button key={f} className="rounded border px-2 py-0.5 text-xs uppercase" onClick={async () => { try { await analyticsExportDownload(orgId, report, f, qs); } catch (e: any) { setError(e.message); } }}>{f}</button>
                        ))}
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>
          </div>
        </>
      )}

      {agency && (
        <div className="mt-4 rounded-xl bg-white p-4 shadow-sm">
          <h3 className="font-semibold">Agency overview — all clients</h3>
          <div className="mt-1 text-sm text-slate-500">{agency.total_clients} clients ({agency.active_clients} active) · {agency.connected_social_accounts} connected social accounts · {agency.totals.leads} leads · {agency.totals.qualified} qualified · spend {fmtMoney(agency.totals.spend)} · CPL {fmtMoney(agency.totals.cost_per_lead)}</div>
          <table className="mt-2 w-full text-left text-sm">
            <thead><tr className="border-b text-xs text-slate-500"><th className="p-1">Client</th><th className="p-1">Leads</th><th className="p-1">Qualified</th><th className="p-1">Spend</th><th className="p-1">CPL</th><th className="p-1">Posts</th><th className="p-1">Overdue follow-ups</th></tr></thead>
            <tbody>{agency.clients.map((c: any) => (
              <tr key={c.client} className="border-b last:border-0"><td className="p-1 font-medium">{c.client}</td><td className="p-1">{c.leads}</td><td className="p-1">{c.qualified}</td><td className="p-1">{fmtMoney(c.spend)}</td><td className="p-1">{fmtMoney(c.cost_per_lead)}</td><td className="p-1">{c.posts_published}</td><td className="p-1">{c.follow_ups_overdue}</td></tr>
            ))}</tbody>
          </table>
          <h4 className="mt-3 text-sm font-semibold">Team workload (open tasks)</h4>
          {agency.team_workload.map((w: any) => <div key={w.member} className="flex justify-between text-sm"><span>{w.member}</span><b>{w.open_tasks}</b></div>)}
        </div>
      )}
    </div>
  );
}

// ---------------- Phase 7: AI assistant + Billing ----------------

function AiAssistantPanel({ orgId, setError, onUse }: {
  orgId: number; setError: (s: string) => void;
  onUse: (caption: string, hashtags: string, cta: string) => void;
}) {
  const [open, setOpen] = useState(false);
  const [prop, setProp] = useState({ title: '', property_type: '', city: '', area: '', price: '', bedrooms: '', features: '' });
  const [language, setLanguage] = useState('en');
  const [platform, setPlatform] = useState('facebook');
  const [busy, setBusy] = useState(false);
  const [suggestions, setSuggestions] = useState<{ caption: string; hashtags: string[]; cta: string }[]>([]);
  const input = 'w-full rounded border px-2 py-1.5 text-sm';
  if (!open) {
    return (
      <div className="md:col-span-2">
        <button type="button" className="rounded border border-violet-300 bg-violet-50 px-3 py-1.5 text-sm text-violet-800" onClick={() => setOpen(true)}>
          ✨ AI caption assistant
        </button>
        <span className="ml-2 text-xs text-slate-400">Drafts are suggestions for your review — nothing AI-written is ever published automatically.</span>
      </div>
    );
  }
  return (
    <div className="rounded-lg border border-violet-200 bg-violet-50 p-3 md:col-span-2">
      <div className="flex items-center justify-between">
        <b className="text-sm">AI caption assistant</b>
        <button type="button" className="text-xs text-slate-500" onClick={() => setOpen(false)}>Close</button>
      </div>
      <p className="text-[11px] text-slate-500">Only the facts you enter are used — the assistant is instructed never to invent prices, features, approvals or availability.</p>
      <div className="mt-2 grid gap-2 md:grid-cols-3">
        <label className="text-xs">Property title*<input className={input} value={prop.title} onChange={e => setProp({ ...prop, title: e.target.value })} placeholder="10 Marla House for Sale" /></label>
        <label className="text-xs">Type<input className={input} value={prop.property_type} onChange={e => setProp({ ...prop, property_type: e.target.value })} placeholder="House / Apartment / Plot" /></label>
        <label className="text-xs">Price (exact text)<input className={input} value={prop.price} onChange={e => setProp({ ...prop, price: e.target.value })} placeholder="PKR 4.5 Crore" /></label>
        <label className="text-xs">City<input className={input} value={prop.city} onChange={e => setProp({ ...prop, city: e.target.value })} /></label>
        <label className="text-xs">Area / society<input className={input} value={prop.area} onChange={e => setProp({ ...prop, area: e.target.value })} /></label>
        <label className="text-xs">Bedrooms<input className={input} value={prop.bedrooms} onChange={e => setProp({ ...prop, bedrooms: e.target.value })} /></label>
        <label className="text-xs md:col-span-3">Features (only real ones)<input className={input} value={prop.features} onChange={e => setProp({ ...prop, features: e.target.value })} placeholder="Near park, west open, …" /></label>
        <label className="text-xs">Platform<select className={input} value={platform} onChange={e => setPlatform(e.target.value)}><option value="facebook">Facebook</option><option value="instagram">Instagram</option><option value="linkedin">LinkedIn</option></select></label>
        <label className="text-xs">Language<select className={input} value={language} onChange={e => setLanguage(e.target.value)}><option value="en">English</option><option value="ur">Urdu</option></select></label>
        <div className="flex items-end">
          <button type="button" disabled={busy || !prop.title} className="rounded bg-violet-600 px-3 py-1.5 text-sm text-white disabled:opacity-50" onClick={async () => {
            setBusy(true);
            try { const r = await aiSuggest(orgId, { platform, language, property: prop }); setSuggestions(r.suggestions); }
            catch (e: any) { setError(e.message); }
            setBusy(false);
          }}>{busy ? 'Generating…' : suggestions.length ? 'Regenerate' : 'Generate captions'}</button>
        </div>
      </div>
      {suggestions.map((s, i) => (
        <div key={i} className="mt-2 rounded bg-white p-3 shadow-sm">
          <div className="flex items-center justify-between">
            <span className="rounded bg-violet-100 px-2 py-0.5 text-[10px] font-semibold text-violet-800">AI-GENERATED — REVIEW BEFORE USE</span>
            <button type="button" className="text-xs text-emerald-700 underline" onClick={() => onUse(s.caption, (s.hashtags ?? []).join(' '), s.cta)}>Use this caption</button>
          </div>
          <p className="mt-1 whitespace-pre-wrap text-sm">{s.caption}</p>
          <p className="text-xs text-slate-500">{(s.hashtags ?? []).join(' ')}{s.cta ? ` · CTA: ${s.cta}` : ''}</p>
        </div>
      ))}
    </div>
  );
}

function BillingView({ orgId, orgType, can, setError, clientOptions }: {
  orgId: number; orgType: string; can: (p: string) => boolean; setError: (s: string) => void;
  clientOptions: { id: number; business_name: string }[];
}) {
  const [overview, setOverview] = useState<any>(null);
  const [packages, setPackages] = useState<any[]>([]);
  const [subscriptions, setSubscriptions] = useState<any[]>([]);
  const [pkgForm, setPkgForm] = useState({ name: '', price: '', max_leads_per_month: '', max_social_accounts: '', max_posts_per_month: '', max_users: '' });
  const [assign, setAssign] = useState({ client_organization_id: '', service_package_id: '' });
  const clients = clientOptions;
  const [invoiceAmount, setInvoiceAmount] = useState('');
  const input = 'rounded border px-2 py-1.5 text-sm';

  const load = () => {
    billingOverview(orgId).then(setOverview).catch(e => setError(e.message));
    if (orgType === 'agency') {
      billingPackages(orgId).then(setPackages).catch(() => undefined);
      billingSubscriptions(orgId).then(setSubscriptions).catch(() => undefined);
    }
  };
  useEffect(() => { load(); }, [orgId]);

  const usageRow = (label: string, key: string) => {
    const used = overview?.usage?.[key]; const limit = overview?.limits?.[key];
    return (
      <div key={key} className="flex items-center justify-between gap-3 text-sm">
        <span>{label}</span>
        <span className="flex items-center gap-2">
          <span className="h-2 w-32 overflow-hidden rounded bg-slate-200">
            <span className="block h-full bg-emerald-500" style={{ width: limit ? `${Math.min(100, (used / limit) * 100)}%` : '0%' }} />
          </span>
          <b>{used}</b><span className="text-slate-400">/ {limit ?? '∞'}</span>
        </span>
      </div>
    );
  };

  return (
    <div>
      <h2 className="text-2xl font-bold">Billing & Packages</h2>

      <div className="mt-3 grid gap-4 lg:grid-cols-2">
        <div className="rounded-xl bg-white p-4 shadow-sm">
          <h3 className="font-semibold">Current plan {overview?.subscription ? `— ${overview.subscription.package?.name}` : ''}</h3>
          {overview?.subscription ? (
            <p className="text-sm text-slate-500">
              Status: <b>{overview.subscription.status}</b> · {overview.subscription.package?.currency} {overview.subscription.package?.price}/month
              {overview.subscription.ends_at ? ` · period ends ${String(overview.subscription.ends_at).slice(0, 10)}` : ''}
            </p>
          ) : <p className="text-sm text-slate-500">No package assigned — this workspace is unlimited.</p>}
          <div className="mt-3 flex flex-col gap-2">
            {usageRow('Team members', 'max_users')}
            {usageRow('Connected social accounts', 'max_social_accounts')}
            {usageRow('Leads this month', 'max_leads_per_month')}
            {usageRow('Posts this month', 'max_posts_per_month')}
          </div>
          <p className="mt-2 text-[11px] text-slate-400">Limits are enforced only where the package sets them; blank = unlimited.</p>
        </div>

        <div className="rounded-xl bg-white p-4 shadow-sm">
          <h3 className="font-semibold">Invoices</h3>
          {(overview?.invoices ?? []).length === 0 && <p className="text-sm text-slate-400">No invoices yet.</p>}
          {(overview?.invoices ?? []).map((inv: any) => (
            <div key={inv.id} className="flex items-center justify-between border-b py-1.5 text-sm last:border-0">
              <span>{inv.number} · {inv.currency} {Number(inv.amount).toLocaleString('en-PK')} <span className="text-xs text-slate-400">due {inv.due_date ?? '—'}</span></span>
              <span className="flex items-center gap-2">
                <span className={`rounded px-2 py-0.5 text-xs ${inv.status === 'paid' ? 'bg-emerald-100 text-emerald-800' : inv.status === 'overdue' ? 'bg-red-100 text-red-800' : 'bg-slate-100'}`}>{inv.status}</span>
                {can('billing.manage') && inv.status !== 'paid' && inv.status !== 'void' && (
                  <button className="text-xs text-emerald-700 underline" onClick={async () => {
                    const method = window.prompt('Payment method (bank_transfer, jazzcash, easypaisa, cash, card)', 'bank_transfer') ?? 'bank_transfer';
                    try { await billingInvoiceUpdate(orgId, inv.id, { status: 'paid', payment_method: method }); load(); } catch (e: any) { setError(e.message); }
                  }}>Mark paid</button>
                )}
              </span>
            </div>
          ))}
          {can('billing.manage') && (
            <div className="mt-2 flex gap-2">
              <input className={input} type="number" placeholder="Amount (PKR)" value={invoiceAmount} onChange={e => setInvoiceAmount(e.target.value)} />
              <button className="rounded bg-slate-900 px-3 py-1.5 text-sm text-white" onClick={async () => {
                if (!invoiceAmount) return;
                try { await billingInvoiceCreate(orgId, { amount: Number(invoiceAmount) }); setInvoiceAmount(''); load(); } catch (e: any) { setError(e.message); }
              }}>Issue invoice</button>
            </div>
          )}
          <p className="text-[11px] text-slate-400">Payments are recorded manually (bank / JazzCash / EasyPaisa / cash) — no card gateway is connected.</p>
        </div>
      </div>

      {orgType === 'agency' && can('billing.manage') && (
        <>
          <div className="mt-4 rounded-xl bg-white p-4 shadow-sm">
            <h3 className="font-semibold">Service packages</h3>
            <div className="mt-2 grid gap-2 md:grid-cols-3">
              {packages.map(p => (
                <div key={p.id} className="rounded-lg bg-slate-50 p-3 text-sm">
                  <b>{p.name}</b> — {p.currency} {Number(p.price).toLocaleString('en-PK')}/mo
                  <div className="text-xs text-slate-500">
                    {Object.entries(p.limits ?? {}).filter(([, v]) => v !== null && v !== '').map(([k, v]) => <div key={k}>{k.replaceAll('_', ' ')}: {String(v)}</div>)}
                  </div>
                  <button className="mt-1 text-xs text-red-600 underline" onClick={async () => { if (window.confirm('Delete this package? Existing subscriptions keep their record.')) { try { await billingPackageDelete(orgId, p.id); load(); } catch (e: any) { setError(e.message); } } }}>Delete</button>
                </div>
              ))}
            </div>
            <form className="mt-3 flex flex-wrap items-end gap-2" onSubmit={async e => {
              e.preventDefault();
              const limits: any = {};
              (['max_leads_per_month', 'max_social_accounts', 'max_posts_per_month', 'max_users'] as const).forEach(k => { if ((pkgForm as any)[k] !== '') limits[k] = Number((pkgForm as any)[k]); });
              try { await billingPackageCreate(orgId, { name: pkgForm.name, price: pkgForm.price ? Number(pkgForm.price) : 0, limits }); setPkgForm({ name: '', price: '', max_leads_per_month: '', max_social_accounts: '', max_posts_per_month: '', max_users: '' }); load(); }
              catch (err: any) { setError(err.message); }
            }}>
              <label className="text-xs">Package name<input required className={`${input} block`} value={pkgForm.name} onChange={e => setPkgForm({ ...pkgForm, name: e.target.value })} /></label>
              <label className="text-xs">Monthly price<input className={`${input} block`} type="number" value={pkgForm.price} onChange={e => setPkgForm({ ...pkgForm, price: e.target.value })} /></label>
              <label className="text-xs">Leads / month<input className={`${input} block`} type="number" value={pkgForm.max_leads_per_month} onChange={e => setPkgForm({ ...pkgForm, max_leads_per_month: e.target.value })} /></label>
              <label className="text-xs">Social accounts<input className={`${input} block`} type="number" value={pkgForm.max_social_accounts} onChange={e => setPkgForm({ ...pkgForm, max_social_accounts: e.target.value })} /></label>
              <label className="text-xs">Posts / month<input className={`${input} block`} type="number" value={pkgForm.max_posts_per_month} onChange={e => setPkgForm({ ...pkgForm, max_posts_per_month: e.target.value })} /></label>
              <label className="text-xs">Users<input className={`${input} block`} type="number" value={pkgForm.max_users} onChange={e => setPkgForm({ ...pkgForm, max_users: e.target.value })} /></label>
              <button className="rounded bg-emerald-600 px-3 py-1.5 text-sm text-white">Create package</button>
              <span className="w-full text-[11px] text-slate-400">Leave a limit blank for unlimited.</span>
            </form>
          </div>

          <div className="mt-4 rounded-xl bg-white p-4 shadow-sm">
            <h3 className="font-semibold">Client subscriptions</h3>
            <div className="mt-2 flex flex-wrap items-end gap-2">
              <label className="text-xs">Client<select className={`${input} block`} value={assign.client_organization_id} onChange={e => setAssign({ ...assign, client_organization_id: e.target.value })}>
                <option value="">Select client…</option>
                {clients.map(c => <option key={c.id} value={c.id}>{c.business_name}</option>)}
              </select></label>
              <label className="text-xs">Package<select className={`${input} block`} value={assign.service_package_id} onChange={e => setAssign({ ...assign, service_package_id: e.target.value })}>
                <option value="">Select package…</option>
                {packages.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}
              </select></label>
              <button className="rounded bg-slate-900 px-3 py-1.5 text-sm text-white" disabled={!assign.client_organization_id || !assign.service_package_id} onClick={async () => {
                try { await billingSubscribe(orgId, { client_organization_id: Number(assign.client_organization_id), service_package_id: Number(assign.service_package_id), status: 'active' }); load(); }
                catch (e: any) { setError(e.message); }
              }}>Assign (1 month)</button>
            </div>
            <table className="mt-3 w-full text-left text-sm">
              <thead><tr className="border-b text-xs text-slate-500"><th className="p-1">Client</th><th className="p-1">Package</th><th className="p-1">Status</th><th className="p-1">Period ends</th><th className="p-1"></th></tr></thead>
              <tbody>{subscriptions.map(s => (
                <tr key={s.id} className="border-b last:border-0">
                  <td className="p-1">{s.organization?.business_name ?? s.organization_id}</td>
                  <td className="p-1">{s.package?.name}</td>
                  <td className="p-1">{s.status}</td>
                  <td className="p-1">{s.ends_at ? String(s.ends_at).slice(0, 10) : '—'}</td>
                  <td className="p-1">{s.status !== 'cancelled' && <button className="text-xs text-red-600 underline" onClick={async () => { try { await billingSubscriptionStatus(orgId, s.id, 'cancelled'); load(); } catch (e: any) { setError(e.message); } }}>Cancel</button>}</td>
                </tr>
              ))}</tbody>
            </table>
          </div>
        </>
      )}
    </div>
  );
}
