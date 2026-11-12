<?php
namespace Tests\Feature;

use App\Models\BillingInvoice;
use App\Models\ContentPost;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\SocialPlatform;
use App\Models\User;
use App\Services\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdvancedIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $agency;
    private Organization $clientA;
    private User $agencyAdmin;
    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\SocialPlatformSeeder::class);
        $roles = app(RoleProvisioner::class);
        $mk = function (string $type) use ($roles) {
            $org = Organization::factory()->create(['type' => $type]);
            $roles->provisionFor($org);
            $org->settings()->create([]);
            return $org;
        };
        $this->agency = $mk('agency');
        $this->clientA = $mk('real_estate_client');
        $member = function (Organization $org, string $role) use ($roles) {
            $user = User::factory()->create();
            $org->users()->attach($user->id, ['status' => 'active', 'joined_at' => now()]);
            $roles->assign($org, $user, $role);
            return $user;
        };
        $this->agencyAdmin = $member($this->agency, 'agency_admin');
        $this->adminA = $member($this->clientA, 'client_admin');
    }

    private function auth(User $user, Organization $org): array
    {
        auth()->forgetGuards();
        return ['Authorization' => 'Bearer ' . $user->createToken('t')->plainTextToken, 'X-Organization-Id' => (string) $org->id];
    }

    private function configureLinkedIn(): void
    {
        config([
            'services.linkedin.client_id' => 'li-client',
            'services.linkedin.client_secret' => 'li-secret',
            'services.linkedin.redirect' => 'http://localhost:8000/api/v1/social/linkedin/callback',
        ]);
    }

    private function fakeLinkedInAuth(): void
    {
        Http::fake([
            'www.linkedin.com/oauth/v2/accessToken' => Http::response([
                'access_token' => 'li-token', 'expires_in' => 5184000, 'refresh_token' => 'li-refresh',
                'scope' => 'openid profile w_member_social r_organization_social w_organization_social',
            ]),
            'api.linkedin.com/v2/userinfo' => Http::response(['sub' => 'member-1', 'name' => 'Agency Person']),
            'api.linkedin.com/rest/organizationAcls*' => Http::response(['elements' => [[
                'role' => 'ADMINISTRATOR', 'organization' => 'urn:li:organization:777', 'state' => 'APPROVED',
                'organization~' => ['localizedName' => 'Acme Estates', 'vanityName' => 'acme-estates'],
            ]]]),
        ]);
    }

    public function test_linkedin_connect_requires_server_configuration(): void
    {
        config(['services.linkedin.client_id' => null, 'services.linkedin.client_secret' => null]);
        $res = $this->getJson("/api/v1/organizations/{$this->clientA->id}/social/connect/linkedin", $this->auth($this->adminA, $this->clientA));
        $res->assertStatus(503);
        $this->assertStringContainsString('Community Management API', $res->json('error.message'));
    }

    public function test_linkedin_oauth_flow_connects_administered_page(): void
    {
        $this->configureLinkedIn();
        $this->fakeLinkedInAuth();
        $h = $this->auth($this->adminA, $this->clientA);
        $start = $this->getJson("/api/v1/organizations/{$this->clientA->id}/social/connect/linkedin", $h);
        $start->assertOk();
        $url = $start->json('data.url');
        $this->assertStringContainsString('linkedin.com/oauth/v2/authorization', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertArrayHasKey('state', $q);

        $callback = $this->get('/api/v1/social/linkedin/callback?code=abc&state=' . $q['state']);
        $callback->assertRedirect();
        $this->assertStringContainsString('linkedin=connected&accounts=1', $callback->headers->get('Location'));

        $account = SocialAccount::where('organization_id', $this->clientA->id)->where('external_id', '777')->first();
        $this->assertNotNull($account);
        $this->assertSame('Acme Estates', $account->display_name);
        // Token encrypted at rest and absent from the API payload.
        $raw = \DB::table('social_account_tokens')->where('social_account_id', $account->id)->value('access_token');
        $this->assertNotSame('li-token', $raw);
        $list = $this->getJson("/api/v1/organizations/{$this->clientA->id}/social/accounts", $h);
        $this->assertStringNotContainsString('li-token', $list->getContent());
        $this->assertTrue($account->permissions()->where('permission', 'w_organization_social')->where('status', 'granted')->exists());
    }

    private function makeLinkedInAccount(): SocialAccount
    {
        $platform = SocialPlatform::where('key', 'linkedin')->firstOrFail();
        $account = SocialAccount::withoutTenantScope()->create([
            'organization_id' => $this->clientA->id, 'social_platform_id' => $platform->id,
            'external_id' => '777', 'display_name' => 'Acme Estates', 'username' => 'acme-estates',
            'status' => 'connected', 'last_synced_at' => now(),
        ]);
        $account->token()->create(['token_type' => 'member_access_token', 'access_token' => 'li-token', 'scopes' => []]);
        $account->permissions()->create(['permission' => 'w_organization_social', 'status' => 'granted']);
        return $account;
    }

    public function test_linkedin_text_post_publishes_via_posts_api(): void
    {
        $account = $this->makeLinkedInAccount();
        Http::fake([
            'api.linkedin.com/rest/posts' => Http::response('', 201, ['x-restli-id' => 'urn:li:share:999']),
        ]);
        $h = $this->auth($this->adminA, $this->clientA);
        $post = $this->postJson("/api/v1/organizations/{$this->clientA->id}/content/posts", [
            'caption' => 'New project launch in DHA Lahore.', 'targets' => [['social_account_id' => $account->id]],
        ], $h);
        $post->assertCreated();
        $this->postJson("/api/v1/organizations/{$this->clientA->id}/content/posts/{$post->json('data.id')}/publish", [], $h)->assertOk();
        $fresh = ContentPost::find($post->json('data.id'));
        $target = $fresh->targets()->first();
        $this->assertSame('published', $target->status);
        $this->assertSame('urn:li:share:999', $target->platform_post_id);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/rest/posts')
            && $request->data()['author'] === 'urn:li:organization:777'
            && $request->hasHeader('LinkedIn-Version'));
    }

    public function test_linkedin_rejects_video_content_honestly(): void
    {
        $account = $this->makeLinkedInAccount();
        $publisher = app(\App\Services\Publishing\LinkedInPublisher::class);
        $this->assertSame('linkedin', $publisher->platform());
        $caps = $publisher->getCapabilities();
        $this->assertFalse($caps['video']);
        $this->assertTrue($caps['text']);
    }

    public function test_linkedin_test_connection_marks_expired_on_401(): void
    {
        $account = $this->makeLinkedInAccount();
        Http::fake(['api.linkedin.com/rest/organizations/777' => Http::response(['message' => 'Expired'], 401)]);
        $res = $this->postJson("/api/v1/organizations/{$this->clientA->id}/social/accounts/{$account->id}/test", [], $this->auth($this->adminA, $this->clientA));
        $res->assertOk();
        $this->assertSame('expired', $account->fresh()->status);
    }

    public function test_ai_assistant_unavailable_without_key(): void
    {
        config(['services.ai.key' => null]);
        $res = $this->postJson("/api/v1/organizations/{$this->clientA->id}/ai/caption-suggestions", [
            'property' => ['title' => '10 Marla House, DHA'],
        ], $this->auth($this->adminA, $this->clientA));
        $res->assertStatus(422);
        $this->assertStringContainsString('AI_API_KEY', json_encode($res->json()));
    }

    public function test_ai_assistant_returns_review_marked_suggestions_with_guardrails(): void
    {
        config(['services.ai.key' => 'test-key']);
        $sentBody = null;
        Http::fake(function ($request) use (&$sentBody) {
            $sentBody = $request->body();
            return Http::response(['choices' => [['message' => ['content' => json_encode(['variations' => [
                ['caption' => 'Beautiful 10 Marla house in DHA Lahore.', 'hashtags' => ['#DHA', '#Lahore'], 'cta' => 'Call now'],
                ['caption' => 'Spacious family home, DHA.', 'hashtags' => ['#RealEstate'], 'cta' => 'Book a visit'],
                ['caption' => 'Your next home in DHA awaits.', 'hashtags' => [], 'cta' => 'DM us'],
            ]])]]]]);
        });
        $res = $this->postJson("/api/v1/organizations/{$this->clientA->id}/ai/caption-suggestions", [
            'platform' => 'linkedin', 'language' => 'en',
            'property' => ['title' => '10 Marla House', 'city' => 'Lahore', 'area' => 'DHA'],
        ], $this->auth($this->adminA, $this->clientA));
        $res->assertOk();
        $this->assertCount(3, $res->json('data.suggestions'));
        $this->assertTrue($res->json('data.suggestions.0.ai_generated'));
        $this->assertTrue($res->json('data.suggestions.0.needs_review'));
        // Guardrail language must be in the system prompt sent to the provider.
        $this->assertStringContainsString('Never invent', (string) $sentBody);
        $this->assertStringContainsString('ONLY the property facts', (string) $sentBody);
        // History is stored.
        $history = $this->getJson("/api/v1/organizations/{$this->clientA->id}/ai/generations", $this->auth($this->adminA, $this->clientA));
        $this->assertCount(1, $history->json('data'));
    }

    public function test_packages_subscriptions_limits_and_invoices(): void
    {
        $hAgency = $this->auth($this->agencyAdmin, $this->agency);
        $pkg = $this->postJson("/api/v1/organizations/{$this->agency->id}/billing/packages", [
            'name' => 'Starter', 'price' => 25000, 'limits' => ['max_leads_per_month' => 2, 'max_social_accounts' => 1],
        ], $hAgency);
        $pkg->assertCreated();
        $packageId = $pkg->json('data.id');

        // Packages cannot be managed under a client org route.
        $this->postJson("/api/v1/organizations/{$this->clientA->id}/billing/packages", ['name' => 'X'], $this->auth($this->adminA, $this->clientA))->assertNotFound();

        // Re-auth: the test guard caches the previously resolved user (see Phase 4 lesson).
        $sub = $this->postJson("/api/v1/organizations/{$this->agency->id}/billing/subscriptions", [
            'client_organization_id' => $this->clientA->id, 'service_package_id' => $packageId, 'status' => 'active',
        ], $this->auth($this->agencyAdmin, $this->agency));
        $sub->assertCreated();

        $h = $this->auth($this->adminA, $this->clientA);
        $overview = $this->getJson("/api/v1/organizations/{$this->clientA->id}/billing", $h);
        $overview->assertOk();
        $this->assertSame('Starter', $overview->json('data.subscription.package.name'));
        $this->assertSame(2, $overview->json('data.limits.max_leads_per_month'));

        // Plan limit enforcement: 2 leads OK, 3rd blocked with a clear 422.
        $this->postJson("/api/v1/organizations/{$this->clientA->id}/leads", ['full_name' => 'L1', 'phone' => '03005550001'], $h)->assertCreated();
        $this->postJson("/api/v1/organizations/{$this->clientA->id}/leads", ['full_name' => 'L2', 'phone' => '03005550002'], $h)->assertCreated();
        $blocked = $this->postJson("/api/v1/organizations/{$this->clientA->id}/leads", ['full_name' => 'L3', 'phone' => '03005550003'], $h);
        $blocked->assertStatus(422);
        $this->assertStringContainsString('Plan limit reached', json_encode($blocked->json()));

        // client_admin holds billing.view only: creating an invoice is forbidden.
        $this->postJson("/api/v1/organizations/{$this->clientA->id}/billing/invoices", ['amount' => 25000], $h)->assertForbidden();
    }

    public function test_invoice_lifecycle_with_billing_manager(): void
    {
        // Give the client org a billing manager via client_owner ('*' permissions).
        $roles = app(RoleProvisioner::class);
        $owner = User::factory()->create();
        $this->clientA->users()->attach($owner->id, ['status' => 'active', 'joined_at' => now()]);
        $roles->assign($this->clientA, $owner, 'client_owner');
        $h = $this->auth($owner, $this->clientA);
        $invoice = $this->postJson("/api/v1/organizations/{$this->clientA->id}/billing/invoices", ['amount' => 25000], $h);
        $invoice->assertCreated();
        $this->assertSame('INV-0001', $invoice->json('data.number'));
        $paid = $this->putJson("/api/v1/organizations/{$this->clientA->id}/billing/invoices/{$invoice->json('data.id')}", [
            'status' => 'paid', 'payment_method' => 'jazzcash',
        ], $h);
        $paid->assertOk();
        $this->assertSame('paid', $paid->json('data.status'));
        $this->assertNotNull($paid->json('data.paid_at'));
        $this->assertSame(1, BillingInvoice::count());
    }

    public function test_white_label_branding_settings_round_trip(): void
    {
        $h = $this->auth($this->adminA, $this->clientA);
        $res = $this->putJson("/api/v1/organizations/{$this->clientA->id}/settings", [
            'settings' => ['branding' => ['brand_name' => 'Acme Properties CRM', 'primary_color' => '#0f766e', 'logo_url' => 'https://example.com/logo.png']],
        ], $h);
        $res->assertOk();
        $this->assertSame('Acme Properties CRM', $res->json('data.settings.branding.brand_name'));
        $bad = $this->putJson("/api/v1/organizations/{$this->clientA->id}/settings", [
            'settings' => ['branding' => ['primary_color' => 'teal']],
        ], $h);
        $bad->assertStatus(422);
    }

    public function test_analytics_period_comparison(): void
    {
        $h = $this->auth($this->adminA, $this->clientA);
        $old = $this->postJson("/api/v1/organizations/{$this->clientA->id}/leads", ['full_name' => 'Old Lead', 'phone' => '03006660001'], $h);
        $old->assertCreated();
        Lead::find($old->json('data.id'))->forceFill(['created_at' => now()->subDays(40)])->save();
        $this->postJson("/api/v1/organizations/{$this->clientA->id}/leads", ['full_name' => 'New Lead', 'phone' => '03006660002'], $h)->assertCreated();
        $this->postJson("/api/v1/organizations/{$this->clientA->id}/leads", ['full_name' => 'New Lead 2', 'phone' => '03006660003'], $h)->assertCreated();

        $res = $this->getJson("/api/v1/organizations/{$this->clientA->id}/analytics/overview", $h);
        $res->assertOk();
        $this->assertSame(2, $res->json('data.leads_received'));
        $this->assertSame(1, $res->json('data.previous_period.leads_received'));
        $this->assertSame(100.0, (float) $res->json('data.previous_period.deltas_percent.leads'));
    }
}
