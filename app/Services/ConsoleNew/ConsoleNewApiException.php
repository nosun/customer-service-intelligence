<?php

namespace App\Services\ConsoleNew;

use RuntimeException;

class ConsoleNewApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($message);
    }
}
