<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull;

/**
 * Source of the current time, so runs can be tested at a fixed instant.
 */
interface Clock
{
    /** Unix time in seconds. */
    public function now(): int;
}
