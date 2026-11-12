<?php
namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignSpendRecord;
use App\Models\Organization;
use App\Models\SocialOauthIdentity;
use App\Services\Meta\MetaApiException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Meta Marketing API sync (prompt section 15: "API synchronization only
 * where official API access and permissions are available").
 * Reads campaign-level insights with daily breakdown for an ad account
 * using the workspace's connected Meta user token (needs ads_read).
 * Endpoints/fields verified against Meta docs — see docs/11.
 */
class MetaAdsSyncService
{
    private string $base;

    public function __construct(private CampaignMetricsService $metrics)
    {
        $version = config('services.meta.graph_version', 'v27.0');
        $this->base = "https://graph.facebook.com/{$version}";
    }

    private function tokenFor(Organization $organization): string
    {
        $identity = SocialOauthIdentity::where('organization_id', $organization->id)
            ->whereNotNull('access_token')->latest()->first();
        if (!$identity) {
            throw ValidationException::withMessages(['meta' =>
                'No connected Meta account with a user token in this workspace. Connect Facebook first (Connected Accounts).']);
        }
        return $identity->access_token;
    }

    /**
     * Sync campaigns + daily spend for the given ad account (act_ id or bare id).
     * Returns counts. Upserts by external campaign id; spend upserts by
     * (campaign, date, level=campaign, external_id) so re-syncs are safe.
     */
    public function syncAdAccount(Organization $organization, string $adAccountId, string $since, string $until): array
    {
        $token = $this->tokenFor($organization);
        $act = str_starts_with($adAccountId, 'act_') ? $adAccountId : 'act_' . $adAccountId;

        // 1) Campaign list (names/objectives/status).
        $campaigns = $this->get("/{$act}/campaigns", [
            'fields' => 'id,name,objective,status,start_time,stop_time,lifetime_budget', 'limit' => 200,
        ], $token);
        $byExternal = [];
        foreach ($campaigns['data'] ?? [] as $c) {
            $existing = Campaign::withoutTenantScope()
                ->where('organization_id', $organization->id)
                ->where('external_campaign_id', (string) $c['id'])->first();
            $campaign = Campaign::updateOrCreate(
                ['organization_id' => $organization->id, 'external_campaign_id' => (string) $c['id']],
                [
                    'name' => $c['name'] ?? ('Campaign ' . $c['id']),
                    'objective' => isset($c['objective']) ? strtolower((string) $c['objective']) : null,
                    'platform' => 'meta',
                    'meta_ad_account_id' => $act,
                    'status' => $this->mapStatus($c['status'] ?? null),
                    'start_date' => isset($c['start_time']) ? substr((string) $c['start_time'], 0, 10) : null,
                    'end_date' => isset($c['stop_time']) ? substr((string) $c['stop_time'], 0, 10) : null,
                    // Meta budget fields are integer minor units (e.g. 1000 = 10.00).
                    // Only adopt Meta's lifetime budget when no budget is set locally.
                    'budget' => $existing?->budget ?? (isset($c['lifetime_budget']) && (int) $c['lifetime_budget'] > 0
                        ? round(((int) $c['lifetime_budget']) / 100, 2) : null),
                ]
            );
            $byExternal[(string) $c['id']] = $campaign;
        }

        // 2) Daily insights at campaign level.
        $insights = $this->get("/{$act}/insights", [
            'fields' => 'campaign_id,campaign_name,spend,impressions,reach,clicks,actions',
            'level' => 'campaign',
            'time_increment' => 1,
            'time_range' => json_encode(['since' => $since, 'until' => $until]),
            'limit' => 500,
        ], $token);

        $rows = 0;
        foreach ($insights['data'] ?? [] as $row) {
            $externalId = (string) ($row['campaign_id'] ?? '');
            if ($externalId === '') continue;
            $campaign = $byExternal[$externalId] ?? Campaign::updateOrCreate(
                ['organization_id' => $organization->id, 'external_campaign_id' => $externalId],
                ['name' => $row['campaign_name'] ?? ('Campaign ' . $externalId), 'platform' => 'meta', 'meta_ad_account_id' => $act, 'status' => 'active']
            );
            $conversions = null;
            foreach ($row['actions'] ?? [] as $action) {
                if (in_array($action['action_type'] ?? '', ['lead', 'onsite_conversion.lead_grouped'], true)) {
                    $conversions = (int) ($action['value'] ?? 0);
                }
            }
            // Lookup by whereDate: the date cast stores datetimes in some
            // drivers, so attribute equality in updateOrCreate can miss.
            $record = CampaignSpendRecord::where('campaign_id', $campaign->id)
                ->whereDate('date', $row['date_start'])->where('level', 'campaign')
                ->where('external_id', $externalId)->first();
            $values = [
                'organization_id' => $organization->id,
                'spend' => (float) ($row['spend'] ?? 0),
                'impressions' => isset($row['impressions']) ? (int) $row['impressions'] : null,
                'reach' => isset($row['reach']) ? (int) $row['reach'] : null,
                'clicks' => isset($row['clicks']) ? (int) $row['clicks'] : null,
                'conversions' => $conversions,
                'source' => 'meta_api',
            ];
            if ($record) {
                $record->update($values);
            } else {
                CampaignSpendRecord::create(array_merge($values, [
                    'campaign_id' => $campaign->id, 'date' => $row['date_start'],
                    'level' => 'campaign', 'external_id' => $externalId,
                ]));
            }
            $rows++;
            $this->metrics->checkBudgetAlerts($campaign);
        }
        return ['campaigns' => count($byExternal), 'spend_rows' => $rows];
    }

    private function mapStatus(?string $status): string
    {
        return match ($status) {
            'ACTIVE' => 'active',
            'PAUSED' => 'paused',
            default => 'draft',
        };
    }

    private function get(string $path, array $params, string $token): array
    {
        $response = Http::withToken($token)->acceptJson()->get($this->base . $path, $params);
        $json = $response->json() ?? [];
        if ($response->failed() || isset($json['error'])) {
            $error = $json['error'] ?? [];
            $hint = ((int) ($error['code'] ?? 0) === 200 || ($error['type'] ?? '') === 'OAuthException')
                ? ' The token likely lacks the ads_read permission for this ad account — reconnect Facebook and approve ads access, or add the token in Ads Manager.'
                : '';
            throw new MetaApiException(($error['message'] ?? 'Meta Ads request failed.') . $hint, (int) ($error['code'] ?? $response->status()));
        }
        return $json;
    }
}
