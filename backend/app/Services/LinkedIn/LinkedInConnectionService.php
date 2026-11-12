<?php
namespace App\Services\LinkedIn;

use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\SocialAccountLog;
use App\Models\SocialOauthIdentity;
use App\Models\SocialPlatform;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * LinkedIn connect flow (Phase 7), mirroring the Meta shape:
 * one-time OAuth state in cache, identity + per-account tokens
 * encrypted at rest, tokens never returned by the API.
 * Pages are discovered via organizationAcls filtered to ADMINISTRATOR
 * (LinkedIn's Community Management integration rule for posting).
 */
class LinkedInConnectionService
{
    public function __construct(private LinkedInClient $client) {}

    public function startAuthorization(Organization $organization, User $user): string
    {
        if (!$this->client->configured()) {
            throw new LinkedInApiException('LinkedIn is not configured on this server. Set LINKEDIN_CLIENT_ID, LINKEDIN_CLIENT_SECRET and LINKEDIN_REDIRECT_URI in .env, and make sure the LinkedIn app has the Community Management API product approved (Development tier or higher).');
        }
        $state = Str::random(40);
        Cache::put("linkedin_oauth_state:{$state}", ['organization_id' => $organization->id, 'user_id' => $user->id], now()->addMinutes(15));
        return $this->client->authorizeUrl($state);
    }

    /** @return array{accounts:int} */
    public function handleCallback(string $code, string $state): array
    {
        $context = Cache::pull("linkedin_oauth_state:{$state}");
        if (!$context) throw new LinkedInApiException('This LinkedIn sign-in link has expired or was already used. Start the connection again.');
        $organization = Organization::findOrFail($context['organization_id']);
        $user = User::find($context['user_id']);

        $token = $this->client->exchangeCode($code);
        $profile = $this->client->userinfo($token['access_token']);
        $externalUserId = (string) ($profile['sub'] ?? '');
        if ($externalUserId === '') throw new LinkedInApiException('LinkedIn did not return a member identity.');

        $identity = SocialOauthIdentity::updateOrCreate(
            ['organization_id' => $organization->id, 'provider' => 'linkedin', 'external_user_id' => $externalUserId],
            [
                'name' => $profile['name'] ?? null,
                'access_token' => $token['access_token'],
                'token_expires_at' => now()->addSeconds($token['expires_in']),
                'scopes' => $token['scope'] ? explode(' ', $token['scope']) : null,
                'connected_by' => $user?->id,
            ]
        );

        $platform = SocialPlatform::where('key', 'linkedin')->firstOrFail();
        $scopes = $identity->scopes ?? explode(' ', (string) config('services.linkedin.scopes'));
        $organizations = $this->client->administeredOrganizations($token['access_token']);
        $count = 0;
        foreach ($organizations as $org) {
            $account = SocialAccount::withoutTenantScope()->updateOrCreate(
                ['organization_id' => $organization->id, 'social_platform_id' => $platform->id, 'external_id' => $org['id']],
                [
                    'display_name' => $org['name'],
                    'username' => $org['vanity'],
                    'status' => 'connected',
                    'connected_by' => $user?->id,
                    'last_synced_at' => now(),
                    'last_error' => null,
                ]
            );
            $account->token()->updateOrCreate(
                ['social_account_id' => $account->id],
                [
                    'token_type' => 'member_access_token',
                    'access_token' => $token['access_token'],
                    'refresh_token' => $token['refresh_token'],
                    'token_expires_at' => now()->addSeconds($token['expires_in']),
                    'scopes' => $scopes,
                ]
            );
            foreach ($scopes as $scope) {
                $account->permissions()->updateOrCreate(['permission' => $scope], ['status' => 'granted']);
            }
            SocialAccountLog::create([
                'organization_id' => $organization->id, 'social_account_id' => $account->id,
                'user_id' => $user?->id, 'event' => 'connected',
                'message' => 'LinkedIn Company Page connected via member authorization.',
                'metadata' => ['platform' => 'linkedin', 'external_id' => $org['id']],
            ]);
            $count++;
        }
        if ($count === 0) {
            SocialAccountLog::create([
                'organization_id' => $organization->id, 'social_account_id' => null,
                'user_id' => $user?->id, 'event' => 'connected_no_accounts',
                'message' => 'LinkedIn sign-in succeeded but the member is not an ADMINISTRATOR of any Company Page.',
                'metadata' => ['platform' => 'linkedin'],
            ]);
        }
        return ['accounts' => $count];
    }

    /** Lightweight liveness check used by Test Connection. */
    public function testAccount(SocialAccount $account): void
    {
        $token = $account->token?->access_token;
        if (!$token) throw new LinkedInApiException('No stored token for this account. Reconnect it.');
        $this->client->getOrganization($token, $account->external_id);
    }
}
