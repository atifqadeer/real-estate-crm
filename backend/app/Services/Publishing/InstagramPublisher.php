<?php
namespace App\Services\Publishing;

use App\Models\ContentPost;
use App\Models\ContentPostTarget;
use App\Services\Meta\MetaApiException;

/**
 * Instagram publishing (Facebook Login path) uses the container workflow:
 *  1. POST /{ig-id}/media            -> container id
 *  2. GET  /{container}?fields=status_code  (poll until FINISHED)
 *  3. POST /{ig-id}/media_publish    -> media id
 * Media must be reachable at a public URL. The container id is stored on
 * the target so a retry resumes instead of creating a duplicate post.
 */
class InstagramPublisher extends AbstractMetaPublisher
{
    public function platform(): string { return 'instagram'; }

    public function getCapabilities(): array
    {
        return ['text' => false, 'link' => false, 'image' => true, 'multi_image' => true,
            'video' => true, 'max_videos' => 1, 'requires_media' => true, 'first_comment' => false];
    }

    public function validateContent(ContentPost $post, ContentPostTarget $target): array
    {
        $errors = [];
        $media = $post->media;
        if ($media->isEmpty()) $errors[] = 'Instagram: a post needs at least one image or video (text-only is not supported).';
        if ($media->count() > 20) $errors[] = 'Instagram: maximum 20 media items per carousel.';
        if (mb_strlen($post->effectiveText($target)) > 2200) $errors[] = 'Instagram: caption exceeds 2,200 characters.';
        if ($media->where('kind', 'video')->count() > 1) $errors[] = 'Instagram: only one video per post in this workflow.';
        if ($media->where('kind', 'video')->count() === 1 && $media->count() > 1) $errors[] = 'Instagram: a video (Reel) cannot be mixed into a carousel here.';
        foreach ($media as $m) {
            if (!$m->public_url) $errors[] = "Instagram: media '{$m->original_name}' has no public URL Meta can fetch.";
        }
        return $errors;
    }

    public function publish(ContentPost $post, ContentPostTarget $target): PublishResult
    {
        $this->validateAccount($target);
        $this->requireScope($target, 'instagram_content_publish');
        $errors = $this->validateContent($post, $target);
        if ($errors) throw new PublishingException(implode(' ', $errors), permanent: true, providerCode: 'validation');

        $igId = $target->account->external_id;
        $token = $this->token($target);
        $caption = $post->effectiveText($target);
        $media = $post->media->values();

        try {
            $containerId = $target->progress('ig_container_id');

            if (!$containerId) {
                if ($media->count() === 1) {
                    $m = $media[0];
                    $params = $m->kind === 'video'
                        ? ['media_type' => 'REELS', 'video_url' => $m->public_url, 'caption' => $caption, 'access_token' => $token]
                        : ['image_url' => $m->public_url, 'caption' => $caption, 'access_token' => $token];
                    $res = $this->graph->post("/{$igId}/media", $params);
                    $containerId = (string) $res['id'];
                } else {
                    $children = [];
                    foreach ($media as $m) {
                        $child = $this->graph->post("/{$igId}/media", [
                            'image_url' => $m->public_url, 'is_carousel_item' => 'true', 'access_token' => $token,
                        ]);
                        $children[] = (string) $child['id'];
                    }
                    $res = $this->graph->post("/{$igId}/media", [
                        'media_type' => 'CAROUSEL', 'children' => implode(',', $children),
                        'caption' => $caption, 'access_token' => $token,
                    ]);
                    $containerId = (string) $res['id'];
                }
                $target->setProgress('ig_container_id', $containerId);
            }

            // Poll container processing (bounded; job retry resumes here).
            $status = null;
            for ($i = 0; $i < 10; $i++) {
                $check = $this->graph->getWithToken("/{$containerId}", ['fields' => 'status_code,status'], $token);
                $status = $check['status_code'] ?? null;
                if ($status === 'FINISHED') break;
                if ($status === 'PUBLISHED' && $target->progress('ig_media_id')) {
                    return new PublishResult((string) $target->progress('ig_media_id'), $target->progress('ig_permalink'), ['resumed' => true]);
                }
                if (in_array($status, ['ERROR', 'EXPIRED'], true)) {
                    throw new PublishingException(
                        'Instagram media processing failed: ' . ($check['status'] ?? $status),
                        permanent: $status === 'ERROR',
                        providerCode: 'container_' . strtolower((string) $status),
                    );
                }
                if ($i < 9 && app()->environment('production')) sleep(3);
            }
            if ($status !== 'FINISHED') {
                throw new PublishingException('Instagram media is still processing; will retry.', permanent: false, providerCode: 'container_in_progress');
            }

            // Idempotency: if a previous attempt already published this container, reuse the stored id.
            if ($existing = $target->progress('ig_media_id')) {
                return new PublishResult((string) $existing, $target->progress('ig_permalink'), ['id' => $existing, 'resumed' => true]);
            }

            $published = $this->graph->post("/{$igId}/media_publish", ['creation_id' => $containerId, 'access_token' => $token]);
            $mediaId = (string) $published['id'];
            $target->setProgress('ig_media_id', $mediaId);

            $permalink = null;
            try {
                $node = $this->graph->getWithToken("/{$mediaId}", ['fields' => 'permalink'], $token);
                $permalink = $node['permalink'] ?? null;
                if ($permalink) $target->setProgress('ig_permalink', $permalink);
            } catch (MetaApiException) {
                // Permalink is cosmetic; the post is live.
            }

            return new PublishResult($mediaId, $permalink, $published);
        } catch (MetaApiException $e) {
            throw $this->wrap($e);
        }
    }
}
