<?php
namespace App\Services;

use App\Jobs\PublishPostTarget;
use App\Models\ContentPost;
use App\Models\ContentPostApproval;
use App\Models\ContentPostTarget;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Publishing\FacebookPublisher;
use App\Services\Publishing\InstagramPublisher;
use App\Services\Publishing\LinkedInPublisher;
use App\Services\Publishing\PublisherInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Composer + approval + scheduling orchestration.
 * Publishing itself happens per-destination in PublishPostTarget jobs.
 */
class ContentService
{
    public function __construct(private AuditLogger $audit) {}

    public function publisherFor(string $platform): PublisherInterface
    {
        return match ($platform) {
            'facebook' => app(FacebookPublisher::class),
            'instagram' => app(InstagramPublisher::class),
            'linkedin' => app(LinkedInPublisher::class),
            default => throw ValidationException::withMessages(['platform' => "No publisher shipped for {$platform}."]),
        };
    }

    /** Accounts of the org with an eligibility verdict for composer display. */
    public function eligibleAccounts(Organization $organization): array
    {
        return SocialAccount::where('organization_id', $organization->id)
            ->with(['platform', 'token', 'permissions'])
            ->orderBy('display_name')->get()
            ->map(function (SocialAccount $a) {
                $reasons = [];
                if ($a->status === 'disconnected') $reasons[] = 'disconnected';
                if (in_array($a->status, ['expired', 'error'], true)) $reasons[] = "connection {$a->status} — test or reconnect";
                if (!$a->token) $reasons[] = 'no stored token';
                $scope = match ($a->platform->key) {
                    'instagram' => 'instagram_content_publish',
                    'linkedin' => 'w_organization_social',
                    default => 'pages_manage_posts',
                };
                if ($a->permissions->where('permission', $scope)->where('status', 'declined')->isNotEmpty()) {
                    $reasons[] = "publishing permission {$scope} declined";
                }
                return [
                    'account' => $a,
                    'eligible' => $reasons === [],
                    'reasons' => $reasons,
                    'capabilities' => in_array($a->platform->key, ['facebook', 'instagram', 'linkedin'], true)
                        ? $this->publisherFor($a->platform->key)->getCapabilities() : [],
                ];
            })->all();
    }

