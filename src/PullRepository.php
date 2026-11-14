<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull;

/**
 * Stores pull definitions and their run state in one non-autoloaded option.
 */
final class PullRepository
{
    public const OPTION = 'newsapi_pull_pulls';

    public const STATE_DEFAULTS = [
        'last_run' => 0,
        'last_success' => 0,
        'last_outcome' => '',
        'last_reason' => '',
        'last_summary' => [],
        'failures' => 0,
        'disabled_reason' => '',
        'disabled_message' => '',
        'backoff_until' => 0,
        'left_out' => 0,
    ];

    /**
     * @return array<string, PullDefinition>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->load() as $slug => $row) {
            $out[(string) $slug] = PullDefinition::fromArray(is_array($row['definition'] ?? null) ? $row['definition'] : []);
        }
        return $out;
    }

    public function get(string $slug): ?PullDefinition
    {
        $rows = $this->load();
        if (!isset($rows[$slug]['definition']) || !is_array($rows[$slug]['definition'])) {
            return null;
        }
        return PullDefinition::fromArray($rows[$slug]['definition']);
    }

    /**
     * Saves a definition. Saving clears a disabled state (the operator has
     * edited the pull) and keeps the rest of the state.
     */
    public function save(PullDefinition $pull, bool $clearDisabled = true): void
    {
        $rows = $this->load();
        $state = $this->stateFrom($rows[$pull->slug] ?? []);
        if ($clearDisabled) {
            $state['disabled_reason'] = '';
            $state['disabled_message'] = '';
        }
        $rows[$pull->slug] = ['definition' => $pull->toArray(), 'state' => $state];
        $this->store($rows);
    }

    public function delete(string $slug): void
    {
        $rows = $this->load();
        unset($rows[$slug]);
        $this->store($rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function state(string $slug): array
    {
        return $this->stateFrom($this->load()[$slug] ?? []);
    }

    /**
     * @param array<string, mixed> $state
     */
    public function saveState(string $slug, array $state): void
    {
        $rows = $this->load();
        if (!isset($rows[$slug])) {
            return;
        }
        $rows[$slug]['state'] = array_merge($this->stateFrom($rows[$slug]), array_intersect_key($state, self::STATE_DEFAULTS));
        $this->store($rows);
    }

    /**
     * @param array<mixed> $row
     * @return array<string, mixed>
     */
    private function stateFrom(array $row): array
    {
        $state = is_array($row['state'] ?? null) ? $row['state'] : [];
        return array_merge(self::STATE_DEFAULTS, array_intersect_key($state, self::STATE_DEFAULTS));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function load(): array
    {
        $value = get_option(self::OPTION, []);
        $out = [];
        if (is_array($value)) {
            foreach ($value as $slug => $row) {
                if (is_array($row)) {
                    $out[(string) $slug] = $row;
                }
            }
        }
        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $rows
     */
    private function store(array $rows): void
    {
        ksort($rows);
        update_option(self::OPTION, $rows, false);
    }
}
