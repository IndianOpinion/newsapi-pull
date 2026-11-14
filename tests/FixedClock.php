<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull\Tests;

use IndianOpinion\NewsApiPull\Clock;

final class FixedClock implements Clock
{
    public function __construct(public int $now)
    {
    }

    public function now(): int
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }
}
