<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull;

final class ArticlesResponse
{
    /**
     * @param list<mixed> $articles Raw entries, normalised later one by one.
     */
    public function __construct(
        public readonly array $articles,
        public readonly int $totalResults,
        public readonly ?int $remaining = null
    ) {
    }
}
