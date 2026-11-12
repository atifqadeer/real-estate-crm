<?php
namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadFollowUp;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\SocialAccountToken;
use App\Models\SocialPlatform;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LeadGenerationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $clientA;
    private Organization $clientB;
    private User $adminA;
    private User $adminB;
    private User $agent1;
    private User $agent2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\SocialPlatformSeeder::class);
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
        $this->agent1 = $member($this->clientA, 'agent1@test.pk', 'client_sales_agent');
        $this->agent2 = $member($this->clientA, 'agent2@test.pk', 'client_sales_agent');
    }

    private function auth(User $user, Organization $org): array
    {
        // The test app instance is shared across requests in one test and the
        // auth guard caches the first resolved user; clear it so each call
        // really authenticates as the given user.
        auth()->forgetGuards();
        return ['Authorization' => 'Bearer ' . $user->createToken('t')->plainTextToken, 'X-Organization-Id' => (string) $org->id];
    }

    private function metaPage(): SocialAccount
    {
        $account = SocialAccount::create([
            'organization_id' => $this->clientA->id,
            'social_platform_id' => SocialPlatform::where('key', 'facebook')->value('id'),
            'external_id' => 'page-1', 'display_name' => 'Client A Estates', 'status' => 'connected',
            'connected_by' => $this->adminA->id, 'connected_at' => now(),
        ]);
        SocialAccountToken::create([
            'social_account_id' => $account->id, 'token_type' => 'page',
            'access_token' => 'page-token', 'scopes' => ['leads_retrieval'],
        ]);
        return $account;
    }

    public function test_manual_lead_normalizes_phone_and_gets_default_stage(): void
    {
        $res = $this->postJson("/api/v1/organizations/{$this->clientA->id}/leads", [
            'full_name' => 'Ahmed Raza', 'phone' => '0300-1234567', 'city' => 'Lahore', 'intent' => 'buy',
        ], $this->auth($this->adminA, $this->clientA));
        $res->assertCreated()->assertJsonPath('outcome', 'created');
        $this->assertSame('+923001234567', $res->json('data.phone_e164'));
        $this->assertSame('new', $res->json('data.stage.slug'));

        $stages = $this->getJson("/api/v1/organizations/{$this->clientA->id}/lead-stages", $this->auth($this->adminA, $this->clientA));
        $stages->assertOk();
        $this->assertCount(8, $stages->json('data'));
    }

    public function test_duplicate_submission_becomes_touchpoint_not_new_lead(): void
    {
        $payload = ['full_name' => 'Ahmed Raza', 'phone' => '0300 1234567'];
        $this->postJson("/api/v1/organizations/{$this->clientA->id}/leads", $payload, $this->auth($this->adminA, $this->clientA))->assertCreated();

        $res = $this->postJson("/api/v1/organizations/{$this->clientA->id}/leads", $payload, $this->auth($this->adminA, $this->clientA));
        $res->assertOk()->assertJsonPath('outcome', 'touchpoint');
        $this->assertSame(1, Lead::count());
        $lead = Lead::first();
        $this->assertSame(2, $lead->touchpoints()->count());
        $this->assertTrue($lead->activities()->where('type', 'touchpoint')->exists());
    }

    public function test_same_phone_different_person_is_flagged_for_review(): void
    {
        $this->postJson("/api/v1/organizations/{$this->clientA->id}/leads", [
            'full_name' => 'Ahmed Raza', 'phone' => '03001112223',
        ], $this->auth($this->adminA, $this->clientA))->assertCreated();

        $res = $this->postJson("/api/v1/organizations/{$this->clientA->id}/leads", [
            'full_name' => 'Bilal Hussain', 'phone' => '+92 300 1112223',
        ], $this->auth($this->adminA, $this->clientA));
        $res->assertOk()->assertJsonPath('outcome', 'possible_duplicate');
        $this->assertSame(2, Lead::count());
        $flagged = Lead::where('full_name', 'Bilal Hussain')->first();
        $this->assertNotNull($flagged->possible_duplicate_of);

        $list = $this->getJson("/api/v1/organizations/{$this->clientA->id}/leads?possible_duplicates=1", $this->auth($this->adminA, $this->clientA));
        $this->assertSame(1, $list->json('total'));
    }

    public function test_round_robin_assignment_and_city_rule(): void
    {
        foreach ([1, 2, 3] as $i) {
            $this->postJson("/api/v1/organizations/{$this->clientA->id}/leads", [
                'full_name' => "Buyer {$i}", 'phone' => "0300111000{$i}",
            ], $this->auth($this->adminA, $this->clientA))->assertCreated();
        }
        $assignees = Lead::orderBy('id')->pluck('assigned_to')->all();
        $this->assertSame([$this->agent1->id, $this->agent2->id, $this->agent1->id], $assignees);

        // City rule beats round robin.
        $this->postJson("/api/v1/organizations/{$this->clientA->id}/lead-rules", [
            'match_type' => 'city', 'match_value' => 'Karachi', 'assign_to_user_id' => $this->agent2->id,
        ], $this->auth($this->adminA, $this->clientA))->assertCreated();
        $this->postJson("/api/v1/organizations/{$this->clientA->id}/leads", [
            'full_name' => 'Karachi Buyer', 'phone' => '03009998887', 'city' => 'Karachi',
        ], $this->auth($this->adminA, $this->clientA))->assertCreated();
        $this->assertSame($this->agent2->id, Lead::where('full_name', 'Karachi Buyer')->value('assigned_to'));
    }

    public function test_csv_import_reports_outcomes(): void
    {
        $csv = "Full Name,Phone Number,Email,City\n"
            . "Ahmed Raza,0300-1234567,ahmed@x.pk,Lahore\n"
            . "Ahmed Raza,03001234567,ahmed@x.pk,Lahore\n"
            . "No Contact,,,Lahore\n"
            . "Bilal Hussain,03001234567,,Lahore\n";
        $file = UploadedFile::fake()->createWithContent('leads.csv', $csv);
        $res = $this->postJson("/api/v1/organizations/{$this->clientA->id}/lead-imports", ['file' => $file], $this->auth($this->adminA, $this->clientA));
        $res->assertCreated()
            ->assertJsonPath('report.total', 4)
            ->assertJsonPath('report.created', 1)
            ->assertJsonPath('report.touchpoints', 1)
            ->assertJsonPath('report.invalid', 1)
            ->assertJsonPath('report.possible_duplicates', 1);
        $this->assertSame(2, Lead::count());
    }

    public function test_website_form_flow_with_honeypot_and_utm(): void
    {
        $form = $this->postJson("/api/v1/organizations/{$this->clientA->id}/lead-forms", [
            'name' => 'DHA Listing Enquiry', 'property_reference' => 'DHA-6-123',
        ], $this->auth($this->adminA, $this->clientA))->assertCreated()->json('data');
        $key = $form['public_key'];

        $res = $this->postJson("/api/v1/public/forms/{$key}/submit", [
            'full_name' => 'Sara Khan', 'phone' => '0321-5556667',
            'utm_source' => 'google', 'utm_campaign' => 'dha-launch',
        ]);
        $res->assertOk();
        $lead = Lead::where('full_name', 'Sara Khan')->first();
        $this->assertNotNull($lead);
        $this->assertSame('website_form', $lead->source->key);
        $this->assertSame('dha-launch', $lead->utm_campaign);
        $this->assertSame('DHA-6-123', $lead->property_reference);

        // Honeypot: bot submission silently dropped.
        $this->postJson("/api/v1/public/forms/{$key}/submit", [
            'full_name' => 'Bot', 'phone' => '03000000001', 'website' => 'http://spam.example',
        ])->assertOk();
        $this->assertSame(1, Lead::count());
    }

    public function test_meta_webhook_end_to_end_and_redelivery_is_safe(): void
    {
        $this->metaPage();
        config(['services.meta.app_secret' => 'testsecret', 'services.meta.webhook_verify_token' => 'vtok']);
        Http::fake([
            'graph.facebook.com/v27.0/leadgen-9*' => Http::response([
                'id' => 'leadgen-9', 'created_time' => '2026-11-12T10:00:00+0000', 'ad_id' => 'ad-55', 'form_id' => 'form-77',
                'field_data' => [
                    ['name' => 'full_name', 'values' => ['Ahmed Raza']],
                    ['name' => 'email', 'values' => ['ahmed@x.pk']],
                    ['name' => 'phone_number', 'values' => ['+923001234567']],
                    ['name' => 'preferred_area?', 'values' => ['DHA Phase 6']],
                ],
            ]),
            'graph.facebook.com/v27.0/ad-55*' => Http::response(['name' => 'DHA Launch Ad']),
        ]);

        // Verification handshake.
        $ok = $this->get('/api/v1/webhooks/meta?hub.mode=subscribe&hub.verify_token=vtok&hub.challenge=987654');
        $ok->assertOk();
        $this->assertSame('987654', $ok->getContent());
        $this->get('/api/v1/webhooks/meta?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=1')->assertForbidden();

        $payload = json_encode(['object' => 'page', 'entry' => [[
            'id' => 'page-1', 'time' => time(),
            'changes' => [['field' => 'leadgen', 'value' => [
                'leadgen_id' => 'leadgen-9', 'page_id' => 'page-1', 'form_id' => 'form-77',
                'ad_id' => 'ad-55', 'adgroup_id' => 'ag-3', 'created_time' => time(),
            ]]],
        ]]]);
        $signature = 'sha256=' . hash_hmac('sha256', $payload, 'testsecret');
        $send = fn () => $this->call('POST', '/api/v1/webhooks/meta', [], [], [], [
            'HTTP_X-Hub-Signature-256' => $signature, 'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $send()->assertOk();
        $this->assertSame(1, WebhookEvent::count());
        $this->assertSame('processed', WebhookEvent::first()->status);
        $lead = Lead::where('meta_lead_id', 'leadgen-9')->first();
        $this->assertNotNull($lead);
        $this->assertSame('meta_lead_ads', $lead->source->key);
        $this->assertSame('+923001234567', $lead->phone_e164);
        $this->assertSame('DHA Launch Ad', $lead->ad_name);
        $this->assertSame('DHA Phase 6', $lead->custom_fields['preferred_area?'] ?? null);

        $send()->assertOk(); // redelivery: no new event, no duplicate lead
        $this->assertSame(1, WebhookEvent::count());
        $this->assertSame(1, Lead::count());

        // Bad signature rejected.
        $this->call('POST', '/api/v1/webhooks/meta', [], [], [], [
            'HTTP_X-Hub-Signature-256' => 'sha256=deadbeef', 'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertForbidden();
    }

    public function test_backfill_imports_form_leads_and_skips_known(): void
    {
        $account = $this->metaPage();
        $this->postJson("/api/v1/organizations/{$this->clientA->id}/leads", [
            'full_name' => 'Existing Meta Lead', 'phone' => '03007776665',
        ], $this->auth($this->adminA, $this->clientA))->assertCreated();
        // Simulate that lead having come from Meta earlier:
        Lead::first()->update(['meta_lead_id' => 'leadgen-9']);

        Http::fake([
            'graph.facebook.com/v27.0/form-77/leads*' => Http::response(['data' => [
                ['id' => 'leadgen-9', 'form_id' => 'form-77', 'field_data' => [['name' => 'full_name', 'values' => ['Existing Meta Lead']]]],
                ['id' => 'leadgen-10', 'form_id' => 'form-77', 'field_data' => [
                    ['name' => 'full_name', 'values' => ['New Person']],
                    ['name' => 'phone_number', 'values' => ['03008889990']],
                ]],
            ]]),
        ]);
        $res = $this->postJson("/api/v1/organizations/{$this->clientA->id}/leads/meta/backfill", [
            'social_account_id' => $account->id, 'form_id' => 'form-77',
        ], $this->auth($this->adminA, $this->clientA));
        $res->assertOk()->assertJsonPath('data.fetched', 2)->assertJsonPath('data.created', 1)->assertJsonPath('data.duplicates', 1);
    }

    public function test_followups_and_agent_summary(): void
    {
        $leadId = $this->postJson("/api/v1/organizations/{$this->clientA->id}/leads", [
            'full_name' => 'Follow Me', 'phone' => '03005554443',
        ], $this->auth($this->adminA, $this->clientA))->json('data.id');

        $this->postJson("/api/v1/organizations/{$this->clientA->id}/follow-ups", [
            'lead_id' => $leadId, 'due_at' => now()->format('Y-m-d H:i:s'), 'note' => 'Call today', 'user_id' => $this->agent1->id,
        ], $this->auth($this->adminA, $this->clientA))->assertCreated();
        $this->postJson("/api/v1/organizations/{$this->clientA->id}/follow-ups", [
            'lead_id' => $leadId, 'due_at' => now()->subDays(2)->format('Y-m-d H:i:s'), 'note' => 'Overdue one', 'user_id' => $this->agent1->id,
        ], $this->auth($this->adminA, $this->clientA))->assertCreated();

        $summary = $this->getJson("/api/v1/organizations/{$this->clientA->id}/leads/my-summary", $this->auth($this->agent1, $this->clientA));
        $summary->assertOk()->assertJsonPath('data.followups_due_today', 1)->assertJsonPath('data.followups_overdue', 1);

        $fu = LeadFollowUp::where('note', 'Call today')->first();
        $this->postJson("/api/v1/organizations/{$this->clientA->id}/follow-ups/{$fu->id}/complete", [], $this->auth($this->agent1, $this->clientA))->assertOk();
        $this->assertSame('done', $fu->fresh()->status);
    }

    public function test_lead_tenant_isolation(): void
    {
        $leadId = $this->postJson("/api/v1/organizations/{$this->clientA->id}/leads", [
            'full_name' => 'Private Lead', 'phone' => '03006667778',
        ], $this->auth($this->adminA, $this->clientA))->json('data.id');

        $this->getJson("/api/v1/organizations/{$this->clientB->id}/leads/{$leadId}", $this->auth($this->adminB, $this->clientB))->assertNotFound();
        $list = $this->getJson("/api/v1/organizations/{$this->clientB->id}/leads", $this->auth($this->adminB, $this->clientB));
        $this->assertSame(0, $list->json('total'));
    }
}
