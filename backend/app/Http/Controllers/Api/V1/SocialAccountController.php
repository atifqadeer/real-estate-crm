<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\SocialAccountLog;
use App\Models\SocialPlatform;
use App\Services\Meta\MetaApiException;
use App\Services\Meta\MetaConnectionService;
use Illuminate\Http\Request;

class SocialAccountController extends Controller
{
    /** Platform registry: what exists, what is connectable, and why not. */
    public function platforms(Request $request)
    {
        abort_unless($request->user()->can('social.view'), 403);
        return response()->json(['data' => SocialPlatform::orderBy('id')->get()]);
    }

    public function index(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('social.view'), 403);
        $accounts = SocialAccount::query()
            ->where('organization_id', $organization->id)
            ->with(['platform', 'token', 'permissions', 'connectedBy:id,name'])
            ->orderBy('display_name')
            ->get()
            ->map(fn (SocialAccount $a) => $this->payload($a));
        return response()->json(['data' => $accounts]);
    }

    public function show(Request $request, Organization $organization, SocialAccount $account)
    {
        $this->guardAccount($organization, $account);
        abort_unless($request->user()->can('social.view'), 403);
        $account->load(['platform', 'token', 'permissions', 'connectedBy:id,name']);
        return response()->json(['data' => $this->payload($account)]);
    }

    /** Start (or restart) the Meta OAuth flow. Returns the dialog URL for the SPA to open. */
    public function connect(Request $request, Organization $organization, string $platform, MetaConnectionService $connections)
    {
        abort_unless($request->user()->can('social.connect'), 403);
        abort_unless(in_array($platform, ['facebook', 'instagram', 'linkedin'], true), 404, 'This platform has no shipped adapter yet.');
        app(\App\Services\Billing\PlanLimitService::class)->assertCan($organization, 'max_social_accounts');
        try {
            $url = $platform === 'linkedin'
                ? app(\App\Services\LinkedIn\LinkedInConnectionService::class)->startAuthorization($organization, $request->user())
                : $connections->startAuthorization($organization, $request->user(), $request->boolean('rerequest'));
        } catch (MetaApiException | \App\Services\LinkedIn\LinkedInApiException $e) {
            return response()->json(['error' => ['code' => 'provider_not_configured', 'message' => $e->getMessage()]], 503);
        }
        return response()->json(['data' => ['url' => $url]]);
    }

    /** LinkedIn OAuth callback (public, one-time state in cache). */
    public function linkedInCallback(Request $request)
    {
        $frontend = rtrim((string) config('services.linkedin.frontend_url'), '/');
        if ($request->filled('error')) {
            return redirect()->away($frontend . '/?linkedin=denied&message=' . urlencode((string) $request->input('error_description', 'Authorization was declined.')));
        }
        try {
            $result = app(\App\Services\LinkedIn\LinkedInConnectionService::class)
                ->handleCallback((string) $request->input('code'), (string) $request->input('state'));
        } catch (\Throwable $e) {
            return redirect()->away($frontend . '/?linkedin=error&message=' . urlencode($e->getMessage()));
        }
        return redirect()->away($frontend . '/?linkedin=connected&accounts=' . $result['accounts']);
    }

    /**
     * Meta OAuth callback (public route — the browser returns here without
     * our auth header). Identity comes from the one-time state in cache.
     * Always ends in a redirect back to the SPA; no tokens in any URL.
     */
    public function callback(Request $request, MetaConnectionService $connections)
    {
        $frontend = rtrim((string) config('services.meta.frontend_url'), '/');
        if ($request->filled('error')) {
            return redirect()->away($frontend . '/?meta=denied&message=' . urlencode((string) $request->input('error_description', 'Authorization was declined.')));
        }
        try {
            $result = $connections->handleCallback((string) $request->input('code'), (string) $request->input('state'));
        } catch (MetaApiException $e) {
            return redirect()->away($frontend . '/?meta=error&message=' . urlencode($e->getMessage()));
        } catch (\Throwable) {
            return redirect()->away($frontend . '/?meta=error&message=' . urlencode('Connection failed unexpectedly. Please try again.'));
        }
        return redirect()->away($frontend . '/?meta=connected&accounts=' . $result['accounts']);
    }

    public function test(Request $request, Organization $organization, SocialAccount $account, MetaConnectionService $connections)
    {
        $this->guardAccount($organization, $account);
        abort_unless($request->user()->can('social.test'), 403);
        if ($account->platform->key === 'linkedin') {
            try {
                app(\App\Services\LinkedIn\LinkedInConnectionService::class)->testAccount($account);
                $account->update(['status' => 'connected', 'last_error' => null, 'last_synced_at' => now()]);
            } catch (\App\Services\LinkedIn\LinkedInApiException $e) {
                $account->update([
                    'status' => $e->status === 401 ? 'expired' : 'error',
                    'last_error' => $e->getMessage(),
                ]);
            }
            $account->load(['platform', 'token', 'permissions', 'connectedBy:id,name']);
            return response()->json(['data' => $this->payload($account)]);
        }
        $account = $connections->testAccount($account, $request->user());
        if ($account->status === 'expired') {
            $this->notifyAdmins($organization, 'social.token_expired',
                "Social account token expired: {$account->display_name}",
                'Reconnect the account to resume publishing and lead retrieval.');
        }
        $account->load(['platform', 'token', 'permissions', 'connectedBy:id,name']);
        return response()->json(['data' => $this->payload($account)]);
    }

    public function disconnect(Request $request, Organization $organization, SocialAccount $account, MetaConnectionService $connections)
    {
        $this->guardAccount($organization, $account);
        abort_unless($request->user()->can('social.disconnect'), 403);
        $connections->disconnect($account, $request->user());
        $this->notifyAdmins($organization, 'social.disconnected',
            "Social account disconnected: {$account->display_name}",
            'Publishing and lead retrieval for this account have stopped.');
        return response()->json(['data' => ['message' => 'Account disconnected. Its stored token was deleted.']]);
    }

    private function notifyAdmins(Organization $organization, string $type, string $title, ?string $body): void
    {
        $service = app(\App\Services\Notifications\NotificationService::class);
        $admins = $service->usersWithRoles($organization, ['client_owner', 'client_admin', 'agency_account_manager']);
        $service->notify($organization, $admins, $type, $title, $body);
    }

    /** Install the app on this Page for leadgen webhook events (Phase 4). */
    public function subscribeLeads(Request $request, Organization $organization, SocialAccount $account, \App\Services\Meta\MetaGraphClient $graph)
    {
        $this->guardAccount($organization, $account);
        abort_unless($request->user()->can('social.connect'), 403);
        abort_unless($account->platform->key === 'facebook', 422, 'Lead subscriptions apply to Facebook Pages.');
        $token = $account->token?->access_token;
        if (!$token) {
            return response()->json(['error' => ['code' => 'no_token', 'message' => 'Page has no stored token; reconnect it first.']], 422);
        }
        try {
            $graph->subscribePageToLeadgen($account->external_id, $token);
        } catch (MetaApiException $e) {
            return response()->json(['error' => ['code' => 'subscribe_failed', 'message' => $e->getMessage()]], 502);
        }
        \App\Models\SocialAccountLog::create([
            'organization_id' => $organization->id, 'social_account_id' => $account->id,
            'user_id' => $request->user()->id, 'event' => 'leads_subscribed',
            'message' => 'App installed on Page for leadgen webhook events.',
        ]);
        return response()->json(['data' => ['message' => 'Subscribed: new Meta Lead Ads leads will arrive by webhook.']]);
    }

    public function logs(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('social.view'), 403);
        $logs = SocialAccountLog::where('organization_id', $organization->id)
            ->with(['account:id,display_name', 'user:id,name'])
            ->latest('created_at')->paginate(25);
        return response()->json($logs);
    }

    /** Cross-tenant account ids must look exactly like missing records. */
    private function guardAccount(Organization $organization, SocialAccount $account): void
    {
        abort_unless($account->organization_id === $organization->id, 404);
    }

    /** API payload — deliberately contains NO token material, ever. */
    private function payload(SocialAccount $a): array
    {
        return [
            'id' => $a->id,
            'platform' => $a->platform->key,
            'external_id' => $a->external_id,
            'username' => $a->username,
            'display_name' => $a->display_name,
            'profile_picture_url' => $a->profile_picture_url,
            'status' => $a->status,
            'connected_by' => $a->connectedBy?->name,
            'last_synced_at' => $a->last_synced_at,
            'last_error' => $a->last_error,
            'metadata' => $a->metadata,
            'permissions' => $a->permissions->map(fn ($p) => ['permission' => $p->permission, 'status' => $p->status])->values(),
            'token' => $a->token ? [
                'type' => $a->token->token_type,
                'expires_at' => $a->token->token_expires_at, // null = provider reports no expiry
                'scopes' => $a->token->scopes,
            ] : null,
        ];
    }
}
