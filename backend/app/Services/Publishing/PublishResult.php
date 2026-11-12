<?php
namespace App\Services\Publishing;

class PublishResult
{
    public function __construct(
        public readonly string $platformPostId,
        public readonly ?string $platformUrl,
        public readonly array $rawResponse = [],
    ) {}
}
