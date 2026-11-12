<?php
namespace App\Services\Publishing;

use App\Models\ContentPost;
use App\Models\ContentPostTarget;
use App\Services\Meta\MetaApiException;

/**
 * Facebook Page publishing via the Pages API:
 *  - text/link  -> POST /{page-id}/feed
 *  - 1 image    -> POST /{page-id}/photos (url + message)
 *  - n images   -> upload unpublished photos, then /feed with attached_media
 *  - 1 video    -> Resumable Upload session, then POST graph-video /{page-id}/videos
 */
class FacebookPublisher extends AbstractMetaPublisher
{
    public function platform(): string { return 'facebook'; }

    public function getCapabilities(): array
    {
        return ['text' => true, 'link' => true, 'image' => true, 'multi_image' => true,
            'video' => true, 'max_videos' => 1, 'requires_media' => false, 'first_comment' => true];
    }

    public function validateContent(ContentPost $post, ContentPostTarget $target): array
    {
        $errors = [];
        $media = $post->media;
        $videos = $media->where('kind', 'video');
        $images = $media->where('kind', 'image');
        if ($videos->count() > 1) $errors[] = 'Facebook: only one video per post.';
        if ($videos->count() === 1 && $images->count() > 0) $errors[] = 'Facebook: cannot mix a video with images in one post.';
        if ($media->isEmpty() && trim($post->effectiveText($target)) === '' && !$post->link_url) {
            $errors[] = 'Facebook: a post needs text, a link, or media.';
        }
        foreach ($media as $m) {
            if (!$m->public_url) $errors[] = "Facebook: media '{$m->original_name}' has no public URL Meta can fetch.";
        }
        return $errors;
    }

    public function publish(ContentPost $post, ContentPostTarget $target): PublishResult
    {
        $this->validateAccount($target);
        $this->requireScope($target, 'pages_manage_posts');
        $errors = $this->validateContent($post, $target);
        if ($errors) throw new PublishingException(implode(' ', $errors), permanent: true, providerCode: 'validation');

        $pageId = $target->account->external_id;
        $token = $this->token($target);
        $text = $post->effectiveText($target);
        $media = $post->media;
        $images = $media->where('kind', 'image')->values();
        $videos = $media->where('kind', 'video')->values();

        try {
            if ($videos->count() === 1) {
                $video = $videos[0];
                $binary = \Illuminate\Support\Facades\Storage::disk($video->disk)->get($video->path);
                if ($binary === null) {
                    throw new PublishingException("Video file '{$video->original_name}' could not be read from storage.", permanent: true, providerCode: 'storage');
                }
                $session = $this->graph->startUploadSession($video->original_name, strlen($binary), $video->mime_type, $token);
                $sessionId = str_replace('upload:', '', (string) ($session['id'] ?? ''));
                $handle = $this->graph->uploadVideoChunk($sessionId, $binary, $token);
                $res = $this->graph->postToHost('https://graph-video.facebook.com', "/{$pageId}/videos", [
                    'fbuploader_video_file_chunk' => $handle,
                    'description' => $text,
                    'title' => $post->title ?? '',
                    'access_token' => $token,
                ]);
                $postId = (string) $res['id'];
            } elseif ($images->count() === 1) {
                $res = $this->graph->post("/{$pageId}/photos", [
                    'url' => $images[0]->public_url,
                    'message' => $text,
                    'published' => 'true',
                    'access_token' => $token,
                ]);
                $postId = (string) ($res['post_id'] ?? $res['id']);
            } elseif ($images->count() > 1) {
                $attached = [];
                foreach ($images as $i => $img) {
                    $up = $this->graph->post("/{$pageId}/photos", [
                        'url' => $img->public_url, 'published' => 'false', 'access_token' => $token,
                    ]);
                    $attached["attached_media[{$i}]"] = json_encode(['media_fbid' => $up['id']]);
                }
                $res = $this->graph->post("/{$pageId}/feed", array_merge([
                    'message' => $text, 'access_token' => $token,
                ], $attached));
                $postId = (string) $res['id'];
            } else {
                $params = ['message' => $text, 'access_token' => $token];
                if ($post->link_url) $params['link'] = $post->link_url;
                $res = $this->graph->post("/{$pageId}/feed", $params);
                $postId = (string) $res['id'];
            }
        } catch (MetaApiException $e) {
            throw $this->wrap($e);
        }

        // Optional first comment — failure here must not fail the post.
        if ($post->first_comment) {
            try {
                $this->graph->post("/{$postId}/comments", ['message' => $post->first_comment, 'access_token' => $token]);
            } catch (MetaApiException) {
                // Logged at attempt level by the caller; post itself is live.
            }
        }

        return new PublishResult($postId, "https://www.facebook.com/{$postId}", $res);
    }
}
