<?php
namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadCampaign;
use App\Models\Organization;
use App\Models\User;
use App\Services\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadCampaignsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $clientA;
    private Organization $clientB;
    private User $adminA;
    private User $adminB;
    private User $agentA;

    protected function setUp(): void
    {
        parent::setUp();
        $provisioner = app(RoleProvisioner::class);
        $mk = function () use ($provisioner) {
            $org = Organization::factory()->create();
            $provisioner->provisionFor($org);
            $org->settings()->create([]);
            return $org;
        };
        $this->clientA = $mk();
        $this->clientB = $mk();
        $member = function (Organization $org, string $email, string $role) {
            $user = User::create(['name' => $email, 'email' => $email, 'password' => bcrypt('password')]);
            $org->users()->attach($user->id, ['status' => 'active', 'joined_at' => now()]);
            app(RoleProvisioner::class)->assign($org, $user, $role);
            return $user;
        };
        $this->adminA = $member($this->clientA, 'admin-a@test.pk', 'client_admin');
        $this->adminB = $member($this->clientB, 'admin-b@test.pk', 'client_admin');
        $this->agentA = $member($this->clientA, 'agent-a@test.pk', 'client_sales_agent');
    }

    private function auth(User $user, Organization $org): array
    {
        auth()->forgetGuards();
        return ['Authorization' => 'Bearer ' . $user->createToken('t')->plainTextToken, 'X-Organization-Id' => (string) $org->id];
    }

    private function makeCampaign(Organization $org, array $overrides = []): LeadCampaign
    {
        return LeadCampaign::withoutTenantScope()->create(array_merge([
            'organization_id' => $org->id,
            'name' => 'Campaign',
            'vertical' => 'real_estate',
            'keywords' => ['plot'],
            'sources' => [],
            'webhook_key' => 'test-key-' . uniqid(),
            'is_active' => true,
        ], $overrides));
    }

    public function test_presets_include_real_estate_and_automobile(): void
    {
        $res = $this->getJson("/api/v1/organizations/{$this->clientA->id}/lead-campaign-presets", $this->auth($this->adminA, $this->clientA));
        $res->assertOk();
        $keys = collect($res->json('data.verticals'))->pluck('key');
        $this->assertTrue($keys->contains('real_estate'));
        $this->assertTrue($keys->contains('automobile'));
        $this->assertTrue($keys->contains('custom'));
    }

    public function test_admin_can_create_and_list_campaigns_agent_cannot_create(): void
    {
        $res = $this->postJson("/api/v1/organizations/{$this->clientA->id}/lead-campaigns", [
            'name' => 'Cars Karachi', 'vertical' => 'automobile',
            'keywords' => ['car', 'test drive'], 'locations' => ['Karachi'], 'sources' => ['webhook'],
        ], $this->auth($this->adminA, $this->clientA));
        $res->assertCreated()->assertJsonPath('data.vertical_label', 'Automobile');
        $this->assertNotEmpty($res->json('data.webhook_url'));

        $list = $this->getJson("/api/v1/organizations/{$this->clientA->id}/lead-campaigns", $this->auth($this->adminA, $this->clientA));
        $list->assertOk()->assertJsonCount(1, 'data');

        // Sales agents lack leads.configure.
        $this->postJson("/api/v1/organizations/{$this->clientA->id}/lead-campaigns", [
            'name' => 'Nope', 'vertical' => 'custom',
        ], $this->auth($this->agentA, $this->clientA))->assertForbidden();
    }

    public function test_campaigns_are_tenant_isolated(): void
    {
        $campaign = $this->makeCampaign($this->clientA);
        $list = $this->getJson("/api/v1/organizations/{$this->clientB->id}/lead-campaigns", $this->auth($this->adminB, $this->clientB));
        $list->assertOk()->assertJsonCount(0, 'data');
        $this->putJson("/api/v1/organizations/{$this->clientB->id}/lead-campaigns/{$campaign->id}", [
            'name' => 'Hijacked',
        ], $this->auth($this->adminB, $this->clientB))->assertNotFound();
        $this->assertSame('Campaign', $campaign->fresh()->name);
    }

    public function test_keyword_matching_tags_incoming_lead(): void
    {
        $campaign = $this->makeCampaign($this->clientA, ['keywords' => ['plot', 'dha']]);
        $res = $this->postJson("/api/v1/organizations/{$this->clientA->id}/leads", [
            'full_name' => 'Ahmed Raza', 'phone' => '0300-1234567',
            'notes' => 'Wants a 10 marla plot in DHA Phase 6',
        ], $this->auth($this->adminA, $this->clientA));
        $res->assertCreated();
        $this->assertSame($campaign->id, $res->json('data.lead_campaign_id'));

        // No keyword -> no tag.
        $res2 = $this->postJson("/api/v1/organizations/{$this->clientA->id}/leads", [
            'full_name' => 'Bilal Khan', 'phone' => '0321-7654321', 'notes' => 'General enquiry',
        ], $this->auth($this->adminA, $this->clientA));
        $res2->assertCreated();
        $this->assertNull($res2->json('data.lead_campaign_id'));
    }

    public function test_source_filter_and_location_filter_are_respected(): void
    {
        // Campaign listens only to Meta, but the lead arrives manually.
        $this->makeCampaign($this->clientA, ['name' => 'Meta only', 'keywords' => ['plot'], 'sources' => ['meta_lead_ads']]);
        $res = $this->postJson("/api/v1/organizations/{$this->clientA->id}/leads", [
            'full_name' => 'Ahmed Raza', 'phone' => '0300-1234567', 'notes' => 'plot enquiry',
        ], $this->auth($this->adminA, $this->clientA));
        $this->assertNull($res->json('data.lead_campaign_id'));

        // Location filter: campaign is Karachi-only, lead is in Lahore.
        $this->makeCampaign($this->clientA, ['name' => 'Karachi plots', 'keywords' => ['plot'], 'locations' => ['Karachi'], 'sources' => []]);
        $res2 = $this->postJson("/api/v1/organizations/{$this->clientA->id}/leads", [
            'full_name' => 'Bilal Khan', 'phone' => '0321-7654321', 'city' => 'Lahore', 'notes' => 'plot enquiry',
        ], $this->auth($this->adminA, $this->clientA));
        $this->assertNull($res2->json('data.lead_campaign_id'));
    }

    public function test_paused_campaign_does_not_capture(): void
    {
        $this->makeCampaign($this->clientA, ['is_active' => false]);
        $res = $this->postJson("/api/v1/organizations/{$this->clientA->id}/leads", [
            'full_name' => 'Ahmed Raza', 'phone' => '0300-1234567', 'notes' => 'plot enquiry',
        ], $this->auth($this->adminA, $this->clientA));
        $this->assertNull($res->json('data.lead_campaign_id'));
    }

    public function test_public_webhook_flat_payload_creates_tagged_lead(): void
    {
        $campaign = $this->makeCampaign($this->clientA, ['webhook_key' => 'flat-key-123']);
        $res = $this->postJson('/api/v1/public/lead-campaigns/flat-key-123', [
            'full_name' => 'Sara Malik', 'phone' => '0333-1112233', 'city' => 'Lahore',
            'message' => 'Interested in a plot', 'interested_in' => '10 marla',
        ]);
        $res->assertOk()->assertJsonPath('data.outcome', 'created');
        $lead = Lead::first();
        $this->assertSame($campaign->id, $lead->lead_campaign_id);
        $this->assertSame('webhook', $lead->source->key);
        $this->assertSame('10 marla', $lead->custom_fields['interested_in'] ?? null);
    }

    public function test_public_webhook_google_ads_shape_maps_fields(): void
    {
        $campaign = $this->makeCampaign($this->clientA, ['webhook_key' => 'google-key-123']);
        $res = $this->postJson('/api/v1/public/lead-campaigns/google-key-123', [
            'lead_id' => 'g-1', 'campaign_id' => 123, 'form_id' => 9,
            'user_column_data' => [
                ['column_name' => 'FULL_NAME', 'string_value' => 'Usman Tariq'],
                ['column_name' => 'PHONE_NUMBER', 'string_value' => '0300-9998877'],
                ['column_name' => 'EMAIL', 'string_value' => 'usman@example.com'],
                ['column_name' => 'CITY', 'string_value' => 'Karachi'],
            ],
        ]);
        $res->assertOk()->assertJsonPath('data.outcome', 'created');
        $lead = Lead::first();
        $this->assertSame('Usman Tariq', $lead->full_name);
        $this->assertSame('+923009998877', $lead->phone_e164);
        $this->assertSame($campaign->id, $lead->lead_campaign_id);
    }

    public function test_public_webhook_rejects_unknown_key_and_contactless_payload(): void
    {
        $this->postJson('/api/v1/public/lead-campaigns/nope-key', ['full_name' => 'X', 'phone' => '03001234567'])->assertNotFound();
        $this->makeCampaign($this->clientA, ['webhook_key' => 'some-key-9']);
        $this->postJson('/api/v1/public/lead-campaigns/some-key-9', ['full_name' => 'No Contact'])->assertStatus(422);
    }
}
