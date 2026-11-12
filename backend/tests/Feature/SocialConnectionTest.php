<?php
namespace Tests\Feature;

use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\SocialAccountToken;
use App\Models\SocialOauthIdentity;
use App\Models\User;
use App\Services\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 2 acceptance tests. The Meta Graph API is faked with Http::fake —
 * these tests prove OUR flow, storage encryption and tenant isolation.
 * They do not claim a live Meta connection (that needs real app
 * credentials + Advanced Access; see docs/07).
 */
class SocialConnectionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;
    private Organization $orgB;
    private User $ownerA;
    private User $ownerB;
    private bool $tokenValid = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\SocialPlatformSeeder::class);
        config([
            'services.meta.app_id' => 'test-app-id',
            'services.meta.app_secret' => 'test-app-secret',
            'services.meta.redirect' => 'http://localhost:8000/api/v1/social/meta/callback',
            'services.meta.graph_version' => 'v27.0',
            'services.meta.frontend_url' => 'http://localhost:5173',
        ]);

        $roles = app(RoleProvisioner::class);
        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();
        foreach ([$this->orgA, $this->orgB] as $org) {
            $org->settings()->create([]);
            $roles->provisionFor($org);
        }
        $this->ownerA = User::factory()->create();
        $this->join($this->orgA, $this->ownerA, 'client_owner');
        $this->ownerB = User::factory()->create();
        $this->join($this->orgB, $this->ownerB, 'client_owner');

        $this->fakeMeta();
    }

    private function join(Organization $org, User $user, string $role): void
    {
        $org->users()->attach($user->id, ['status' => 'active', 'joined_at' => now()]);
        app(RoleProvisioner::class)->assign($org, $user, $role);
    }

    private function fakeMeta(): void
    {
        Http::fake(function (HttpRequest $request) {
            $url = $request->url();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

            if (str_contains($url, '/oauth/access_token')) {
                if (($q['grant_type'] ?? null) === 'fb_exchange_token') {
                    return Http::response(['access_token' => 'LONG_TOKEN', 'expires_in' => 5183944], 200);
                }
                return Http::response(['access_token' => 'SHORT_TOKEN', 'expires_in' => 3600], 200);
            }
            if (str_contains($url, '/debug_token')) {
                return Http::response(['data' => [
                    'app_id' => 'test-app-id',
                    'is_valid' => $this->tokenValid,
                    'user_id' => 'meta-user-1',
                    'expires_at' => 0,
                    // instagram_content_publish deliberately NOT granted
                    'scopes' => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'instagram_basic'],
                ]], 200);
            }
            if (str_ends_with($url, '/me') || str_contains($url, '/me?')) {
                return Http::response(['id' => 'meta-user-1', 'name' => 'Client Admin'], 200);
            }
            if (str_contains($url, '/me/accounts')) {
                return Http::response(['data' => [[
                    'id' => 'page-1',
                    'name' => 'Client A Properties',
                    'username' => 'clientaproperties',
                    'picture' => ['data' => ['url' => 'https://example.test/p.png']],
                    'access_token' => 'PAGE_TOKEN_1',
                    'instagram_business_account' => ['id' => 'ig-1', 'username' => 'clienta.properties'],
                ]]], 200);
            }
            if (str_contains($url, '/ig-1')) {
                return Http::response(['id' => 'ig-1', 'username' => 'clienta.properties', 'profile_picture_url' => 'https://example.test/ig.png'], 200);
            }
            if (str_contains($url, '/page-1')) {
                return Http::response(['id' => 'page-1', 'name' => 'Client A Properties'], 200);
            }
            return Http::response(['error' => ['message' => 'Unknown fake endpoint', 'code' => 100]], 400);
        });
    }

    /** Runs the full connect flow for org A as ownerA and returns the FB account. */
    private function connectOrgA(): SocialAccount
    {
        Sanctum::actingAs($this->ownerA);
        $res = $this->getJson("/api/v1/organizations/{$this->orgA->id}/social/connect/facebook")->assertOk();
        $url = $res->json('data.url');
        $this->assertStringContainsString('facebook.com/v27.0/dialog/oauth', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertNotEmpty($q['state'] ?? null);

        $callback = $this->get("/api/v1/social/meta/callback?code=test-code&state={$q['state']}");
        $callback->assertRedirect();
        $this->assertStringContainsString('meta=connected', $callback->headers->get('Location'));

        return SocialAccount::withoutTenantScope()
            ->where('organization_id', $this->orgA->id)
            ->where('external_id', 'page-1')->firstOrFail();
    }

    public function test_connect_flow_discovers_page_and_instagram(): void
    {
        $fb = $this->connectOrgA();
        $this->assertSame('connected', $fb->status);
        $this->assertSame('Client A Properties', $fb->display_name);

        $ig = SocialAccount::withoutTenantScope()
            ->where('organization_id', $this->orgA->id)
            ->where('external_id', 'ig-1')->firstOrFail();
        $this->assertSame('clienta.properties', $ig->username);
        $this->assertSame('page-1', $ig->metadata['linked_page_id'] ?? null);

        // Declined permission recorded; granted ones too.
        $declined = $fb->permissions()->where('status', 'declined')->pluck('permission')->all();
        $this->assertContains('instagram_content_publish', $declined);
        $granted = $fb->permissions()->where('status', 'granted')->pluck('permission')->all();
        $this->assertContains('pages_show_list', $granted);
    }

    public function test_tokens_are_encrypted_at_rest_and_never_exposed(): void
    {
        $fb = $this->connectOrgA();

        $raw = DB::table('social_account_tokens')->where('social_account_id', $fb->id)->first();
        $this->assertNotSame('PAGE_TOKEN_1', $raw->access_token);
        $this->assertSame('PAGE_TOKEN_1', SocialAccountToken::find($raw->id)->access_token);

        $identityRaw = DB::table('social_oauth_identities')->where('organization_id', $this->orgA->id)->first();
        $this->assertNotSame('LONG_TOKEN', $identityRaw->access_token);
        $this->assertSame('LONG_TOKEN', SocialOauthIdentity::find($identityRaw->id)->access_token);

        Sanctum::actingAs($this->ownerA);
        $res = $this->getJson("/api/v1/organizations/{$this->orgA->id}/social/accounts")->assertOk();
        $this->assertStringNotContainsString('PAGE_TOKEN_1', $res->getContent());
        $this->assertStringNotContainsString('LONG_TOKEN', $res->getContent());
        $this->assertStringNotContainsString('SHORT_TOKEN', $res->getContent());
    }

    public function test_social_accounts_are_tenant_isolated(): void
    {
        $fb = $this->connectOrgA();

        Sanctum::actingAs($this->ownerB);
        $this->getJson("/api/v1/organizations/{$this->orgB->id}/social/accounts")
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/organizations/{$this->orgB->id}/social/accounts/{$fb->id}")->assertNotFound();
        $this->postJson("/api/v1/organizations/{$this->orgB->id}/social/accounts/{$fb->id}/test")->assertNotFound();
        $this->deleteJson("/api/v1/organizations/{$this->orgB->id}/social/accounts/{$fb->id}")->assertNotFound();
    }

    public function test_test_connection_marks_invalid_token_expired(): void
    {
        $fb = $this->connectOrgA();
        $this->tokenValid = false;

        Sanctum::actingAs($this->ownerA);
        $this->postJson("/api/v1/organizations/{$this->orgA->id}/social/accounts/{$fb->id}/test")
            ->assertOk()->assertJsonPath('data.status', 'expired');
        $this->assertDatabaseHas('social_account_logs', [
            'organization_id' => $this->orgA->id, 'social_account_id' => $fb->id, 'event' => 'test_failed',
        ]);
    }

    public function test_disconnect_deletes_token_and_marks_status(): void
    {
        $fb = $this->connectOrgA();

        Sanctum::actingAs($this->ownerA);
        $this->deleteJson("/api/v1/organizations/{$this->orgA->id}/social/accounts/{$fb->id}")->assertOk();
        $this->assertSame('disconnected', $fb->fresh()->status);
        $this->assertDatabaseMissing('social_account_tokens', ['social_account_id' => $fb->id]);
    }

    public function test_connect_requires_meta_credentials(): void
    {
        config(['services.meta.app_id' => '', 'services.meta.app_secret' => '']);
        Sanctum::actingAs($this->ownerA);
        $this->getJson("/api/v1/organizations/{$this->orgA->id}/social/connect/facebook")
            ->assertStatus(503);
    }

    public function test_platforms_registry_marks_unshipped_platforms_not_connectable(): void
    {
        Sanctum::actingAs($this->ownerA);
        $res = $this->getJson("/api/v1/organizations/{$this->orgA->id}/social/platforms")->assertOk();
        $byKey = collect($res->json('data'))->keyBy('key');
        $this->assertTrue($byKey['facebook']['is_connectable']);
        $this->assertTrue($byKey['instagram']['is_connectable']);
        $this->assertFalse($byKey['linkedin']['is_connectable']);
        $this->assertFalse($byKey['tiktok']['is_connectable']);
    }

    public function test_callback_with_bad_state_is_rejected(): void
    {
        $res = $this->get('/api/v1/social/meta/callback?code=x&state=not-a-real-state');
        $res->assertRedirect();
        $this->assertStringContainsString('meta=error', $res->headers->get('Location'));
        $this->assertSame(0, SocialAccount::withoutTenantScope()->count());
    }
}
