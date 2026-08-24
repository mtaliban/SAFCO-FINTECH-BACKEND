<?php

namespace App\Services\AI;

use RuntimeException;

class AiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $provider = 'unknown',
        public readonly ?int $httpStatus = null,
        public readonly ?array $responseBody = null,
    ) {
        parent::__construct($message);
    }
}
