<?php
namespace App\Services\Meta;

use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\SocialAccountLog;
use App\Models\SocialOauthIdentity;
use App\Models\SocialPlatform;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Facebook Login connection flow for one organization:
 * dialog URL (state in cache) -> callback -> long-lived user token ->
 * Page discovery -> per-Page tokens + linked Instagram accounts.
 */
class MetaConnectionService
{
    public function __construct(
        private MetaGraphClient $graph,
        private AuditLogger $audit,
    ) {}

    public function startAuthorization(Organization $organization, User $user, bool $rerequest = false): string
    {
        if (!$this->graph->configured()) {
            throw new MetaApiException('Meta app credentials are not configured on the server (META_APP_ID, META_APP_SECRET, META_REDIRECT_URI).');
        }
        $state = Str::random(48);
        Cache::put("meta_oauth_state:{$state}", [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
        ], now()->addMinutes(15));
        return $this->graph->dialogUrl($state, $rerequest);
    }

    /** @return array{organization: Organization, accounts: int} */
    public function handleCallback(string $code, string $state): array
    {
        $context = Cache::pull("meta_oauth_state:{$state}");
        if (!$context) {
            throw new MetaApiException('OAuth state is invalid or expired. Please start the connection again.');
        }
        $organization = Organization::findOrFail($context['organization_id']);
        $user = User::findOrFail($context['user_id']);

        $short = $this->graph->exchangeCode($code);
        $long = $this->graph->exchangeLongLived($short['access_token']);
        $longToken = $long['access_token'];
        $expiresAt = isset($long['expires_in']) ? now()->addSeconds((int) $long['expires_in']) : now()->addDays(60);

        $debug = $this->graph->debugToken($longToken);
        $grantedScopes = $debug['scopes'] ?? [];
        $me = $this->graph->me($longToken);

        $identity = DB::transaction(function () use ($organization, $me, $longToken, $expiresAt, $grantedScopes, $user) {
            return SocialOauthIdentity::withoutTenantScope()->updateOrCreate(
                ['organization_id' => $organization->id, 'provider' => 'meta', 'external_user_id' => (string) $me['id']],
                [
                    'name' => $me['name'] ?? null,
                    'access_token' => $longToken,
                    'token_expires_at' => $expiresAt,
                    'scopes' => $grantedScopes,
                    'connected_by' => $user->id,
                ]
            );
        });

        $accounts = $this->syncAccounts($organization, $identity, $user);
        $this->log($organization, null, $user, 'connected', 'Meta authorization completed; accounts synced.', ['pages_found' => $accounts]);
        $this->audit->log($organization->id, $user->id, 'social.meta_connected', ['accounts_synced' => $accounts]);

        return ['organization' => $organization, 'accounts' => $accounts];
    }

    /** Discover Pages (+ linked Instagram accounts) and upsert them. Returns pages found. */
    public function syncAccounts(Organization $organization, SocialOauthIdentity $identity, ?User $actor = null): int
    {
        $pages = $this->graph->accounts($identity->access_token);
        $facebook = SocialPlatform::where('key', 'facebook')->firstOrFail();
        $instagram = SocialPlatform::where('key', 'instagram')->firstOrFail();
        $requested = collect(explode(',', (string) config('services.meta.scopes')))->map(fn ($s) => trim($s))->filter()->all();
        $granted = $identity->scopes ?? [];
        $declined = array_values(array_diff($requested, $granted));

        foreach ($pages as $page) {
            $pageToken = $page['access_token'] ?? null;
            $fbAccount = $this->upsertAccount($organization, $facebook, (string) $page['id'], [
                'display_name' => $page['name'] ?? 'Facebook Page',
                'username' => $page['username'] ?? null,
                'profile_picture_url' => $page['picture']['data']['url'] ?? null,
                'metadata' => ['source' => 'me/accounts'],
            ], $actor);
            if ($pageToken) {
                $this->storeToken($fbAccount, $pageToken, $granted, null);
            }
            $this->syncPermissions($fbAccount, $granted, $declined);
            $this->markStatus($fbAccount, $declined);

            $ig = $page['instagram_business_account'] ?? null;
            if ($ig && !empty($ig['id'])) {
                $igAccount = $this->upsertAccount($organization, $instagram, (string) $ig['id'], [
                    'display_name' => $ig['username'] ?? 'Instagram account',
                    'username' => $ig['username'] ?? null,
                    'profile_picture_url' => null,
                    'metadata' => ['linked_page_id' => (string) $page['id'], 'linked_page_name' => $page['name'] ?? null],
                ], $actor);
                if ($pageToken) {
                    // Instagram via Facebook Login is called with the Page token.
                    $this->storeToken($igAccount, $pageToken, $granted, null);
                }
                $this->syncPermissions($igAccount, $granted, $declined);
                $this->markStatus($igAccount, $declined);
                // Best-effort profile enrichment; failure must not fail the connection.
                try {
                    $node = $this->graph->node((string) $ig['id'], $pageToken, 'id,username,profile_picture_url');
                    $igAccount->update([
                        'username' => $node['username'] ?? $igAccount->username,
                        'display_name' => $node['username'] ?? $igAccount->display_name,
                        'profile_picture_url' => $node['profile_picture_url'] ?? null,
                    ]);
                } catch (MetaApiException) {
                    // Keep the discovered basics; Test Connection will surface problems.
                }
            }
        }
        return count($pages);
    }

