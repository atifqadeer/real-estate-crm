<?php
namespace App\Services\Publishing;

use App\Models\ContentPost;
use App\Models\ContentPostTarget;

interface PublisherInterface
{
    public function platform(): string;

    /** Capability map shown in the UI and used by validators. */
    public function getCapabilities(): array;

    /** @return string[] human-readable validation errors (empty = valid) */
    public function validateContent(ContentPost $post, ContentPostTarget $target): array;

    /** @throws PublishingException */
    public function validateAccount(ContentPostTarget $target): void;

    /**
     * Publish one destination. Must be idempotency-aware: if provider
     * progress (e.g. an Instagram container id) already exists on the
     * target, resume instead of creating duplicates.
     *
     * @throws PublishingException
     */
    public function publish(ContentPost $post, ContentPostTarget $target): PublishResult;
}
