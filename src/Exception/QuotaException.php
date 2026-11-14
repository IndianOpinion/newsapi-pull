<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull\Exception;

/**
 * The API said the daily quota is gone (`apiKeyExhausted`) or the caller is
 * rate limited (`rateLimited`, or HTTP 429 without a code). The API may send
 * HTTP 429 for both, so the code decides which one it is.
 */
class QuotaException extends ApiException
{
    public function isRateLimit(): bool
    {
        return $this->apiCode() === 'rateLimited';
    }
}
