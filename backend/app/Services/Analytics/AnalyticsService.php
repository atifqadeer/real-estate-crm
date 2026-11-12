<?php
namespace App\Services\Analytics;

use App\Models\Campaign;
use App\Models\CampaignSpendRecord;
use App\Models\ContentPost;
use App\Models\ContentPostTarget;
use App\Models\Lead;
use App\Models\LeadFollowUp;
use App\Models\LeadPipelineStage;
use App\Models\LeadTask;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Services\Campaigns\CampaignMetricsService;
use Illuminate\Support\Carbon;

/**
 * Dashboards and report datasets (prompt section 16). All numbers are
 * computed from local, tenant-scoped data. Provider metrics that were
 * never supplied remain null ("not provided") rather than 0.
 * Aggregate lead counts always count distinct leads — no double counting.
 */
class AnalyticsService
{
    public function __construct(private CampaignMetricsService $campaignMetrics) {}

    public function range(?string $from, ?string $to, ?string $preset = null): array
    {
        if ($from && $to) return [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()];
        return match ($preset) {
            'today' => [now()->startOfDay(), now()->endOfDay()],
            'week' => [now()->startOfWeek(), now()->endOfDay()],
            'month' => [now()->startOfMonth(), now()->endOfDay()],
            default => [now()->subDays(29)->startOfDay(), now()->endOfDay()],
        };
    }

    public function clientOverview(Organization $org, Carbon $from, Carbon $to): array
    {
        // Table-qualified columns: several breakdowns join tables that
        // also carry organization_id / created_at.
        $leads = Lead::withoutTenantScope()->where('leads.organization_id', $org->id)->whereBetween('leads.created_at', [$from, $to]);
        $totalLeads = (clone $leads)->count();

        $stages = LeadPipelineStage::withoutTenantScope()->where('organization_id', $org->id)->orderBy('sort_order')->get();
        $qualifiedIds = $this->qualifiedStageIds($org);
        $wonIds = $stages->where('is_won', true)->pluck('id')->all();
        $qualified = (clone $leads)->whereIn('lead_pipeline_stage_id', $qualifiedIds ?: [0])->count();
        $won = (clone $leads)->whereIn('lead_pipeline_stage_id', $wonIds ?: [0])->count();

        $funnel = $stages->map(fn ($s) => [
            'stage' => $s->name, 'slug' => $s->slug,
            'count' => (clone $leads)->where('lead_pipeline_stage_id', $s->id)->count(),
        ])->values();

        $bySource = (clone $leads)->join('lead_sources', 'lead_sources.id', '=', 'leads.lead_source_id')
            ->selectRaw('lead_sources.name as source, count(*) as count')
            ->groupBy('lead_sources.name')->orderByDesc('count')->get();

        $byCity = (clone $leads)->whereNotNull('city')->selectRaw('city, count(*) as count')
            ->groupBy('city')->orderByDesc('count')->limit(10)->get();
        $byProperty = (clone $leads)->whereNotNull('property_type')->selectRaw('property_type, count(*) as count')
            ->groupBy('property_type')->orderByDesc('count')->limit(10)->get();

        $byAgent = (clone $leads)->whereNotNull('assigned_to')
            ->join('users', 'users.id', '=', 'leads.assigned_to')
            ->selectRaw('leads.assigned_to as uid, users.name as agent, count(*) as leads')
            ->groupBy('leads.assigned_to', 'users.name')->orderByDesc('leads')->get()
            ->map(function ($row) use ($org, $wonIds, $from, $to) {
                $wonCount = Lead::withoutTenantScope()->where('organization_id', $org->id)->where('assigned_to', $row->uid)
                    ->whereBetween('created_at', [$from, $to])
                    ->whereIn('lead_pipeline_stage_id', $wonIds ?: [0])->count();
                return ['agent' => $row->agent, 'leads' => (int) $row->leads, 'won' => $wonCount];
            });

        $byCampaign = Campaign::withoutTenantScope()->where('organization_id', $org->id)->get()
            ->map(fn (Campaign $c) => array_merge(['name' => $c->name], $this->campaignMetrics->metrics($c, $from, $to)))
            ->filter(fn ($m) => $m['leads'] > 0 || $m['spend'] > 0)->values();

        // whereDate: the date column may hold datetimes depending on driver.
        $spend = (float) CampaignSpendRecord::withoutTenantScope()->where('organization_id', $org->id)
            ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())->sum('spend');

