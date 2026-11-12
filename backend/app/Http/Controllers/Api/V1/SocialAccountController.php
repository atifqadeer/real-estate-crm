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
        abort_unless(in_array($platform, ['facebook', 'instagram'], true), 404, 'This platform has no shipped adapter yet.');
        try {
            $url = $connections->startAuthorization($organization, $request->user(), $request->boolean('rerequest'));
        } catch (MetaApiException $e) {
            return response()->json(['error' => ['code' => 'meta_not_configured', 'message' => $e->getMessage()]], 503);
        }
        return response()->json(['data' => ['url' => $url]]);
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
        $account = $connections->testAccount($account, $request->user());
        $account->load(['platform', 'token', 'permissions', 'connectedBy:id,name']);
        return response()->json(['data' => $this->payload($account)]);
    }

    public function disconnect(Request $request, Organization $organization, SocialAccount $account, MetaConnectionService $connections)
    {
        $this->guardAccount($organization, $account);
        abort_unless($request->user()->can('social.disconnect'), 403);
        $connections->disconnect($account, $request->user());
        return response()->json(['data' => ['message' => 'Account disconnected. Its stored token was deleted.']]);
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
