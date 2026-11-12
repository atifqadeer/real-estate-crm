<?php
namespace App\Services\Meta;

use Exception;

/** Carries only the provider's redacted error message/code — never tokens. */
class MetaApiException extends Exception
{
    public function __construct(string $message, public readonly ?int $graphCode = null, public readonly ?int $graphSubcode = null)
    {
        parent::__construct($message);
    }

    public static function fromResponse(array $body, string $fallback = 'Meta API request failed.'): self
    {
        $error = $body['error'] ?? [];
        return new self(
            (string) ($error['message'] ?? $fallback),
            isset($error['code']) ? (int) $error['code'] : null,
            isset($error['error_subcode']) ? (int) $error['error_subcode'] : null,
        );
    }
}
