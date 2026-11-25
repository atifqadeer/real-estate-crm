<?php

namespace Database\Seeders;

use App\Models\Campaign;
use App\Models\CampaignSpendRecord;
use App\Models\ContentPost;
use App\Models\Lead;
use App\Models\LeadFollowUp;
use App\Models\LeadPipelineStage;
use App\Models\Organization;
use App\Models\User;
use App\Services\Leads\LeadIngestionService;
use Illuminate\Database\Seeder;

/**
 * DEMO module data for local development only. Clearly fake; never production data.
 *
 * Seeds Client A only (owner@clienta.demo) with a handful of leads spread
 * across the pipeline, one campaign with manual spend, and two content posts,
 * so Leads / Pipeline / Campaigns / Analytics / Content can be exercised
 * without a real Meta account, WhatsApp number or live lead form.
 *
 * Idempotent and safe: if Client A already has any leads, it does nothing,
 * so it never overwrites real data Atif has entered. Wipe with
 * `php artisan migrate:fresh --seed`.
 *
 * Deliberately NOT seeded: social accounts, WhatsApp conversations/messages
 * (both need real connected accounts), and published post targets.
 */
class DemoModuleDataSeeder extends Seeder
{
    public function run(): void
    {
        $clientA = Organization::where('email', 'hello@clientA.demo')->first();
        $owner = User::where('email', 'owner@clienta.demo')->first();
        if (! $clientA || ! $owner) {
            return;
        }

        if (Lead::withoutTenantScope()->where('organization_id', $clientA->id)->exists()) {
            return;
        }

        $ingest = app(LeadIngestionService::class);

        $campaign = Campaign::withoutTenantScope()->create([
            'organization_id' => $clientA->id,
            'name' => 'DEMO DHA Launch',
            'objective' => 'leads',
            'platform' => 'meta',
            'status' => 'active',
            'budget' => 100000,
            'currency' => 'PKR',
            'start_date' => now()->subDays(7)->toDateString(),
            'end_date' => now()->addDays(23)->toDateString(),
            'target_location' => 'Karachi',
            'property_category' => 'apartment',
            'tracking_params' => ['utm_campaign' => 'demo_dha_launch'],
            'owner_id' => $owner->id,
        ]);

        foreach ([
            ['date' => now()->subDays(2)->toDateString(), 'spend' => 12000, 'impressions' => 4200, 'reach' => 3100, 'clicks' => 96, 'conversions' => 2],
            ['date' => now()->subDay()->toDateString(), 'spend' => 18000, 'impressions' => 6100, 'reach' => 4400, 'clicks' => 131, 'conversions' => 3],
            ['date' => now()->toDateString(), 'spend' => 9000, 'impressions' => 2800, 'reach' => 2100, 'clicks' => 54, 'conversions' => 1],
        ] as $row) {
            CampaignSpendRecord::withoutTenantScope()->create(array_merge($row, [
                'organization_id' => $clientA->id,
                'campaign_id' => $campaign->id,
                'source' => 'manual',
                'created_by' => $owner->id,
            ]));
        }

        $leads = [
            // full_name, phone, city, property, intent, budget, source, stage, campaign?
            ['DEMO Ahmed Raza', '03001111111', 'Karachi', 'apartment', 'buy', 15000000, 20000000, 'manual', 'new', true],
            ['DEMO Sara Khan', '03002222222', 'Karachi', 'house', 'buy', 25000000, 35000000, 'website_form', 'contacted', true],
            ['DEMO Bilal Ahmed', '03003333333', 'Lahore', 'apartment', 'rent', 80000, 120000, 'meta_lead_ads', 'qualified', false],
            ['DEMO Fatima Noor', '03004444444', 'Islamabad', 'plot', 'invest', 5000000, 8000000, 'manual', 'interested', false],
            ['DEMO Usman Tariq', '03005555555', 'Karachi', 'house', 'buy', 40000000, 55000000, 'website_form', 'viewing_scheduled', false],
            ['DEMO Ayesha Malik', '03006666666', 'Lahore', 'apartment', 'buy', 12000000, 18000000, 'manual', 'converted', false],
        ];

        $created = [];
        foreach ($leads as [$name, $phone, $city, $property, $intent, $min, $max, $source, $stage, $inCampaign]) {
            $result = $ingest->ingest($clientA, [
                'full_name' => $name,
                'phone' => $phone,
                'city' => $city,
                'property_type' => $property,
                'intent' => $intent,
                'budget_min' => $min,
                'budget_max' => $max,
                'budget_currency' => 'PKR',
                'utm_source' => $inCampaign ? 'facebook' : null,
                'utm_medium' => $inCampaign ? 'cpc' : null,
                'utm_campaign' => $inCampaign ? 'demo_dha_launch' : null,
                'campaign_name' => $inCampaign ? 'DEMO DHA Launch' : null,
            ], $source, $owner);

            $lead = $result['lead'];
            $stageId = LeadPipelineStage::withoutTenantScope()
                ->where('organization_id', $clientA->id)->where('slug', $stage)->value('id');
            $lead->update(array_filter([
                'lead_pipeline_stage_id' => $stageId,
                'campaign_id' => $inCampaign ? $campaign->id : null,
                'attribution_method' => $inCampaign ? 'manual' : null,
            ]));
            $created[] = $lead->fresh();
        }

        if (! empty($created[0])) {
            LeadFollowUp::withoutTenantScope()->create([
                'organization_id' => $clientA->id,
                'lead_id' => $created[0]->id,
                'user_id' => $owner->id,
                'due_at' => now()->addDay()->setTime(11, 0),
                'note' => 'DEMO: call to confirm viewing time.',
                'status' => 'pending',
            ]);
        }

        ContentPost::withoutTenantScope()->create([
            'organization_id' => $clientA->id,
            'author_id' => $owner->id,
            'title' => 'DEMO: 3-bed apartment in DHA Karachi',
            'caption' => 'DEMO post — 3-bed apartment in DHA Karachi, Rs 1.5–2 crore. Call to book a viewing.',
            'hashtags' => '#Karachi #DHA #PropertyForSale',
            'status' => 'approved',
            'approval_required' => false,
        ]);
        ContentPost::withoutTenantScope()->create([
            'organization_id' => $clientA->id,
            'author_id' => $owner->id,
            'title' => 'DEMO: Open house this weekend',
            'caption' => 'DEMO post — open house this weekend in Lahore. Walk-ins welcome.',
            'hashtags' => '#Lahore #OpenHouse',
            'status' => 'draft',
            'approval_required' => false,
        ]);
    }
}
