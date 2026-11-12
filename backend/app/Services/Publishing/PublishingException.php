<?php
namespace App\Services\Publishing;

use Exception;

/** permanent=true means retrying cannot help (validation, permission, policy, auth). */
class PublishingException extends Exception
{
    public function __construct(string $message, public readonly bool $permanent = false, public readonly ?string $providerCode = null)
    {
        parent::__construct($message);
    }
}
