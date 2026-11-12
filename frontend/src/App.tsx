import { useEffect, useState } from 'react';
import {
  login, me, dashboard, members, auditLogs, getSettings, saveSettings,
  inviteMember, createOrganization, setToken, hasToken,
  socialPlatforms, socialAccounts, socialConnect, socialTest, socialDisconnect,
  mediaList, mediaUpload, mediaDelete, postList, postGet, postCreate, postUpdate, postDelete,
  postDuplicate, postDestinations, postPreviews, postAction, postRetryTarget, postCalendar,
  templateList, templateCreate, templateDelete, templateSeed,
  type Me, type Dashboard, type SocialPlatform, type SocialAccount,
  type MediaAsset, type ContentPost, type Destination, type ContentTemplate,
} from './api';

// Shell rule (prompt): only modules that exist are shown — no fake buttons.
// Leads / Campaigns nav appears when those phases ship.
type View = 'dashboard' | 'clients' | 'social' | 'content' | 'members' | 'settings' | 'audit';

const NAV: { id: View; label: string; permission: string }[] = [
  { id: 'dashboard', label: 'Dashboard', permission: 'dashboard.view' },
  { id: 'clients', label: 'Clients / Organizations', permission: 'organizations.view' },
  { id: 'social', label: 'Connected Accounts', permission: 'social.view' },
  { id: 'content', label: 'Content & Publishing', permission: 'content.view' },
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

  return (
    <div className="flex min-h-screen bg-slate-100 text-slate-900">
      <aside className="w-64 shrink-0 bg-slate-900 text-slate-100 p-4 flex flex-col gap-4">
        <div>
          <div className="text-lg font-bold">Agency CRM</div>
          <div className="text-xs text-slate-400">Real Estate Marketing · Pakistan</div>
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
  return (
    <form onSubmit={async e => { e.preventDefault(); try { await saveSettings(orgId, { lead_assignment_mode: s.lead_assignment_mode, data_retention_days: s.data_retention_days }); } catch (ex: any) { setError(ex.message); } }}>
      <h2 className="text-2xl font-bold">Organization Settings</h2>
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
