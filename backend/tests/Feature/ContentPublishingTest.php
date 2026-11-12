<?php
namespace Tests\Feature;

use App\Jobs\ProcessScheduledPosts;
use App\Jobs\PublishPostTarget;
use App\Models\ContentPost;
use App\Models\MediaAsset;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\SocialPlatform;
use App\Models\User;
use App\Services\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 3 acceptance tests. Meta's Graph API is faked (Http::fake) — these
 * tests prove the composer, approval gate, per-destination engine,
 * scheduling, retry and isolation. Not a live Meta claim (docs/08).
 */
class ContentPublishingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;
    private Organization $orgB;
    private User $ownerA;
    private User $ownerB;
    private User $approverA;
    private SocialAccount $fbA;
    private SocialAccount $igA;
    private int $feedCalls = 0;
    private int $feedFailuresRemaining = 0;
    private string $igContainerStatus = 'FINISHED';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\SocialPlatformSeeder::class);
        config(['services.meta.app_id' => 'test-app-id', 'services.meta.app_secret' => 'test-secret']);

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
        $this->approverA = User::factory()->create();
        $this->join($this->orgA, $this->approverA, 'client_marketing_approver');

        $this->fbA = $this->makeAccount($this->orgA, 'facebook', 'page-1');
        $this->igA = $this->makeAccount($this->orgA, 'instagram', 'ig-1');

        $this->fakeMeta();
    }

    private function join(Organization $org, User $user, string $role): void
    {
        $org->users()->attach($user->id, ['status' => 'active', 'joined_at' => now()]);
        app(RoleProvisioner::class)->assign($org, $user, $role);
    }

    private function makeAccount(Organization $org, string $platformKey, string $externalId): SocialAccount
    {
        $platform = SocialPlatform::where('key', $platformKey)->firstOrFail();
        $account = SocialAccount::withoutTenantScope()->create([
            'organization_id' => $org->id,
            'social_platform_id' => $platform->id,
            'external_id' => $externalId,
            'display_name' => $platformKey === 'facebook' ? 'Client A Properties' : 'clienta.properties',
            'username' => $platformKey === 'instagram' ? 'clienta.properties' : 'clientaproperties',
            'status' => 'connected',
            'last_synced_at' => now(),
        ]);
        $account->token()->create([
            'token_type' => 'page_access_token', 'access_token' => 'PAGE_TOKEN', 'scopes' => [],
        ]);
        foreach (['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'instagram_basic', 'instagram_content_publish'] as $perm) {
            $account->permissions()->create(['permission' => $perm, 'status' => 'granted']);
        }
        return $account;
    }

    private function fakeMeta(): void
    {
        Http::fake(function (HttpRequest $request) {
            $url = $request->url();
            $method = $request->method();

            if ($method === 'POST' && str_contains($url, '/page-1/feed')) {
                $this->feedCalls++;
                if ($this->feedFailuresRemaining > 0) {
                    $this->feedFailuresRemaining--;
                    return Http::response(['error' => ['message' => 'Temporary server error', 'code' => 1]], 500);
                }
                return Http::response(['id' => 'page-1_555'], 200);
            }
            if ($method === 'POST' && str_contains($url, '/page-1/photos')) {
                if (($request->data()['published'] ?? 'true') === 'false') {
                    return Http::response(['id' => 'photo-' . rand(100, 999)], 200);
                }
                return Http::response(['id' => 'photo-1', 'post_id' => 'page-1_556'], 200);
            }
            if ($method === 'POST' && str_contains($url, '/comments')) {
                return Http::response(['id' => 'comment-1'], 200);
            }
            if ($method === 'POST' && str_contains($url, '/ig-1/media_publish')) {
                return Http::response(['id' => 'ig-media-1'], 200);
            }
            if ($method === 'POST' && str_contains($url, '/ig-1/media')) {
                return Http::response(['id' => 'container-1'], 200);
            }
            if ($method === 'GET' && str_contains($url, '/container-1')) {
                return Http::response(['status_code' => $this->igContainerStatus], 200);
            }
            if ($method === 'GET' && str_contains($url, '/ig-media-1')) {
                return Http::response(['permalink' => 'https://www.instagram.com/p/xyz/'], 200);
            }
            return Http::response(['error' => ['message' => 'Unknown fake endpoint: ' . $url, 'code' => 100]], 400);
        });
    }

    private function imageAsset(Organization $org): MediaAsset
    {
        return MediaAsset::create([
            'organization_id' => $org->id, 'disk' => 'public', 'path' => 'org-x/img.jpg',
            'public_url' => 'https://cdn.example.test/img.jpg', 'original_name' => 'img.jpg',
            'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'size_bytes' => 12345, 'kind' => 'image',
            'width' => 1080, 'height' => 1080,
        ]);
    }

    private function createPost(User $as, array $overrides = []): ContentPost
    {
        Sanctum::actingAs($as);
        $res = $this->postJson("/api/v1/organizations/{$this->orgA->id}/content/posts", array_merge([
            'title' => 'DHA Phase 6 — 1 Kanal House',
            'caption' => 'Beautiful 1 Kanal house for sale in DHA Phase 6.',
            'hashtags' => '#DHA #HouseForSale',
            'targets' => [['social_account_id' => $this->fbA->id]],
        ], $overrides));
        $res->assertCreated();
        return ContentPost::findOrFail($res->json('data.id'));
    }

    public function test_facebook_text_post_publishes_and_rerun_is_idempotent(): void
    {
        $post = $this->createPost($this->ownerA, ['first_comment' => 'DM for details']);
        $this->postJson("/api/v1/organizations/{$this->orgA->id}/content/posts/{$post->id}/publish")->assertOk();

        $target = $post->targets()->first();
        $this->assertSame('published', $target->status);
        $this->assertSame('page-1_555', $target->platform_post_id);
        $this->assertSame('published', $post->fresh()->status);
        $this->assertDatabaseHas('publishing_attempts', ['content_post_target_id' => $target->id, 'status' => 'succeeded']);
        $this->assertSame(1, $this->feedCalls);

        // Running the job again must NOT create a second Facebook post.
        (new PublishPostTarget($target->id))->handle(app(\App\Services\ContentService::class));
        $this->assertSame(1, $this->feedCalls);
    }

    public function test_instagram_image_post_uses_container_flow(): void
    {
        $asset = $this->imageAsset($this->orgA);
        $post = $this->createPost($this->ownerA, [
            'media_ids' => [$asset->id],
            'targets' => [['social_account_id' => $this->igA->id]],
        ]);
        $this->postJson("/api/v1/organizations/{$this->orgA->id}/content/posts/{$post->id}/publish")->assertOk();

        $target = $post->targets()->first();
        $this->assertSame('published', $target->status);
        $this->assertSame('ig-media-1', $target->platform_post_id);
        $this->assertSame('https://www.instagram.com/p/xyz/', $target->platform_url);
        $this->assertSame('container-1', $target->progress('ig_container_id'));
    }

    public function test_one_destination_failure_does_not_block_the_other(): void
    {
        $this->igContainerStatus = 'ERROR';
        $asset = $this->imageAsset($this->orgA);
        $post = $this->createPost($this->ownerA, [
            'media_ids' => [$asset->id],
            'targets' => [['social_account_id' => $this->fbA->id], ['social_account_id' => $this->igA->id]],
        ]);
        $this->postJson("/api/v1/organizations/{$this->orgA->id}/content/posts/{$post->id}/publish")->assertOk();

        $byPlatform = $post->targets()->get()->keyBy('platform');
        $this->assertSame('published', $byPlatform['facebook']->status);
        $this->assertSame('failed', $byPlatform['instagram']->status);
        $this->assertSame('partially_published', $post->fresh()->status);
    }

    public function test_approval_gate_blocks_publish_until_approved(): void
    {
        $post = $this->createPost($this->ownerA, ['approval_required' => true]);

        // Not approved yet -> publish blocked
        $this->postJson("/api/v1/organizations/{$this->orgA->id}/content/posts/{$post->id}/publish")->assertStatus(422);

        $this->postJson("/api/v1/organizations/{$this->orgA->id}/content/posts/{$post->id}/submit")->assertOk()
            ->assertJsonPath('data.status', 'pending_approval');

        // Rejection requires a comment
        Sanctum::actingAs($this->approverA);
        $this->postJson("/api/v1/organizations/{$this->orgA->id}/content/posts/{$post->id}/reject", [])->assertStatus(422);
        $this->postJson("/api/v1/organizations/{$this->orgA->id}/content/posts/{$post->id}/request-changes", ['comment' => 'Fix the price line.'])
            ->assertOk()->assertJsonPath('data.status', 'changes_requested');

        // Author edits and resubmits; approver approves; publish now allowed
        Sanctum::actingAs($this->ownerA);
        $this->putJson("/api/v1/organizations/{$this->orgA->id}/content/posts/{$post->id}", ['caption' => 'Updated caption with correct price.'])->assertOk();
        $this->postJson("/api/v1/organizations/{$this->orgA->id}/content/posts/{$post->id}/submit")->assertOk();
        Sanctum::actingAs($this->approverA);
        $this->postJson("/api/v1/organizations/{$this->orgA->id}/content/posts/{$post->id}/approve", [])->assertOk()
            ->assertJsonPath('data.status', 'approved');
        Sanctum::actingAs($this->ownerA);
        $this->postJson("/api/v1/organizations/{$this->orgA->id}/content/posts/{$post->id}/publish")->assertOk();
        $this->assertSame('published', $post->fresh()->status);
        $this->assertDatabaseHas('content_post_approvals', ['content_post_id' => $post->id, 'action' => 'changes_requested']);
        $this->assertDatabaseHas('content_post_approvals', ['content_post_id' => $post->id, 'action' => 'approved']);
    }

    public function test_scheduling_dispatches_only_when_due_and_can_be_cancelled(): void
    {
        $post = $this->createPost($this->ownerA);
        $when = now()->addHours(2)->toIso8601String();
        $this->postJson("/api/v1/organizations/{$this->orgA->id}/content/posts/{$post->id}/schedule", ['scheduled_at' => $when])
            ->assertOk()->assertJsonPath('data.status', 'scheduled');

        (new ProcessScheduledPosts)->handle(app(\App\Services\ContentService::class));
        $this->assertSame(0, $this->feedCalls);

        $this->travel(3)->hours();
        (new ProcessScheduledPosts)->handle(app(\App\Services\ContentService::class));
        $this->assertSame(1, $this->feedCalls);
        $this->assertSame('published', $post->fresh()->status);

        // Cancel path
        $post2 = $this->createPost($this->ownerA);
        $this->postJson("/api/v1/organizations/{$this->orgA->id}/content/posts/{$post2->id}/schedule", ['scheduled_at' => now()->addHour()->toIso8601String()])->assertOk();
        $this->postJson("/api/v1/organizations/{$this->orgA->id}/content/posts/{$post2->id}/cancel-schedule")->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
        $this->travel(2)->hours();
        (new ProcessScheduledPosts)->handle(app(\App\Services\ContentService::class));
        $this->assertSame('cancelled', $post2->fresh()->status);
        $this->assertSame(1, $this->feedCalls); // unchanged — cancelled post never published
    }

    public function test_transient_failure_retries_and_then_succeeds(): void
    {
        $this->feedFailuresRemaining = 1;
        $post = $this->createPost($this->ownerA);
        $this->postJson("/api/v1/organizations/{$this->orgA->id}/content/posts/{$post->id}/publish")->assertOk();

        $target = $post->targets()->first();
        $this->assertSame('published', $target->status);
        $this->assertSame(2, $target->attempts);
        $this->assertSame(2, $this->feedCalls);
        $this->assertDatabaseHas('publishing_attempts', ['content_post_target_id' => $target->id, 'attempt_no' => 1, 'status' => 'failed']);
        $this->assertDatabaseHas('publishing_attempts', ['content_post_target_id' => $target->id, 'attempt_no' => 2, 'status' => 'succeeded']);
    }

    public function test_instagram_text_only_post_fails_permanently_with_clear_error(): void
    {
        $post = $this->createPost($this->ownerA, ['targets' => [['social_account_id' => $this->igA->id]]]);
        $this->postJson("/api/v1/organizations/{$this->orgA->id}/content/posts/{$post->id}/publish")->assertOk();
        $target = $post->targets()->first();
        $this->assertSame('failed', $target->status);
        $this->assertStringContainsString('Instagram', (string) $target->error_message);
        $this->assertSame('failed', $post->fresh()->status);
    }

    public function test_media_upload_validation_and_tenant_isolation(): void
    {
        Sanctum::actingAs($this->ownerA);
        $bad = UploadedFile::fake()->create('notes.txt', 10, 'text/plain');
        $this->postJson("/api/v1/organizations/{$this->orgA->id}/media", ['file' => $bad])->assertStatus(422);

        $img = UploadedFile::fake()->image('house.jpg', 800, 600);
        $res = $this->postJson("/api/v1/organizations/{$this->orgA->id}/media", ['file' => $img])->assertCreated();
        $assetId = $res->json('data.id');
        $this->assertSame(800, $res->json('data.width'));

        Sanctum::actingAs($this->ownerB);
        $this->getJson("/api/v1/organizations/{$this->orgB->id}/media")->assertOk()->assertJsonPath('total', 0);
        $this->deleteJson("/api/v1/organizations/{$this->orgB->id}/media/{$assetId}")->assertNotFound();

        // Posts are isolated too
        $post = $this->createPost($this->ownerA);
        Sanctum::actingAs($this->ownerB);
        $this->getJson("/api/v1/organizations/{$this->orgB->id}/content/posts/{$post->id}")->assertNotFound();
        $this->postJson("/api/v1/organizations/{$this->orgB->id}/content/posts/{$post->id}/publish")->assertNotFound();
    }

    public function test_templates_seed_starters_and_custom_template(): void
    {
        Sanctum::actingAs($this->ownerA);
        $this->postJson("/api/v1/organizations/{$this->orgA->id}/templates/seed-starters")->assertOk()
            ->assertJsonPath('data.created', 16);
        $this->getJson("/api/v1/organizations/{$this->orgA->id}/templates")->assertOk()->assertJsonCount(16, 'data');
        $this->postJson("/api/v1/organizations/{$this->orgA->id}/templates", [
            'name' => 'My Template', 'category' => 'Custom', 'caption' => 'Hello {location}', 'hashtags' => '#x',
        ])->assertCreated();
    }

    public function test_previews_show_effective_text_and_validation(): void
    {
        $post = $this->createPost($this->ownerA, [
            'targets' => [
                ['social_account_id' => $this->fbA->id, 'caption_override' => 'FB-only caption'],
                ['social_account_id' => $this->igA->id],
            ],
        ]);
        Sanctum::actingAs($this->ownerA);
        $res = $this->getJson("/api/v1/organizations/{$this->orgA->id}/content/posts/{$post->id}/previews")->assertOk();
        $byPlatform = collect($res->json('data'))->keyBy('platform');
        $this->assertStringStartsWith('FB-only caption', $byPlatform['facebook']['text']);
        $this->assertNotEmpty($byPlatform['instagram']['errors']); // no media -> IG error listed
        $this->assertSame([], $byPlatform['facebook']['errors']);
    }
}
