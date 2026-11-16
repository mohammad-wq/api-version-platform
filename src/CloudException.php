<?php

declare(strict_types=1);

namespace Mohammad\ApiVersionPlatform;

/** A failure talking to the cloud platform; `status` is the HTTP status the local API answers with. */
final class CloudException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 502)
    {
        parent::__construct($message);
    }
}
