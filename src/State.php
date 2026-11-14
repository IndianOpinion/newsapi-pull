<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull;

/**
 * Site-wide run state that is not tied to one pull: a key error and the
 * rate-limit back-off.
 */
final class State
{
    public const OPTION = 'newsapi_pull_state';

    public function __construct(private readonly Settings $settings, private readonly Clock $clock)
    {
    }

    /**
     * A key error recorded against the current key, or '' when none. A new
     * key clears it, so nothing retries until the key is changed.
     */
    public function keyError(): string
    {
        $state = $this->load();
        $error = $state['key_error'] ?? null;
        if (is_array($error) && ($error['fingerprint'] ?? '') === $this->settings->keyFingerprint()) {
            return is_string($error['code'] ?? null) ? $error['code'] : 'apiKeyInvalid';
        }
        return '';
    }

    public function setKeyError(string $code): void
    {
        $state = $this->load();
        $state['key_error'] = ['code' => $code, 'fingerprint' => $this->settings->keyFingerprint()];
        update_option(self::OPTION, $state, false);
    }

    public function backoffUntil(): int
    {
        $until = $this->load()['backoff_until'] ?? 0;
        return is_numeric($until) ? (int) $until : 0;
    }

    public function backoffActive(): bool
    {
        return $this->backoffUntil() > $this->clock->now();
    }

    public function setBackoff(int $seconds): void
    {
        $state = $this->load();
        $state['backoff_until'] = $this->clock->now() + $seconds;
        update_option(self::OPTION, $state, false);
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        $value = get_option(self::OPTION, []);
        return is_array($value) ? $value : [];
    }
}
