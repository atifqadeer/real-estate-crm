<?php
namespace App\Jobs;

use App\Models\ContentPostTarget;
use App\Models\PublishingAttempt;
use App\Services\ContentService;
use App\Services\Publishing\PublishingException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One destination, one job. Attempts are budgeted here (max 3) with
 * bounded backoff for transient failures only; permanent errors fail
 * immediately. A target that already has a platform_post_id is never
 * re-published (idempotency).
 */
class PublishPostTarget implements ShouldQueue
{
    use Queueable;

    public const MAX_ATTEMPTS = 3;
    private const BACKOFF_SECONDS = [60, 300, 900];

    public function __construct(public int $targetId) {}

    public function handle(ContentService $content): void
    {
        $target = ContentPostTarget::with(['post.media', 'account.token', 'account.platform', 'account.permissions'])->find($this->targetId);
        if (!$target) return;
        if ($target->platform_post_id || $target->status === 'published') return; // idempotent no-op
        if (in_array($target->status, ['cancelled'], true)) return;

        $post = $target->post;
        // A scheduled post cancelled after dispatch must not publish.
        if ($post->status === 'cancelled') return;

        $target->update(['status' => 'publishing']);
        $attemptNo = $target->attempts + 1;
        $attempt = PublishingAttempt::create([
            'organization_id' => $post->organization_id,
            'content_post_target_id' => $target->id,
            'attempt_no' => $attemptNo,
            'status' => 'started',
            'provider_request' => ['platform' => $target->platform, 'account' => $target->account->external_id],
            'started_at' => now(),
        ]);
        $target->update(['attempts' => $attemptNo]);

        try {
            $publisher = $content->publisherFor($target->platform);
            $result = $publisher->publish($post, $target);
            $target->update([
                'status' => 'published',
                'platform_post_id' => $result->platformPostId,
                'platform_url' => $result->platformUrl,
                'published_at' => now(),
                'error_code' => null,
                'error_message' => null,
                'next_retry_at' => null,
                'provider_response' => array_merge($target->provider_response ?? [], ['last_publish' => $result->rawResponse]),
            ]);
            $attempt->update(['status' => 'succeeded', 'provider_response' => $result->rawResponse, 'finished_at' => now()]);
        } catch (PublishingException $e) {
            $attempt->update(['status' => 'failed', 'error_message' => $e->getMessage(), 'permanent' => $e->permanent, 'finished_at' => now()]);
            if (!$e->permanent && $attemptNo < self::MAX_ATTEMPTS) {
                $delay = self::BACKOFF_SECONDS[$attemptNo - 1] ?? 900;
                $target->update([
                    'status' => 'queued',
                    'error_code' => $e->providerCode,
                    'error_message' => $e->getMessage(),
                    'next_retry_at' => now()->addSeconds($delay),
                ]);
                // Production (Redis/Horizon) honors the delay; the scheduler
                // sweep also re-dispatches due retries as a safety net.
                self::dispatch($target->id)->delay(now()->addSeconds($delay));
            } else {
                $target->update([
                    'status' => 'failed',
                    'error_code' => $e->providerCode,
                    'error_message' => $e->getMessage(),
                    'next_retry_at' => null,
                ]);
            }
        }

        $content->recalculatePostStatus($post->fresh());
    }
}
