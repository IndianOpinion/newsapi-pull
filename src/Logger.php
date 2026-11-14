<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull;

/**
 * Writes redacted lines to the PHP error log. Debug lines only when the debug
 * setting is on; warnings and errors always.
 */
final class Logger
{
    /** @var \Closure(string): void */
    private readonly \Closure $sink;

    /** @var list<string> */
    private array $lines = [];

    /**
     * @param (callable(string): void)|null $sink Receives each finished line.
     */
    public function __construct(private readonly Settings $settings, ?callable $sink = null)
    {
        $this->sink = \Closure::fromCallable($sink ?? static function (string $line): void {
            error_log($line);
        });
    }

    public function debug(string $message): void
    {
        if ($this->settings->debugLog()) {
            $this->write('debug', $message);
        }
    }

    public function warning(string $message): void
    {
        $this->write('warning', $message);
    }

    public function error(string $message): void
    {
        $this->write('error', $message);
    }

    public function redact(string $message): string
    {
        return Redactor::redact($message, $this->settings->apiKey());
    }

    /**
     * Lines written during this request, newest last.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    private function write(string $level, string $message): void
    {
        $line = '[newsapi-pull] ' . $level . ': ' . $this->redact($message);
        $this->lines[] = $line;
        ($this->sink)($line);
    }
}
