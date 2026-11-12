<?php
namespace App\Services\LinkedIn;

use RuntimeException;

class LinkedInApiException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message);
    }

    /** 401/403 (auth/role) and 400 validation errors will not heal by retrying. */
    public function isPermanent(): bool
    {
        return in_array($this->status, [400, 401, 403, 404, 422], true);
    }
}
