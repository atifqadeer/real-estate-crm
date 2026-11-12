import { useEffect, useState } from 'react';
import {
  login, me, dashboard, members, auditLogs, getSettings, saveSettings,
  inviteMember, createOrganization, setToken, hasToken,
  socialPlatforms, socialAccounts, socialConnect, socialTest, socialDisconnect,
  type Me, type Dashboard, type SocialPlatform, type SocialAccount,
} from './api';

// Shell rule (prompt): only modules that exist are shown — no fake buttons.
// Leads / Campaigns nav appears when those phases ship.
type View = 'dashboard' | 'clients' | 'social' | 'members' | 'settings' | 'audit';

const NAV: { id: View; label: string; permission: string }[] = [
  { id: 'dashboard', label: 'Dashboard', permission: 'dashboard.view' },
  { id: 'clients', label: 'Clients / Organizations', permission: 'organizations.view' },
  { id: 'social', label: 'Connected Accounts', permission: 'social.view' },
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