    /** Test one account: validate its stored token and read its node. */
    public function testAccount(SocialAccount $account, ?User $actor = null): SocialAccount
    {
        $token = $account->token;
        if (!$token) {
            $account->update(['status' => 'error', 'last_error' => 'No stored token. Reconnect the account.']);
            $this->log($account->organization, $account, $actor, 'test_failed', 'No stored token.');
            return $account;
        }
        try {
            $debug = $this->graph->debugToken($token->access_token);
            if (empty($debug['is_valid'])) {
                $account->update(['status' => 'expired', 'last_error' => 'Stored token is no longer valid. Reconnect the account.']);
                $this->log($account->organization, $account, $actor, 'test_failed', 'debug_token reported the token invalid.');
                return $account;
            }
            if (isset($debug['app_id']) && (string) $debug['app_id'] !== $this->graph->appId()) {
                $account->update(['status' => 'error', 'last_error' => 'Stored token belongs to a different Meta app.']);
                $this->log($account->organization, $account, $actor, 'test_failed', 'Token app_id mismatch.');
                return $account;
            }
            $fields = $account->platform->key === 'instagram' ? 'id,username' : 'id,name';
            $node = $this->graph->node($account->external_id, $token->access_token, $fields);
            $account->update([
                'status' => 'connected',
                'last_error' => null,
                'last_synced_at' => now(),
                'display_name' => $node['name'] ?? $node['username'] ?? $account->display_name,
                'username' => $node['username'] ?? $account->username,
            ]);
            $this->log($account->organization, $account, $actor, 'test_ok', 'Token valid; node read succeeded.');
        } catch (MetaApiException $e) {
            $status = $e->graphCode === 190 ? 'expired' : 'error'; // 190 = token invalid/expired
            $account->update(['status' => $status, 'last_error' => $e->getMessage()]);
            $this->log($account->organization, $account, $actor, 'test_failed', $e->getMessage(), ['graph_code' => $e->graphCode]);
        }
        return $account->fresh();
    }

    public function disconnect(SocialAccount $account, ?User $actor = null): void
    {
        DB::transaction(function () use ($account) {
            $account->token()?->delete();
            $account->update(['status' => 'disconnected', 'last_error' => null]);
        });
        $this->log($account->organization, $account, $actor, 'disconnected', 'Account disconnected by user.');
        $this->audit->log($account->organization_id, $actor?->id, 'social.account_disconnected', [
            'platform' => $account->platform->key, 'external_id' => $account->external_id,
        ]);
    }

    public function logDenial(Organization $organization, ?User $user, string $description): void
    {
        $this->log($organization, null, $user, 'oauth_denied', $description);
    }

    private function upsertAccount(Organization $organization, SocialPlatform $platform, string $externalId, array $attrs, ?User $actor): SocialAccount
    {
        return SocialAccount::withoutTenantScope()->updateOrCreate(
            ['organization_id' => $organization->id, 'social_platform_id' => $platform->id, 'external_id' => $externalId],
            array_merge($attrs, [
                'status' => 'connected',
                'connected_by' => $actor?->id,
                'last_synced_at' => now(),
                'last_error' => null,
            ])
        );
    }

    private function storeToken(SocialAccount $account, string $plainToken, array $scopes, $expiresAt): void
    {
        $account->token()->updateOrCreate(
            ['social_account_id' => $account->id],
            [
                'token_type' => 'page_access_token',
                'access_token' => $plainToken, // encrypted cast
                'token_expires_at' => $expiresAt, // Page tokens from long-lived user tokens: no expiry
                'scopes' => $scopes,
            ]
        );
    }

    private function syncPermissions(SocialAccount $account, array $granted, array $declined): void
    {
        foreach ($granted as $perm) {
            $account->permissions()->updateOrCreate(['permission' => $perm], ['status' => 'granted']);
        }
        foreach ($declined as $perm) {
            $account->permissions()->updateOrCreate(['permission' => $perm], ['status' => 'declined']);
        }
    }

    private function markStatus(SocialAccount $account, array $declined): void
    {
        // Publishing-critical declines downgrade the account visibly.
        $critical = ['pages_show_list', 'instagram_basic'];
        if (array_intersect($critical, $declined)) {
            $account->update(['status' => 'permission_required', 'last_error' => 'A required permission was declined: ' . implode(', ', array_intersect($critical, $declined))]);
        }
    }

    private function log(Organization $organization, ?SocialAccount $account, ?User $user, string $event, ?string $message = null, array $metadata = []): void
    {
        SocialAccountLog::create([
            'organization_id' => $organization->id,
            'social_account_id' => $account?->id,
            'user_id' => $user?->id,
            'event' => $event,
            'message' => $message,
            'metadata' => $metadata,
        ]);
    }
}
