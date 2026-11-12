<?php
namespace App\Jobs;

use App\Models\ContentPost;
use App\Models\ContentPostTarget;
use App\Services\ContentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Runs every minute from the scheduler:
 *  - dispatches due scheduled posts (per-post lock prevents double dispatch)
 *  - re-dispatches targets whose retry time has arrived (safety net)
 */
class ProcessScheduledPosts implements ShouldQueue
{
    use Queueable;

    public function handle(ContentService $content): void
    {
        $due = ContentPost::withoutTenantScope()
            ->where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->get();

        foreach ($due as $post) {
            $lock = Cache::lock("publish-post-{$post->id}", 120);
            if (!$lock->acquire()) continue;
            try {
                if ($post->fresh()->status !== 'scheduled') continue;
                // Mark publishing BEFORE dispatch: with a sync queue the target
                // job completes inline and recalculates the final status itself.
                $post->update(['status' => 'publishing', 'scheduled_at' => null]);
                foreach ($post->targets()->where('status', 'scheduled')->get() as $target) {
                    $target->update(['status' => 'queued', 'dispatched_at' => now()]);
                    PublishPostTarget::dispatch($target->id);
                }
            } finally {
                $lock->release();
            }
        }

        $retries = ContentPostTarget::where('status', 'queued')
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', now())
            ->whereNull('platform_post_id')
            ->limit(100)->get();
        foreach ($retries as $target) {
            $target->update(['next_retry_at' => null]);
            PublishPostTarget::dispatch($target->id);
        }
    }
}