        $published = ContentPostTarget::where('organization_id', $org->id)->where('status', 'published')
            ->whereBetween('published_at', [$from, $to])->count();
        $failed = ContentPostTarget::where('organization_id', $org->id)->where('status', 'failed')
            ->whereBetween('updated_at', [$from, $to])->count();
        $scheduled = ContentPost::withoutTenantScope()->where('organization_id', $org->id)->where('status', 'scheduled')->count();

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'leads_received' => $totalLeads,
            'qualified_leads' => $qualified,
            'qualified_rate_percent' => $totalLeads ? round($qualified / $totalLeads * 100, 1) : null,
            'won_leads' => $won,
            'conversion_rate_percent' => $totalLeads ? round($won / $totalLeads * 100, 1) : null,
            'campaign_spend' => $spend,
            'cost_per_lead' => $totalLeads && $spend > 0 ? round($spend / $totalLeads, 2) : null,
            'funnel' => $funnel,
            'leads_by_source' => $bySource,
            'leads_by_campaign' => $byCampaign,
            'leads_by_city' => $byCity,
            'leads_by_property_type' => $byProperty,
            'agent_performance' => $byAgent,
            'follow_ups_due_today' => LeadFollowUp::withoutTenantScope()->where('organization_id', $org->id)->where('status', 'pending')
                ->whereDate('due_at', now()->toDateString())->count(),
            'follow_ups_overdue' => LeadFollowUp::withoutTenantScope()->where('organization_id', $org->id)->where('status', 'pending')
                ->where('due_at', '<', now())->count(),
            'posts_published' => $published,
            'posts_failed' => $failed,
            'posts_scheduled' => $scheduled,
            'social_account_health' => SocialAccount::withoutTenantScope()->where('organization_id', $org->id)
                ->selectRaw('status, count(*) as count')->groupBy('status')->get(),
            // Post engagement metrics require Meta insights access per post;
            // not fetched yet, so reported as not-provided rather than 0.
            'social_engagement' => null,
        ];
    }

    public function agencyOverview(Organization $agency, Carbon $from, Carbon $to): array
    {
        $clients = Organization::where('type', 'real_estate_client')->orderBy('business_name')->get();
        $rows = [];
        $totals = ['leads' => 0, 'qualified' => 0, 'spend' => 0.0, 'posts_published' => 0];
        foreach ($clients as $client) {
            $o = $this->clientOverview($client, $from, $to);
            $rows[] = [
                'client' => $client->business_name,
                'leads' => $o['leads_received'],
                'qualified' => $o['qualified_leads'],
                'spend' => $o['campaign_spend'],
                'cost_per_lead' => $o['cost_per_lead'],
                'posts_published' => $o['posts_published'],
                'follow_ups_overdue' => $o['follow_ups_overdue'],
            ];
            $totals['leads'] += $o['leads_received'];
            $totals['qualified'] += $o['qualified_leads'];
            $totals['spend'] += $o['campaign_spend'];
            $totals['posts_published'] += $o['posts_published'];
        }
        $workload = LeadTask::withoutTenantScope()->whereIn('organization_id', $clients->pluck('id'))
            ->where('status', 'pending')->whereNotNull('assigned_to')
            ->join('users', 'users.id', '=', 'lead_tasks.assigned_to')
            ->selectRaw('users.name as member, count(*) as open_tasks')
            ->groupBy('users.name')->orderByDesc('open_tasks')->get();
        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'total_clients' => $clients->count(),
            'active_clients' => $clients->where('status', 'active')->count(),
            'connected_social_accounts' => SocialAccount::withoutTenantScope()->whereIn('organization_id', $clients->pluck('id'))
                ->where('status', 'connected')->count(),
            'totals' => array_merge($totals, [
                'cost_per_lead' => $totals['leads'] && $totals['spend'] > 0 ? round($totals['spend'] / $totals['leads'], 2) : null,
            ]),
            'clients' => $rows,
            'team_workload' => $workload,
        ];
    }

    /** Exportable report datasets: [title, columns, rows]. */
    public function reportDataset(Organization $org, string $report, Carbon $from, Carbon $to): array
    {
        $overview = $this->clientOverview($org, $from, $to);
        return match ($report) {
            'leads' => ['Leads report', ['Name', 'Phone', 'Email', 'City', 'Source', 'Stage', 'Assigned to', 'Created'],
                Lead::withoutTenantScope()->where('organization_id', $org->id)->whereBetween('created_at', [$from, $to])
                    ->with(['source', 'stage', 'assignee'])->latest()->get()
                    ->map(fn (Lead $l) => [$l->full_name, $l->phone_e164, $l->email, $l->city, $l->source?->name, $l->stage?->name, $l->assignee?->name, $l->created_at->toDateTimeString()])->all()],
            'campaign-performance' => ['Campaign performance', ['Campaign', 'Spend', 'Impressions', 'Reach', 'Clicks', 'Leads', 'Qualified', 'Won', 'CPL', 'CPQL', 'Conversion %'],
                collect($overview['leads_by_campaign'])->map(fn ($m) => [$m['name'], $m['spend'], $m['impressions'] ?? 'n/a', $m['reach'] ?? 'n/a', $m['clicks'] ?? 'n/a', $m['leads'], $m['qualified_leads'], $m['won_leads'], $m['cost_per_lead'] ?? 'n/a', $m['cost_per_qualified_lead'] ?? 'n/a', $m['conversion_rate_percent'] ?? 'n/a'])->all()],
            'funnel' => ['Lead conversion funnel', ['Stage', 'Leads'],
                collect($overview['funnel'])->map(fn ($f) => [$f['stage'], $f['count']])->all()],
            'agent-performance' => ['Agent performance', ['Agent', 'Leads', 'Won'],
                collect($overview['agent_performance'])->map(fn ($a) => [$a['agent'], $a['leads'], $a['won']])->all()],
            'publishing-history' => ['Social publishing history', ['Post', 'Platform', 'Account', 'Status', 'Published at', 'Link'],
                ContentPostTarget::where('organization_id', $org->id)->whereBetween('created_at', [$from, $to])
                    ->with(['post', 'account'])->latest()->get()
                    ->map(fn ($t) => [$t->post?->title ?? 'Untitled', $t->platform, $t->account?->display_name, $t->status, $t->published_at?->toDateTimeString(), $t->platform_url])->all()],
            default => throw new \InvalidArgumentException("Unknown report: {$report}"),
        };
    }

    private function qualifiedStageIds(Organization $org): array
    {
        $order = LeadPipelineStage::withoutTenantScope()->where('organization_id', $org->id)->where('slug', 'qualified')->value('sort_order');
        if ($order === null) return [];
        return LeadPipelineStage::withoutTenantScope()->where('organization_id', $org->id)
            ->where('sort_order', '>=', $order)->where('is_lost', false)->pluck('id')->all();
    }
}
