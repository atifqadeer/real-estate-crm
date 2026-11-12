<?php
namespace App\Services\Publishing;

use App\Models\ContentPost;
use App\Models\ContentPostTarget;
use App\Services\LinkedIn\LinkedInApiException;
use App\Services\LinkedIn\LinkedInClient;
use Illuminate\Support\Facades\Http;

/**
 * LinkedIn Company Page publishing via the REST Posts API (Phase 7).
 * Text posts and single-image posts. The author is always the
 * organization URN — this product connects Company Pages only.
 * Endpoints/payloads verified in docs/12.
 */
class LinkedInPublisher implements PublisherInterface
{
    public function __construct(private LinkedInClient $client) {}

    public function platform(): string { return 'linkedin'; }

    public function getCapabilities(): array
    {
        return ['text' => true, 'link' => true, 'image' => true, 'multi_image' => false,
            'video' => false, 'max_videos' => 0, 'requires_media' => false, 'first_comment' => false];
    }

    public function validateContent(ContentPost $post, ContentPostTarget $target): array
    {
        $errors = [];
        $media = $post->media;
        if ($media->where('kind', 'video')->isNotEmpty()) $errors[] = 'LinkedIn: video publishing is not supported in this integration yet.';
        if ($media->where('kind', 'image')->count() > 1) $errors[] = 'LinkedIn: this integration supports a single image per post.';
        if (mb_strlen($post->effectiveText($target)) > 3000) $errors[] = 'LinkedIn: post text exceeds 3,000 characters.';
        if ($post->effectiveText($target) === '' && $media->isEmpty()) $errors[] = 'LinkedIn: a post needs text or an image.';
        return $errors;
    }

    public function validateAccount(ContentPostTarget $target): void
    {
        $account = $target->account;
        if ($account->status === 'disconnected') {
            throw new PublishingException('Account is disconnected.', permanent: true, providerCode: 'disconnected');
        }
        if (in_array($account->status, ['expired', 'error'], true)) {
            throw new PublishingException("Account connection is {$account->status}. Test or reconnect it first.", permanent: true, providerCode: 'account_' . $account->status);
        }
        $this->token($target);
    }

    public function publish(ContentPost $post, ContentPostTarget $target): PublishResult
    {
        $this->validateAccount($target);
        $declined = $target->account->permissions()->where('permission', 'w_organization_social')->where('status', 'declined')->exists();
        if ($declined) {
            throw new PublishingException('Permission w_organization_social was declined for this account. Reconnect and grant it.', permanent: true, providerCode: 'permission_declined');
        }
        $errors = $this->validateContent($post, $target);
        if ($errors) throw new PublishingException(implode(' ', $errors), permanent: true, providerCode: 'validation');

        $token = $this->token($target);
        $authorUrn = 'urn:li:organization:' . $target->account->external_id;
        $text = $post->effectiveText($target);
        if ($post->link_url) $text = trim($text . "\n" . $post->link_url);

        $payload = [
            'author' => $authorUrn,
            'commentary' => $text,
            'visibility' => 'PUBLIC',
            'distribution' => ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => []],
            'lifecycleState' => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ];

        try {
            $image = $post->media->where('kind', 'image')->first();
            if ($image) {
                // Resume-safe: the image URN is stored on the target after upload.
                $imageUrn = $target->progress('li_image_urn');
                if (!$imageUrn) {
                    if (!$image->public_url) {
                        throw new PublishingException('LinkedIn: the image has no public URL to fetch its binary from.', permanent: true, providerCode: 'no_public_url');
                    }
                    $init = $this->client->initializeImageUpload($token, $authorUrn);
                    $binary = Http::get($image->public_url)->body();
                    $this->client->uploadImageBinary($token, $init['upload_url'], $binary);
                    $imageUrn = $init['image_urn'];
                    $target->setProgress('li_image_urn', $imageUrn);
                }
                $payload['content'] = ['media' => ['id' => $imageUrn, 'altText' => $post->title ?? '']];
            }
            $postUrn = $this->client->createPost($token, $payload);
        } catch (LinkedInApiException $e) {
            throw new PublishingException($e->getMessage(), permanent: $e->isPermanent(), providerCode: (string) $e->status);
        }
        $numeric = preg_replace('/\D+/', '', $postUrn);
        $url = $numeric ? "https://www.linkedin.com/feed/update/{$postUrn}" : null;
        return new PublishResult($postUrn, $url, ['urn' => $postUrn]);
    }

    private function token(ContentPostTarget $target): string
    {
        $token = $target->account->token?->access_token;
        if (!$token) throw new PublishingException('No stored token for this account. Reconnect it.', permanent: true, providerCode: 'no_token');
        return $token;
    }
}