    /** Whether this workspace requires approval (client setting) — post flag ORs with it at submit time. */
    public function approvalRequiredFor(Organization $organization, ContentPost $post): bool
    {
        if ($post->approval_required) return true;
        $setting = $organization->settings()->first();
        $value = $setting?->settings['content.approval_required'] ?? null;
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function createPost(Organization $organization, User $author, array $data): ContentPost
    {
        return DB::transaction(function () use ($organization, $author, $data) {
            $post = ContentPost::create([
                'organization_id' => $organization->id,
                'author_id' => $author->id,
                'title' => $data['title'] ?? null,
                'caption' => $data['caption'] ?? null,
                'hashtags' => $data['hashtags'] ?? null,
                'link_url' => $data['link_url'] ?? null,
                'cta_text' => $data['cta_text'] ?? null,
                'first_comment' => $data['first_comment'] ?? null,
                'internal_notes' => $data['internal_notes'] ?? null,
                'approval_required' => (bool) ($data['approval_required'] ?? false),
                'status' => 'draft',
            ]);
            $this->syncMediaAndTargets($post, $data);
            return $post->fresh(['targets', 'media']);
        });
    }

    public function updatePost(ContentPost $post, array $data): ContentPost
    {
        if (!in_array($post->status, ['draft', 'changes_requested', 'rejected'], true)) {
            throw ValidationException::withMessages(['status' => "Only draft / changes-requested posts can be edited (current: {$post->status})."]);
        }
        return DB::transaction(function () use ($post, $data) {
            $post->update(array_intersect_key($data, array_flip([
                'title', 'caption', 'hashtags', 'link_url', 'cta_text', 'first_comment', 'internal_notes', 'approval_required',
            ])));
            if (array_key_exists('media_ids', $data) || array_key_exists('targets', $data)) {
                $this->syncMediaAndTargets($post, $data);
            }
            if ($post->status === 'rejected') $post->update(['status' => 'draft']);
            return $post->fresh(['targets', 'media']);
        });
    }

    private function syncMediaAndTargets(ContentPost $post, array $data): void
    {
        if (array_key_exists('media_ids', $data)) {
            $sync = [];
            foreach (array_values($data['media_ids'] ?? []) as $i => $id) {
                $sync[(int) $id] = ['sort_order' => $i];
            }
            $post->media()->sync($sync);
        }
        if (array_key_exists('targets', $data)) {
            $keep = [];
            foreach ($data['targets'] ?? [] as $t) {
                $account = SocialAccount::where('organization_id', $post->organization_id)->findOrFail((int) $t['social_account_id']);
                $target = ContentPostTarget::updateOrCreate(
                    ['content_post_id' => $post->id, 'social_account_id' => $account->id],
                    [
                        'platform' => $account->platform->key,
                        'caption_override' => $t['caption_override'] ?? null,
                        'hashtags_override' => $t['hashtags_override'] ?? null,
                        'idempotency_key' => $post->id . '-' . $account->id . '-' . Str::lower(Str::random(8)),
                    ]
                );
                $keep[] = $target->id;
            }
            $post->targets()->whereNotIn('id', $keep)->where('status', 'pending')->delete();
        }
    }

    // ---- Approval workflow ----

    public function submit(ContentPost $post, User $actor): ContentPost
    {
        if (!in_array($post->status, ['draft', 'changes_requested'], true)) {
            throw ValidationException::withMessages(['status' => "Post cannot be submitted from status {$post->status}."]);
        }
        $post->update(['status' => 'pending_approval', 'approval_required' => $this->approvalRequiredFor($post->organization, $post)]);
        $this->recordApproval($post, $actor, 'submitted');
        $this->audit->log($post->organization_id, $actor->id, 'content.submitted', ['post_id' => $post->id]);
        return $post;
    }

    public function approve(ContentPost $post, User $actor, ?string $comment = null): ContentPost
    {
        $this->guardPending($post);
        $post->update(['status' => 'approved']);
        $this->recordApproval($post, $actor, 'approved', $comment);
        $this->audit->log($post->organization_id, $actor->id, 'content.approved', ['post_id' => $post->id]);
        $this->notifyAuthor($post, 'content.approved', 'Post approved: ' . ($post->title ?? 'Untitled post'), $comment);
        return $post;
    }

    public function reject(ContentPost $post, User $actor, string $comment): ContentPost
    {
        $this->guardPending($post);
        $post->update(['status' => 'rejected']);
        $this->recordApproval($post, $actor, 'rejected', $comment);
        $this->audit->log($post->organization_id, $actor->id, 'content.rejected', ['post_id' => $post->id]);
        $this->notifyAuthor($post, 'content.rejected', 'Post rejected: ' . ($post->title ?? 'Untitled post'), $comment);
        return $post;
    }

    public function requestChanges(ContentPost $post, User $actor, string $comment): ContentPost
    {
        $this->guardPending($post);
        $post->update(['status' => 'changes_requested']);
        $this->recordApproval($post, $actor, 'changes_requested', $comment);
        $this->audit->log($post->organization_id, $actor->id, 'content.changes_requested', ['post_id' => $post->id]);
        $this->notifyAuthor($post, 'content.changes_requested', 'Changes requested: ' . ($post->title ?? 'Untitled post'), $comment);
        return $post;
    }

    private function notifyAuthor(ContentPost $post, string $type, string $title, ?string $body): void
    {
        $author = $post->author;
        if ($author && $post->organization) {
            app(\App\Services\Notifications\NotificationService::class)
                ->notify($post->organization, $author, $type, $title, $body, ['post_id' => $post->id]);
        }
    }

    private function guardPending(ContentPost $post): void
    {
        if ($post->status !== 'pending_approval') {
            throw ValidationException::withMessages(['status' => 'Post is not awaiting approval.']);
        }
    }

    private function recordApproval(ContentPost $post, User $actor, string $action, ?string $comment = null): void
    {
        ContentPostApproval::create([
            'organization_id' => $post->organization_id,
            'content_post_id' => $post->id,
            'actor_id' => $actor->id,
            'action' => $action,
            'comment' => $comment,
        ]);
    }

    // ---- Publishing / scheduling ----

    /** The approval gate: unapproved content must never publish when approval is required. */
    public function guardPublishable(ContentPost $post): void
    {
        if ($this->approvalRequiredFor($post->organization, $post) && $post->status !== 'approved') {
            throw ValidationException::withMessages(['approval' => 'This post requires approval and has not been approved yet.']);
        }
        if ($post->targets()->count() === 0) {
            throw ValidationException::withMessages(['targets' => 'Select at least one destination account.']);
        }
        if (in_array($post->status, ['publishing', 'published', 'cancelled'], true)) {
            throw ValidationException::withMessages(['status' => "Post cannot be published from status {$post->status}."]);
        }
    }

    public function publishNow(ContentPost $post, ?User $actor = null): ContentPost
    {
        $this->guardPublishable($post);
        $post->update(['status' => 'publishing', 'scheduled_at' => null]);
        foreach ($post->targets()->whereIn('status', ['pending', 'scheduled', 'failed'])->get() as $target) {
            if ($target->platform_post_id) continue; // already live — idempotency
            $target->update(['status' => 'queued', 'dispatched_at' => now(), 'error_message' => null]);
            PublishPostTarget::dispatch($target->id);
        }
        if ($actor) $this->audit->log($post->organization_id, $actor->id, 'content.publish_requested', ['post_id' => $post->id]);
        return $post->fresh();
    }

    public function schedule(ContentPost $post, \DateTimeInterface $at, ?User $actor = null): ContentPost
    {
        $this->guardPublishable($post);
        if ($at <= now()) {
            throw ValidationException::withMessages(['scheduled_at' => 'Scheduled time must be in the future.']);
        }
        $post->update(['status' => 'scheduled', 'scheduled_at' => $at]);
        $post->targets()->whereIn('status', ['pending', 'failed'])->update(['status' => 'scheduled']);
        if ($actor) $this->audit->log($post->organization_id, $actor->id, 'content.scheduled', ['post_id' => $post->id, 'scheduled_at' => $at->format(DATE_ATOM)]);
        return $post->fresh();
    }

    public function cancelSchedule(ContentPost $post, ?User $actor = null): ContentPost
    {
        if ($post->status !== 'scheduled') {
            throw ValidationException::withMessages(['status' => 'Only a scheduled post can be cancelled.']);
        }
        $post->update(['status' => 'cancelled', 'scheduled_at' => null]);
        $post->targets()->where('status', 'scheduled')->update(['status' => 'cancelled']);
        if ($actor) $this->audit->log($post->organization_id, $actor->id, 'content.schedule_cancelled', ['post_id' => $post->id]);
        return $post->fresh();
    }

    public function retryTarget(ContentPostTarget $target, ?User $actor = null): ContentPostTarget
    {
        if ($target->status !== 'failed' || $target->platform_post_id) {
            throw ValidationException::withMessages(['target' => 'Only a failed, unpublished destination can be retried.']);
        }
        // Keep the IG container progress (resume, don't duplicate) but reset attempt budgeting.
        $target->update(['status' => 'queued', 'attempts' => 0, 'next_retry_at' => null, 'error_message' => null, 'dispatched_at' => now()]);
        $target->post->update(['status' => 'publishing']);
        PublishPostTarget::dispatch($target->id);
        if ($actor) $this->audit->log($target->post->organization_id, $actor->id, 'content.target_retried', ['post_id' => $target->content_post_id, 'target_id' => $target->id]);
        return $target;
    }

    public function duplicate(ContentPost $post, User $actor): ContentPost
    {
        return DB::transaction(function () use ($post, $actor) {
            $copy = $post->replicate(['status', 'scheduled_at', 'published_at']);
            $copy->author_id = $actor->id;
            $copy->status = 'draft';
            $copy->scheduled_at = null;
            $copy->published_at = null;
            $copy->title = $post->title ? $post->title . ' (copy)' : null;
            $copy->save();
            $sync = [];
            foreach ($post->media as $i => $m) $sync[$m->id] = ['sort_order' => $i];
            $copy->media()->sync($sync);
            foreach ($post->targets as $t) {
                $copy->targets()->create([
                    'social_account_id' => $t->social_account_id,
                    'platform' => $t->platform,
                    'caption_override' => $t->caption_override,
                    'hashtags_override' => $t->hashtags_override,
                    'status' => 'pending',
                    'idempotency_key' => $copy->id . '-' . $t->social_account_id . '-' . Str::lower(Str::random(8)),
                ]);
            }
            return $copy->fresh(['targets', 'media']);
        });
    }

    /** Recompute the post-level status from its destinations (called after every attempt). */
    public function recalculatePostStatus(ContentPost $post): void
    {
        $statuses = $post->targets()->pluck('status');
        if ($statuses->isEmpty()) return;
        $published = $statuses->filter(fn ($s) => $s === 'published')->count();
        $failed = $statuses->filter(fn ($s) => $s === 'failed')->count();
        $active = $statuses->filter(fn ($s) => in_array($s, ['queued', 'publishing', 'pending', 'scheduled'], true))->count();

        $new = match (true) {
            $active > 0 => 'publishing',
            $published === $statuses->count() => 'published',
            $published > 0 => 'partially_published',
            $failed === $statuses->count() => 'failed',
            default => $post->status,
        };
        $updates = ['status' => $new];
        if ($new === 'published' && !$post->published_at) $updates['published_at'] = now();
        $post->update($updates);
    }
}
