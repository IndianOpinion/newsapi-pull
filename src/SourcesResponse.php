<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull;

final class SourcesResponse
{
    /**
     * @param list<array{id: string, name: string, description: string, url: string, category: string, language: string, country: string}> $sources
     */
    public function __construct(
        public readonly array $sources,
        public readonly ?int $remaining = null
    ) {
    }
}
