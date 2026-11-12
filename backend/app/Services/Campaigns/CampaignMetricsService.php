<?php
namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignSpendRecord;
use App\Models\Lead;
use App\Models\LeadPipelineStage;
use Illuminate\Support\Carbon;

/**
 * Campaign performance math (prompt section 15).
 * Attribution: a lead belongs to a campaign when ANY of these hold —
 * it is linked manually (campaign_id), its Meta campaign id matches the
 * campaign's external id, or its UTM campaign matches the campaign's
 * tracking utm_campaign. Each lead is counted once (distinct lead ids),
 * and the attribution-method mix is reported honestly instead of
 * claiming deterministic attribution.
 */
class CampaignMetricsService
{
    /** Stage sort orders that count a lead as "qualified" or beyond. */
    public function qualifiedStageIds(Campaign $campaign): array
    {
        $qualifiedOrder = LeadPipelineStage::withoutTenantScope()->where('organization_id', $campaign->organization_id)
            ->where('slug', 'qualified')->value('sort_order');
        if ($qualifiedOrder === null) return [];
        return LeadPipelineStage::withoutTenantScope()->where('organization_id', $campaign->organization_id)
            ->where('sort_order', '>=', $qualifiedOrder)
            ->where('is_lost', false)
            ->pluck('id')->all();
    }

    public function wonStageIds(Campaign $campaign): array
    {
        return LeadPipelineStage::withoutTenantScope()->where('organization_id', $campaign->organization_id)
            ->where('is_won', true)->pluck('id')->all();
    }

    /** Distinct attributed lead ids with the method that matched first. */
    public function attributedLeadIds(Campaign $campaign): array
    {
        $map = [];
        $base = Lead::withoutTenantScope()->where('organization_id', $campaign->organization_id);
        // NOTE: plain loops, not arrow functions — PHP arrow functions
        // capture by value, so $map writes inside fn() would be lost.
        foreach ((clone $base)->where('campaign_id', $campaign->id)->pluck('id') as $id) {
            $map[$id] = 'manual';
        }
        if ($campaign->external_campaign_id) {
            foreach ((clone $base)->where('meta_campaign_id', $campaign->external_campaign_id)->pluck('id') as $id) {
                $map[$id] ??= 'meta_id';
            }
        }
        if ($campaign->utmCampaign()) {
            foreach ((clone $base)->where('utm_campaign', $campaign->utmCampaign())->pluck('id') as $id) {
                $map[$id] ??= 'utm';
            }
        }
        return $map;
    }

    public function metrics(Campaign $campaign, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $records = CampaignSpendRecord::withoutTenantScope()->where('campaign_id', $campaign->id)
            ->when($from, fn ($q) => $q->whereDate('date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->whereDate('date', '<=', $to->toDateString()))
            ->get();

        $spend = (float) $records->sum('spend');
        // Provider metrics: sum only when at least one record supplies them (null = not provided).
        $metric = function (string $field) use ($records) {
            $present = $records->whereNotNull($field);
            return $present->isEmpty() ? null : (int) $present->sum($field);
        };

        $leadIds = array_keys($this->attributedLeadIds($campaign));
        $leadsQuery = Lead::withoutTenantScope()->whereIn('id', $leadIds ?: [0])
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to->endOfDay()));
        $leadCount = (clone $leadsQuery)->count();
        $qualified = (clone $leadsQuery)->whereIn('lead_pipeline_stage_id', $this->qualifiedStageIds($campaign) ?: [0])->count();
        $won = (clone $leadsQuery)->whereIn('lead_pipeline_stage_id', $this->wonStageIds($campaign) ?: [0])->count();

        return [
            'campaign_id' => $campaign->id,
            'spend' => $spend,
            'currency' => $campaign->currency,
            'impressions' => $metric('impressions'),
            'reach' => $metric('reach'),
            'clicks' => $metric('clicks'),
            'conversions_reported' => $metric('conversions'),
            'leads' => $leadCount,
            'qualified_leads' => $qualified,
            'won_leads' => $won,
            'cost_per_lead' => $leadCount > 0 && $spend > 0 ? round($spend / $leadCount, 2) : null,
            'cost_per_qualified_lead' => $qualified > 0 && $spend > 0 ? round($spend / $qualified, 2) : null,
            'conversion_rate_percent' => $leadCount > 0 ? round($won / $leadCount * 100, 2) : null,
            'budget' => $campaign->budget !== null ? (float) $campaign->budget : null,
            'budget_used_percent' => $campaign->budget ? round($spend / (float) $campaign->budget * 100, 1) : null,
            'attribution_methods' => array_count_values($this->attributedLeadIds($campaign)),
        ];
    }

    /** Fire budget-threshold notifications once per threshold per campaign. */
    public function checkBudgetAlerts(Campaign $campaign): void
    {
        if (!$campaign->budget || (float) $campaign->budget <= 0 || !$campaign->owner) return;
        $metrics = $this->metrics($campaign);
        $used = $metrics['budget_used_percent'];
        if ($used === null) return;
        $notifications = app(\App\Services\Notifications\NotificationService::class);
        $organization = $campaign->organization()->first();
        if (!$organization) return;
        foreach ([80 => '80%', 100 => '100%'] as $threshold => $label) {
            if ($used < $threshold) continue;
            $exists = \App\Models\InAppNotification::where('organization_id', $organization->id)
                ->where('type', 'campaign.budget_threshold')
                ->where('data->campaign_id', $campaign->id)
                ->where('data->threshold', $threshold)->exists();
            if ($exists) continue;
            $notifications->notify($organization, $campaign->owner, 'campaign.budget_threshold',
                "Campaign budget {$label} reached: {$campaign->name}",
                "Spend {$metrics['spend']} of {$campaign->budget} {$campaign->currency} ({$used}%).",
                ['campaign_id' => $campaign->id, 'threshold' => $threshold]);
        }
    }
}
