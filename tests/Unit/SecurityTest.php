<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull\Tests\Unit;

use IndianOpinion\NewsApiPull\Admin\AdminPage;
use IndianOpinion\NewsApiPull\Tests\TestCase;

final class SecurityTest extends TestCase
{
    public function testT95ActionsRefusedWithoutCapabilityOrNonce(): void
    {
        $this->configure();
        $this->addPull();
        $page = new AdminPage($this->plugin);
        $posts = [
            'save_settings' => ['plan' => 'developer', 'kill_switch' => '1'],
            'save_pull' => ['slug' => 'new-one', 'endpoint' => 'headlines'],
            'run_now' => ['slug' => 'top'],
            'toggle_pause' => ['slug' => 'top'],
            'delete_pull' => ['slug' => 'top'],
            'export' => [],
            'import' => ['json' => '[{"slug":"imp","endpoint":"headlines"}]'],
            'add_example' => [],
            'refresh_catalogue' => [],
        ];
        $before = \WpStub::$options;
        foreach ($posts as $action => $post) {
            \WpStub::$caps = ['edit_posts'];
            $result = $page->handle($action, $post + ['_wpnonce' => wp_create_nonce('newsapi_pull_' . $action)]);
            self::assertFalse($result['ok'], "$action without capability");
            self::assertArrayNotHasKey('download', $result);

            \WpStub::$caps = ['manage_options'];
            $result = $page->handle($action, $post + ['_wpnonce' => 'bad']);
            self::assertFalse($result['ok'], "$action with a bad nonce");
            $result = $page->handle($action, $post + ['_wpnonce' => wp_create_nonce('newsapi_pull_other')]);
            self::assertFalse($result['ok'], "$action with another action's nonce");
        }
        self::assertSame($before, \WpStub::$options);
        self::assertSame([], \WpStub::$requests);
    }

    public function testT96KeyNeverInRenderedHtml(): void
    {
        $this->configure();
        $this->addPull();
        $page = new AdminPage($this->plugin);
        ob_start();
        $page->render();
        $page->notices();
        $page->renderEditor('top');
        $html = (string) ob_get_clean();
        self::assertStringNotContainsString(self::KEY, $html);
        self::assertStringContainsString('<code>' . substr(self::KEY, -4) . '</code>', $html);
        self::assertStringContainsString('type="password"', $html);
        self::assertStringContainsString('name="api_key" value=""', $html);
        self::assertStringNotContainsString(self::KEY, $page->export());

        $this->constants['NEWSAPI_PULL_API_KEY'] = 'constkey00000000000000000000wxyz';
        ob_start();
        $page->render();
        $html = (string) ob_get_clean();
        self::assertStringNotContainsString('constkey', $html);
        self::assertStringContainsString('<code>wxyz</code>', $html);
        self::assertStringNotContainsString('name="api_key"', $html);
    }

    public function testBlankKeyFieldKeepsTheStoredKey(): void
    {
        $this->configure();
        $values = $this->plugin->settings->sanitise(['plan' => 'paid', 'api_key' => '']);
        self::assertSame(self::KEY, $values['api_key']);
    }

    public function testT97ImportIgnoresKeyFieldsAndValidates(): void
    {
        $this->configure();
        $json = (string) json_encode(['pulls' => [
            ['slug' => 'imported', 'endpoint' => 'headlines', 'country' => 'gb', 'api_key' => 'evil0000000000000000000000000000', 'apiKey' => 'x'],
            ['slug' => 'broken', 'endpoint' => 'nowhere'],
        ]]);
        $result = (new AdminPage($this->plugin))->handle('import', ['json' => $json, '_wpnonce' => wp_create_nonce('newsapi_pull_import')]);
        self::assertStringContainsString('1 pulls imported', $result['message']);
        self::assertArrayHasKey('broken', $result['errors']);
        self::assertSame(self::KEY, $this->plugin->settings->apiKey());
        self::assertStringNotContainsString('evil', (string) json_encode(\WpStub::$options));
        $pull = $this->plugin->pulls->get('imported');
        self::assertNotNull($pull);
        self::assertTrue($pull->paused);
    }

    public function testT98ReadmeCarriesTheRequiredLines(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');
        self::assertStringContainsString('Written from scratch; not derived from any third-party plugin.', $readme);
        self::assertMatchesRegularExpression('/came out of the work on indianopinion\.org.*written from scratch.*independent of and not affiliated with any third-party service/s', $readme);
        self::assertMatchesRegularExpression('/^## Free tier and drafts$/m', $readme);
        $section = substr($readme, (int) strpos($readme, '## Free tier and drafts'));
        $section = substr($section, 0, (int) (strpos($section, "\n## ", 5) ?: strlen($section)));
        self::assertStringContainsString('`local`', $section);
        self::assertStringContainsString('`development`', $section);
        self::assertStringContainsString('never publishes', $section);
        self::assertStringContainsString('lead for an editor', $section);
    }

    public function testNoticesAreEscaped(): void
    {
        $this->configure();
        $this->addPull();
        $this->plugin->pulls->saveState('top', ['disabled_reason' => 'pull_invalid', 'disabled_message' => '<script>x</script>']);
        ob_start();
        (new AdminPage($this->plugin))->notices();
        $html = (string) ob_get_clean();
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }
}
