<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull\Exception;

/**
 * The response was not the documented JSON shape.
 */
class ResponseException extends NewsApiPullException
{
    public function __construct(string $message, private readonly string $snippet = '')
    {
        parent::__construct($message);
    }

    /** At most the first 200 bytes of the body, for the debug log only. */
    public function snippet(): string
    {
        return $this->snippet;
    }
}
