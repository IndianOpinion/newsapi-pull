<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull\Exception;

/**
 * The API answered with an error status and a code.
 */
class ApiException extends NewsApiPullException
{
    public function __construct(
        private readonly string $apiCode,
        string $message,
        private readonly int $httpStatus = 0
    ) {
        parent::__construct($message);
    }

    public function apiCode(): string
    {
        return $this->apiCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
