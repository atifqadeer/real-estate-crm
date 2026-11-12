<?php
namespace App\Jobs;

use App\Models\SocialAccountLog;
use App\Models\SocialOauthIdentity;
use App\Services\Meta\MetaApiException;
use App\Services\Meta\MetaConnectionService;
use App\Services\Meta\MetaGraphClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Long-lived Meta user tokens last ~60 days and CANNOT be exchanged after
 * expiry (official docs, docs/07). This job re-exchanges tokens expiring
 * within 7 days and re-syncs the derived Page/Instagram accounts.
 */
class RefreshMetaTokens implements ShouldQueue
{
    use Queueable;

    public function handle(MetaGraphClient $graph, MetaConnectionService $connections): void
    {
        $identities = SocialOauthIdentity::withoutTenantScope()
            ->where('provider', 'meta')
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<=', now()->addDays(7))
            ->where('token_expires_at', '>', now())
            ->get();

        foreach ($identities as $identity) {
            try {
                $long = $graph->exchangeLongLived($identity->access_token);
                $identity->update([
                    'access_token' => $long['access_token'],
                    'token_expires_at' => isset($long['expires_in']) ? now()->addSeconds((int) $long['expires_in']) : now()->addDays(60),
                ]);
                $connections->syncAccounts($identity->organization, $identity->fresh());
                SocialAccountLog::create([
                    'organization_id' => $identity->organization_id,
                    'social_account_id' => null,
                    'user_id' => null,
                    'event' => 'token_refreshed',
                    'message' => 'Long-lived user token re-exchanged; accounts re-synced.',
                ]);
            } catch (MetaApiException $e) {
                SocialAccountLog::create([
                    'organization_id' => $identity->organization_id,
                    'social_account_id' => null,
                    'user_id' => null,
                    'event' => 'error',
                    'message' => 'Token refresh failed: ' . $e->getMessage(),
                    'metadata' => ['graph_code' => $e->graphCode],
                ]);
            }
        }
    }
}
