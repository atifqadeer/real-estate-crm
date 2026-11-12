<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignAd;
use App\Models\CampaignAdGroup;
use App\Models\CampaignSpendRecord;
use App\Models\Organization;
use App\Services\Campaigns\CampaignMetricsService;
use App\Services\Campaigns\MetaAdsSyncService;
use App\Services\Meta\MetaApiException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CampaignController extends Controller
{
    public function __construct(private CampaignMetricsService $metrics) {}

    public function index(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('campaigns.view'), 403);
        $campaigns = Campaign::where('organization_id', $organization->id)
            ->with('owner:id,name')->latest()->get()
            ->map(fn (Campaign $c) => array_merge($c->toArray(), ['metrics' => $this->metrics->metrics($c)]));
        return response()->json(['data' => $campaigns]);
    }

    public function store(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('campaigns.create'), 403);
        $data = $this->validateCampaign($request);
        $campaign = Campaign::create(array_merge($data, [
            'organization_id' => $organization->id,
            'owner_id' => $data['owner_id'] ?? $request->user()->id,
        ]));
        return response()->json(['data' => $campaign], 201);
    }

    public function show(Request $request, Organization $organization, Campaign $campaign)
    {
        $this->guard($organization, $campaign);
        abort_unless($request->user()->can('campaigns.view'), 403);
        $from = $request->query('from') ? Carbon::parse($request->query('from')) : null;
        $to = $request->query('to') ? Carbon::parse($request->query('to')) : null;
        return response()->json(['data' => array_merge($campaign->load(['adGroups', 'ads', 'owner:id,name'])->toArray(), [
            'metrics' => $this->metrics->metrics($campaign, $from, $to),
            'spend_records' => $campaign->spendRecords()->orderByDesc('date')->limit(90)->get(),
            'attributed_lead_ids' => array_keys($this->metrics->attributedLeadIds($campaign)),
        ])]);
    }

    public function update(Request $request, Organization $organization, Campaign $campaign)
    {
        $this->guard($organization, $campaign);
        abort_unless($request->user()->can('campaigns.edit'), 403);
        $campaign->update($this->validateCampaign($request, partial: true));
        return response()->json(['data' => $campaign->fresh()]);
    }

    public function destroy(Request $request, Organization $organization, Campaign $campaign)
    {
        $this->guard($organization, $campaign);
        abort_unless($request->user()->can('campaigns.delete'), 403);
        $campaign->delete();
        return response()->json(['data' => ['message' => 'Campaign deleted.']]);
    }

    public function addAdGroup(Request $request, Organization $organization, Campaign $campaign)
    {
        $this->guard($organization, $campaign);
        abort_unless($request->user()->can('campaigns.edit'), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'external_id' => ['nullable', 'string', 'max:64']]);
        $group = CampaignAdGroup::create(array_merge($data, ['organization_id' => $organization->id, 'campaign_id' => $campaign->id]));
        return response()->json(['data' => $group], 201);
    }

    public function addAd(Request $request, Organization $organization, Campaign $campaign)
    {
        $this->guard($organization, $campaign);
        abort_unless($request->user()->can('campaigns.edit'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'], 'external_id' => ['nullable', 'string', 'max:64'],
            'creative_reference' => ['nullable', 'string', 'max:255'], 'campaign_ad_group_id' => ['nullable', 'integer'],
        ]);
        $ad = CampaignAd::create(array_merge($data, ['organization_id' => $organization->id, 'campaign_id' => $campaign->id]));
        return response()->json(['data' => $ad], 201);
    }

    /** Manual spend entry (first-release path per prompt section 15). */
    public function addSpend(Request $request, Organization $organization, Campaign $campaign)
    {
        $this->guard($organization, $campaign);
        abort_unless($request->user()->can('campaigns.spend'), 403);
        $data = $request->validate([
            'date' => ['required', 'date'],
            'spend' => ['required', 'numeric', 'min:0'],
            'impressions' => ['nullable', 'integer', 'min:0'],
            'reach' => ['nullable', 'integer', 'min:0'],
            'clicks' => ['nullable', 'integer', 'min:0'],
            'conversions' => ['nullable', 'integer', 'min:0'],
        ]);
        // whereDate lookup: the date cast may store datetimes, so plain
        // attribute equality in updateOrCreate can miss an existing row.
        $record = CampaignSpendRecord::where('campaign_id', $campaign->id)
            ->whereDate('date', $data['date'])->where('level', 'campaign')->whereNull('external_id')->first();
        $values = array_merge($data, ['organization_id' => $organization->id, 'source' => 'manual', 'created_by' => $request->user()->id]);
        if ($record) {
            $record->update($values);
        } else {
            $record = CampaignSpendRecord::create(array_merge($values, ['campaign_id' => $campaign->id, 'level' => 'campaign']));
        }
        $this->metrics->checkBudgetAlerts($campaign->fresh());
        return response()->json(['data' => $record], 201);
    }

    public function deleteSpend(Request $request, Organization $organization, Campaign $campaign, CampaignSpendRecord $record)
    {
        $this->guard($organization, $campaign);
        abort_unless($record->campaign_id === $campaign->id, 404);
        abort_unless($request->user()->can('campaigns.spend'), 403);
        $record->delete();
        return response()->json(['data' => ['message' => 'Spend record deleted.']]);
    }

    public function compare(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('campaigns.view'), 403);
        $ids = array_filter(array_map('intval', explode(',', (string) $request->query('ids', ''))));
        abort_if(count($ids) < 2, 422, 'Provide at least two campaign ids (?ids=1,2).');
        $rows = Campaign::where('organization_id', $organization->id)->whereIn('id', $ids)->get()
            ->map(fn (Campaign $c) => ['campaign' => ['id' => $c->id, 'name' => $c->name, 'status' => $c->status], 'metrics' => $this->metrics->metrics($c)]);
        return response()->json(['data' => $rows]);
    }

    /** Meta Ads API sync (only with official access + ads_read; see docs/11). */
    public function syncMeta(Request $request, Organization $organization, MetaAdsSyncService $sync)
    {
        abort_unless($request->user()->can('campaigns.spend'), 403);
        $data = $request->validate([
            'ad_account_id' => ['required', 'string', 'max:64'],
            'since' => ['nullable', 'date'], 'until' => ['nullable', 'date'],
        ]);
        try {
            $result = $sync->syncAdAccount($organization, $data['ad_account_id'],
                $data['since'] ?? now()->subDays(29)->toDateString(),
                $data['until'] ?? now()->toDateString());
        } catch (MetaApiException $e) {
            return response()->json(['error' => ['code' => 'meta_sync_failed', 'message' => $e->getMessage()]], 502);
        }
        return response()->json(['data' => $result]);
    }

    private function validateCampaign(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        return $request->validate([
            'name' => [$req, 'string', 'max:160'],
            'objective' => ['nullable', 'string', 'max:40'],
            'platform' => ['nullable', 'in:meta,google,tiktok,offline,other'],
            'external_campaign_id' => ['nullable', 'string', 'max:64'],
            'meta_ad_account_id' => ['nullable', 'string', 'max:64'],
            'start_date' => ['nullable', 'date'], 'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'status' => ['nullable', 'in:draft,active,paused,completed'],
            'target_location' => ['nullable', 'string', 'max:120'],
            'property_category' => ['nullable', 'string', 'max:120'],
            'landing_page_url' => ['nullable', 'url', 'max:255'],
            'tracking_params' => ['nullable', 'array'],
            'owner_id' => ['nullable', 'integer'],
        ]);
    }

    private function guard(Organization $organization, Campaign $campaign): void
    {
        abort_unless($campaign->organization_id === $organization->id, 404);
    }
}
