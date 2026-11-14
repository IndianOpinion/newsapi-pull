<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull\Tests\Unit;

use IndianOpinion\NewsApiPull\Admin\AdminPage;
use IndianOpinion\NewsApiPull\Cli\Command;
use IndianOpinion\NewsApiPull\Tests\TestCase;

final class PlanEnvironmentTest extends TestCase
{
    public function testT1NoPlanSendsNothing(): void
    {
        \WpStub::$env = 'local';
        $this->plugin->settings->save(['api_key' => self::KEY]);
        $this->addPull();
        $summary = $this->runPull();
        self::assertSame('plan_missing', $summary['reason']);
        self::assertSame([], \WpStub::$requests);
        $notices = (new AdminPage($this->plugin))->noticeList();
        self::assertStringContainsString('Choose your plan', $notices[0][1]);
    }

    public function testT2DeveloperOnProductionAndStagingIsRefused(): void
    {
        foreach (['production', 'staging'] as $env) {
            $this->configure('developer', $env);
            $this->addPull();
            $summary = $this->runPull('top', true);
            self::assertSame('plan_environment', $summary['reason'], $env);
            self::assertSame(0, $summary['requests']);
            self::assertSame([], \WpStub::$requests);
            $notices = (new AdminPage($this->plugin))->noticeList();
            self::assertSame('error', $notices[0][0]);
            self::assertStringContainsString('development and testing only', $notices[0][1]);
        }
    }

    public function testT3DeveloperOnLocalAndDevelopmentProceeds(): void
    {
        foreach (['local', 'development'] as $env) {
            \WpStub::$requests = [];
            $this->configure('developer', $env);
            $this->addPull(['slug' => 'p-' . $env]);
            $this->queueArticles([]);
            $summary = $this->runPull('p-' . $env, true);
            self::assertSame('ok', $summary['outcome'], $env);
            self::assertCount(1, \WpStub::$requests);
        }
    }

    public function testT4FilterCanOnlyMakeTheRuleStricter(): void
    {
        $this->configure('developer', 'production');
        $this->addPull();
        add_filter('newsapi_pull_environment_allowed', static fn (): bool => true);
        self::assertSame('plan_environment', $this->runPull('top', true)['reason']);

        \WpStub::reset();
        $this->setUp();
        $this->configure('developer', 'local');
        $this->addPull();
        add_filter('newsapi_pull_environment_allowed', static fn (): bool => false);
        self::assertSame('plan_environment', $this->runPull('top', true)['reason']);
        self::assertSame([], \WpStub::$requests);
    }

    public function testT5PaidOnProductionRuns(): void
    {
        $this->configure('paid', 'production');
        $this->addPull();
        $this->queueArticles([]);
        self::assertSame('ok', $this->runPull()['outcome']);
        self::assertCount(1, \WpStub::$requests);
    }

    public function testT6CliAndRunNowObeyTheRule(): void
    {
        $this->configure('developer', 'production');
        $this->addPull();
        $cmd = new Command($this->plugin);
        $cmd->run(['top'], ['yes' => true]);
        self::assertStringContainsString('plan_environment', implode("\n", \WP_CLI::$out));

        $page = new AdminPage($this->plugin);
        $result = $page->handle('run_now', ['slug' => 'top', '_wpnonce' => wp_create_nonce('newsapi_pull_run_now')]);
        self::assertFalse($result['ok']);
        self::assertStringContainsString('plan_environment', $result['message']);
        self::assertSame([], \WpStub::$requests);
    }

    public function testPlanConstantOverridesTheSetting(): void
    {
        $this->constants['NEWSAPI_PULL_PLAN'] = 'paid';
        $this->configure('developer', 'production');
        self::assertSame('paid', $this->plugin->settings->plan());
        self::assertTrue($this->plugin->settings->environmentAllowed());
    }
}
