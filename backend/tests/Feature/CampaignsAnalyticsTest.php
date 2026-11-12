<?php
namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\InAppNotification;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\SocialOauthIdentity;
use App\Models\User;
use App\Services\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CampaignsAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $agency;
    private Organization $clientA;
    private Organization $clientB;
    private User $adminA;
    private User $adminB;
    private User $analyst;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\SocialPlatformSeeder::class);
        $roles = app(RoleProvisioner::class);
        $mk = function (string $type = 'real_estate_client') use ($roles) {
            $org = Organization::factory()->create(['type' => $type]);
            $roles->provisionFor($org);
            $org->settings()->create([]);
            return $org;
        };
        $this->agency = $mk('agency');
        $this->clientA = $mk();
        $this->clientB = $mk();
        $member = function (Organization $org, string $role) use ($roles) {
            $user = User::factory()->create();
            $org->users()->attach($user->id, ['status' => 'active', 'joined_at' => now()]);
            $roles->assign($org, $user, $role);
            return $user;
        };
        $this->adminA = $member($this->clientA, 'client_admin');
        $this->adminB = $member($this->clientB, 'client_admin');
        $this->analyst = $member($this->agency, 'agency_analyst');
    }

    private function auth(User $user, Organization $org): array
    {
        auth()->forgetGuards();
        return ['Authorization' => 'Bearer ' . $user->createToken('t')->plainTextToken, 'X-Organization-Id' => (string) $org->id];
    }

    private function makeCampaign(array $attrs = []): Campaign
    {
        $res = $this->postJson("/api/v1/organizations/{$this->clientA->id}/campaigns", array_merge([
            'name' => 'DHA Launch', 'platform' => 'meta', 'status' => 'active',
        ], $attrs), $this->auth($this->adminA, $this->clientA));
        $res->assertCreated();
        return Campaign::find($res->json('data.id'));
    }

    private function makeLead(array $attrs): Lead
    {
        $res = $this->postJson("/api/v1/organizations/{$this->clientA->id}/leads", $attrs, $this->auth($this->adminA, $this->clientA));
        $res->assertCreated();
        return Lead::find($res->json('data.id'));
    }

    private function stageId(string $slug): int
    {
        return \App\Models\LeadPipelineStage::where('organization_id', $this->clientA->id)->where('slug', $slug)->value('id');
    }

    public function test_campaign_crud_defaults(): void
    {
        $campaign = $this->makeCampaign(['budget' => 250000]);
        $this->assertSame('PKR', $campaign->currency);
        $this->assertSame($this->adminA->id, $campaign->owner_id);

        $list = $this->getJson("/api/v1/organizations/{$this->clientA->id}/campaigns", $this->auth($this->adminA, $this->clientA));
        $list->assertOk();
        $this->assertSame(1, count($list->json('data')));
        $this->assertNull($list->json('data.0.metrics.cost_per_lead')); // no spend/leads -> null, not 0

        $upd = $this->putJson("/api/v1/organizations/{$this->clientA->id}/campaigns/{$campaign->id}", ['status' => 'paused'], $this->auth($this->adminA, $this->clientA));
        $upd->assertOk()->assertJsonPath('data.status', 'paused');
    }

    public function test_spend_metrics_attribution_and_missing_vs_zero(): void
    {
        $campaign = $this->makeCampaign([
            'budget' => 100000, 'external_campaign_id' => 'meta-c-1',
            'tracking_params' => ['utm_campaign' => 'dha_launch'],
        ]);
        $h = $this->auth($this->adminA, $this->clientA);
        $this->postJson("/api/v1/organizations/{$this->clientA->id}/campaigns/{$campaign->id}/spend", [
            'date' => now()->subDays(2)->toDateString(), 'spend' => 30000, 'impressions' => 1000, 'clicks' => 50,
        ], $h)->assertCreated();
        $this->postJson("/api/v1/organizations/{$this->clientA->id}/campaigns/{$campaign->id}/spend", [
            'date' => now()->subDay()->toDateString(), 'spend' => 20000, // no provider metrics this day
        ], $h)->assertCreated();

        // A second campaign whose spend has NO provider metrics at all.
        $bare = $this->makeCampaign(['name' => 'Offline Hoardings', 'platform' => 'offline']);
        $this->postJson("/api/v1/organizations/{$this->clientA->id}/campaigns/{$bare->id}/spend", [
            'date' => now()->toDateString(), 'spend' => 5000,
        ], $h)->assertCreated();
        $bareDetail = $this->getJson("/api/v1/organizations/{$this->clientA->id}/campaigns/{$bare->id}", $h);
        $this->assertNull($bareDetail->json('data.metrics.impressions')); // missing, NOT zero

        // Attribution: meta id, utm, manual link, unrelated.
        $won = $this->makeLead(['full_name' => 'Meta Buyer', 'phone' => '03001110001']);
        $won->update(['meta_campaign_id' => 'meta-c-1']);
        $this->putJson("/api/v1/organizations/{$this->clientA->id}/leads/{$won->id}", ['lead_pipeline_stage_id' => $this->stageId('converted')], $h)->assertOk();

        $utm = $this->makeLead(['full_name' => 'Utm Lead', 'phone' => '03001110002']);
        $utm->update(['utm_campaign' => 'dha_launch']);
        $this->putJson("/api/v1/organizations/{$this->clientA->id}/leads/{$utm->id}", ['lead_pipeline_stage_id' => $this->stageId('qualified')], $h)->assertOk();

        $manual = $this->makeLead(['full_name' => 'Manual Link', 'phone' => '03001110003']);
        $this->putJson("/api/v1/organizations/{$this->clientA->id}/leads/{$manual->id}", ['campaign_id' => $campaign->id], $h)->assertOk();

        $this->makeLead(['full_name' => 'Unrelated', 'phone' => '03001110004']);

        $detail = $this->getJson("/api/v1/organizations/{$this->clientA->id}/campaigns/{$campaign->id}", $h);
        $detail->assertOk();
        $m = $detail->json('data.metrics');
        $this->assertSame(50000.0, (float) $m['spend']);
        $this->assertSame(1000, $m['impressions']);
        $this->assertSame(3, $m['leads']);
        $this->assertSame(2, $m['qualified_leads']);
        $this->assertSame(1, $m['won_leads']);
        $this->assertEqualsWithDelta(50000 / 3, (float) $m['cost_per_lead'], 0.01);
        $this->assertSame(25000.0, (float) $m['cost_per_qualified_lead']);
        $this->assertEqualsWithDelta(33.33, (float) $m['conversion_rate_percent'], 0.01);
        $methods = $detail->json('data.metrics.attribution_methods');
        $this->assertSame(1, $methods['meta_id']);
        $this->assertSame(1, $methods['utm']);
        $this->assertSame(1, $methods['manual']);
    }

    public function test_budget_threshold_notifications_fire_once(): void
    {
        $campaign = $this->makeCampaign(['budget' => 10000]);
        $h = $this->auth($this->adminA, $this->clientA);
        $this->postJson("/api/v1/organizations/{$this->clientA->id}/campaigns/{$campaign->id}/spend", [
            'date' => now()->toDateString(), 'spend' => 8500,
        ], $h)->assertCreated();
        $this->assertSame(1, InAppNotification::where('type', 'campaign.budget_threshold')->where('data->threshold', 80)->count());

        $this->postJson("/api/v1/organizations/{$this->clientA->id}/campaigns/{$campaign->id}/spend", [
            'date' => now()->subDay()->toDateString(), 'spend' => 2000,
        ], $h)->assertCreated();
        $this->assertSame(1, InAppNotification::where('type', 'campaign.budget_threshold')->where('data->threshold', 100)->count());

        // More spend must not re-fire either threshold.
        $this->postJson("/api/v1/organizations/{$this->clientA->id}/campaigns/{$campaign->id}/spend", [
            'date' => now()->subDays(2)->toDateString(), 'spend' => 500,
        ], $h)->assertCreated();
        $this->assertSame(2, InAppNotification::where('type', 'campaign.budget_threshold')->count());
    }

    public function test_meta_ads_sync_imports_campaigns_and_daily_spend(): void
    {
        SocialOauthIdentity::create([
            'organization_id' => $this->clientA->id, 'provider' => 'facebook', 'external_user_id' => 'fb-1',
            'access_token' => 'ads-token', 'connected_by' => $this->adminA->id,
        ]);
        Http::fake([
            'graph.facebook.com/v27.0/act_12345/campaigns*' => Http::response(['data' => [[
                'id' => 'meta-c-9', 'name' => 'Synced Campaign', 'objective' => 'OUTCOME_LEADS', 'status' => 'ACTIVE',
                'start_time' => '2026-11-01T00:00:00+0500', 'lifetime_budget' => '500000',
            ]]]),
            'graph.facebook.com/v27.0/act_12345/insights*' => Http::response(['data' => [
                ['campaign_id' => 'meta-c-9', 'campaign_name' => 'Synced Campaign', 'spend' => '1200.50',
                    'impressions' => '5000', 'reach' => '3000', 'clicks' => '120',
                    'actions' => [['action_type' => 'lead', 'value' => '7']],
                    'date_start' => now()->subDay()->toDateString(), 'date_stop' => now()->subDay()->toDateString()],
                ['campaign_id' => 'meta-c-9', 'campaign_name' => 'Synced Campaign', 'spend' => '800',
                    'impressions' => '3000', 'reach' => '2000', 'clicks' => '60',
                    'actions' => [['action_type' => 'onsite_conversion.lead_grouped', 'value' => '3']],
                    'date_start' => now()->toDateString(), 'date_stop' => now()->toDateString()],
            ]]),
        ]);
        $h = $this->auth($this->adminA, $this->clientA);
        $res = $this->postJson("/api/v1/organizations/{$this->clientA->id}/campaigns/sync-meta", [
            'ad_account_id' => '12345', 'since' => now()->subDays(7)->toDateString(), 'until' => now()->toDateString(),
        ], $h);
        $res->assertOk()->assertJsonPath('data.campaigns', 1)->assertJsonPath('data.spend_rows', 2);

        $campaign = Campaign::where('external_campaign_id', 'meta-c-9')->first();
        $this->assertNotNull($campaign);
        $this->assertSame(5000.0, (float) $campaign->budget); // lifetime_budget minor units -> 5000.00
        $this->assertSame(2, $campaign->spendRecords()->count());
        $first = $campaign->spendRecords()->orderBy('date')->first();
        $this->assertSame(7, $first->conversions);
        $this->assertSame('meta_api', $first->source);

        // Re-sync is idempotent.
        $this->postJson("/api/v1/organizations/{$this->clientA->id}/campaigns/sync-meta", ['ad_account_id' => '12345'], $h)->assertOk();
        $this->assertSame(1, Campaign::where('external_campaign_id', 'meta-c-9')->count());
        $this->assertSame(2, $campaign->spendRecords()->count());
    }

    public function test_meta_sync_permission_error_is_honest(): void
    {
        SocialOauthIdentity::create([
            'organization_id' => $this->clientA->id, 'provider' => 'facebook', 'external_user_id' => 'fb-1',
            'access_token' => 'ads-token', 'connected_by' => $this->adminA->id,
        ]);
        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => [
                'message' => 'Permissions error', 'type' => 'OAuthException', 'code' => 200,
            ]], 400),
        ]);
        $res = $this->postJson("/api/v1/organizations/{$this->clientA->id}/campaigns/sync-meta", [
            'ad_account_id' => '12345',
        ], $this->auth($this->adminA, $this->clientA));
        $res->assertStatus(502);
        $this->assertStringContainsString('ads_read', $res->json('error.message'));
    }

    public function test_client_analytics_overview(): void
    {
        $campaign = $this->makeCampaign(['name' => 'Overview Campaign']);
        $h = $this->auth($this->adminA, $this->clientA);
        $this->postJson("/api/v1/organizations/{$this->clientA->id}/campaigns/{$campaign->id}/spend", [
            'date' => now()->toDateString(), 'spend' => 9000,
        ], $h)->assertCreated();
        $l1 = $this->makeLead(['full_name' => 'One', 'phone' => '03002220001', 'city' => 'Lahore']);
        $l2 = $this->makeLead(['full_name' => 'Two', 'phone' => '03002220002', 'city' => 'Lahore']);
        $this->makeLead(['full_name' => 'Three', 'phone' => '03002220003', 'city' => 'Karachi']);
        $this->putJson("/api/v1/organizations/{$this->clientA->id}/leads/{$l1->id}", ['lead_pipeline_stage_id' => $this->stageId('qualified')], $h)->assertOk();
        $this->putJson("/api/v1/organizations/{$this->clientA->id}/leads/{$l2->id}", ['lead_pipeline_stage_id' => $this->stageId('converted')], $h)->assertOk();

        $res = $this->getJson("/api/v1/organizations/{$this->clientA->id}/analytics/overview", $h);
        $res->assertOk();
        $this->assertSame(3, $res->json('data.leads_received'));
        $this->assertSame(2, $res->json('data.qualified_leads'));
        $this->assertSame(1, $res->json('data.won_leads'));
        $this->assertSame(9000.0, (float) $res->json('data.campaign_spend'));
        $this->assertSame(3000.0, (float) $res->json('data.cost_per_lead'));
        $funnelTotal = collect($res->json('data.funnel'))->sum('count');
        $this->assertSame(3, $funnelTotal);
        $this->assertNull($res->json('data.social_engagement')); // not provided, not zero
    }

    public function test_agency_overview_aggregates_clients(): void
    {
        $this->makeLead(['full_name' => 'A Lead', 'phone' => '03003330001']);
        $resB = $this->postJson("/api/v1/organizations/{$this->clientB->id}/leads", [
            'full_name' => 'B Lead', 'phone' => '03003330002',
        ], $this->auth($this->adminB, $this->clientB));
        $resB->assertCreated();

        $res = $this->getJson("/api/v1/organizations/{$this->agency->id}/analytics/agency", $this->auth($this->analyst, $this->agency));
        $res->assertOk();
        $this->assertSame(2, $res->json('data.totals.leads'));
        $this->assertGreaterThanOrEqual(2, $res->json('data.total_clients'));
    }

    public function test_exports_formats_permissions_and_audit(): void
    {
        $this->makeLead(['full_name' => 'Export Lead', 'phone' => '03004440001']);
        $h = $this->auth($this->adminA, $this->clientA);

        $csv = $this->get("/api/v1/organizations/{$this->clientA->id}/analytics/export/leads?format=csv", $h);
        $csv->assertOk();
        $this->assertStringContainsString('Export Lead', $csv->getContent());
        $this->assertTrue(AuditLog::where('action', 'analytics.exported')->exists());

        $xlsx = $this->get("/api/v1/organizations/{$this->clientA->id}/analytics/export/funnel?format=xlsx", $h);
        $xlsx->assertOk();
        $this->assertSame('PK', substr($xlsx->getContent(), 0, 2)); // zip signature

        $pdf = $this->get("/api/v1/organizations/{$this->clientA->id}/analytics/export/leads?format=pdf", $h);
        $pdf->assertOk();
        $this->assertSame('%PDF', substr($pdf->getContent(), 0, 4));

        $this->getJson("/api/v1/organizations/{$this->clientA->id}/analytics/export/nope?format=csv", $h)->assertNotFound();
    }

    public function test_campaign_tenant_isolation(): void
    {
        $campaign = $this->makeCampaign();
        $this->getJson("/api/v1/organizations/{$this->clientB->id}/campaigns/{$campaign->id}", $this->auth($this->adminB, $this->clientB))->assertNotFound();
        $compare = $this->getJson("/api/v1/organizations/{$this->clientB->id}/campaigns/compare?ids={$campaign->id},{$campaign->id}", $this->auth($this->adminB, $this->clientB));
        $compare->assertOk();
        $this->assertCount(0, $compare->json('data'));
    }

    public function test_campaign_compare(): void
    {
        $a = $this->makeCampaign(['name' => 'Alpha']);
        $b = $this->makeCampaign(['name' => 'Beta']);
        $res = $this->getJson("/api/v1/organizations/{$this->clientA->id}/campaigns/compare?ids={$a->id},{$b->id}", $this->auth($this->adminA, $this->clientA));
        $res->assertOk();
        $this->assertCount(2, $res->json('data'));
        $this->assertSame('Alpha', $res->json('data.0.campaign.name'));
    }
}
