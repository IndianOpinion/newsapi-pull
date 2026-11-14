<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull;

/**
 * One WP-Cron event per active pull at its interval, plus one daily
 * maintenance event (PRD 4.11).
 */
final class Scheduler
{
    public const HOOK = 'newsapi_pull_scheduled_run';

    public const DAILY_HOOK = 'newsapi_pull_daily';

    public function __construct(private readonly PullRepository $pulls, private readonly Clock $clock)
    {
    }

    public static function recurrence(int $minutes): string
    {
        return 'newsapi_pull_' . max(15, $minutes) . 'min';
    }

    /**
     * Adds a schedule for every interval in use.
     *
     * @param array<string, array<string, mixed>> $schedules
     * @return array<string, array<string, mixed>>
     */
    public function cronSchedules(array $schedules): array
    {
        foreach ($this->pulls->all() as $pull) {
            $minutes = max(15, $pull->interval);
            $schedules[self::recurrence($minutes)] = [
                'interval' => $minutes * 60,
                'display' => sprintf('Every %d minutes (newsapi-pull)', $minutes),
            ];
        }
        return $schedules;
    }

    public function isActive(PullDefinition $pull): bool
    {
        $state = $this->pulls->state($pull->slug);
        return !$pull->paused && $state['disabled_reason'] === '';
    }

    /**
     * Reschedules or clears one pull's event to match its definition.
     */
    public function sync(PullDefinition $pull): void
    {
        $this->unschedule($pull->slug);
        if (!$this->isActive($pull)) {
            return;
        }
        $recurrence = self::recurrence($pull->interval);
        // The recurrence must be known before the event is stored.
        add_filter('cron_schedules', [$this, 'cronSchedules']);
        wp_schedule_event($this->clock->now() + $pull->interval * 60, $recurrence, self::HOOK, [$pull->slug]);
    }

    public function unschedule(string $slug): void
    {
        wp_clear_scheduled_hook(self::HOOK, [$slug]);
    }

    /**
     * Restores the event of any active pull that has none (admin load).
     *
     * @return list<string> Slugs that were rescheduled.
     */
    public function selfHeal(): array
    {
        $fixed = [];
        foreach ($this->pulls->all() as $slug => $pull) {
            if ($this->isActive($pull) && wp_next_scheduled(self::HOOK, [$slug]) === false) {
                $this->sync($pull);
                $fixed[] = $slug;
            }
        }
        if (wp_next_scheduled(self::DAILY_HOOK) === false) {
            wp_schedule_event($this->clock->now() + 3600, 'daily', self::DAILY_HOOK);
        }
        return $fixed;
    }

    /** Clears every event this plugin scheduled. */
    public function clearAll(): void
    {
        foreach (array_keys($this->pulls->all()) as $slug) {
            $this->unschedule($slug);
        }
        wp_unschedule_hook(self::HOOK);
        wp_clear_scheduled_hook(self::DAILY_HOOK);
    }
}
