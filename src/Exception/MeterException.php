<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull\Exception;

/**
 * The local quota meter refused a request at the moment it was about to be
 * sent (another run or site took the last room first). Nothing was sent.
 */
class MeterException extends NewsApiPullException
{
    /**
     * @param string $reason `quota` or `pull_quota`.
     */
    public function __construct(private readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
