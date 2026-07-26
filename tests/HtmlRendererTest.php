<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/index.php';

final class HtmlRendererTest extends TestCase
{
    protected function setUp(): void
    {
        global $lang, $availableLangs;
        $lang = ['title' => 'Test Title', 'greet' => 'Hi :name'];
        $availableLangs = ['en', 'de'];
        $_SESSION = [];
    }

    public function testResolveDashboardViewDefaultsToHome(): void
    {
        $this->assertSame('home', resolveDashboardView(null));
        $this->assertSame('home', resolveDashboardView(''));
        $this->assertSame('home', resolveDashboardView('invalid'));
    }

    public function testResolveDashboardViewValidValues(): void
    {
        $this->assertSame('home', resolveDashboardView('home'));
        $this->assertSame('updates', resolveDashboardView('updates'));
        $this->assertSame('environment', resolveDashboardView('environment'));
        $this->assertSame('databases', resolveDashboardView('databases'));
        $this->assertSame('install-uuid', resolveDashboardView('install-uuid'));
        $this->assertSame('installer', resolveDashboardView('installer'));
        $this->assertSame('system', resolveDashboardView('system'));
    }

    public function testResolveInstallerTabDefaultsToBranches(): void
    {
        $this->assertSame('branches', resolveInstallerTab(null));
        $this->assertSame('branches', resolveInstallerTab(''));
        $this->assertSame('branches', resolveInstallerTab('invalid'));
        $this->assertSame('tags', resolveInstallerTab('tags'));
    }

    public function testResolveDashboardState(): void
    {
        $result = resolveDashboardState('updates');
        $this->assertSame('updates', $result['view']);
        $this->assertNull($result['itab']);

        $result = resolveDashboardState('installer', 'tags');
        $this->assertSame('installer', $result['view']);
        $this->assertSame('tags', $result['itab']);
    }

    public function testBuildDashboardViewHref(): void
    {
        $href = buildDashboardViewHref('home');
        $this->assertStringNotContainsString('view=', $href);

        $href = buildDashboardViewHref('updates');
        $this->assertStringContainsString('view=updates', $href);

        $href = buildDashboardViewHref('installer', 'tags');
        $this->assertStringContainsString('view=installer', $href);
        $this->assertStringContainsString('itab=tags', $href);
    }

    public function testLucideIconReturnsSvg(): void
    {
        $svg = lucideIcon('home');
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('width="16"', $svg);
        $this->assertStringContainsString('height="16"', $svg);
    }

    public function testLucideIconCustomSize(): void
    {
        $svg = lucideIcon('settings', 22);
        $this->assertStringContainsString('width="22"', $svg);
        $this->assertStringContainsString('height="22"', $svg);
    }

    public function testLucideIconUnknownReturnsEmpty(): void
    {
        $this->assertSame('', lucideIcon('definitely-not-existing-icon'));
    }

    public function testRenderDropdownBasic(): void
    {
        $html = renderDropdown('test', [
            ['value' => 'a', 'label' => 'Option A'],
            ['value' => 'b', 'label' => 'Option B'],
        ], 'a');

        $this->assertStringContainsString('name="test"', $html);
        $this->assertStringContainsString('value="a"', $html);
        $this->assertStringContainsString('Option A', $html);
        $this->assertStringContainsString('Option B', $html);
    }

    public function testRenderDropdownEmptyOptions(): void
    {
        $html = renderDropdown('test', [], '', false, '', true);
        $this->assertStringContainsString('is-disabled', $html);
    }

    public function testRenderModal(): void
    {
        $html = renderModal('my-modal', 'My Title', '<p>body</p>', 'Close');
        $this->assertStringContainsString('id="my-modal"', $html);
        $this->assertStringContainsString('My Title', $html);
        $this->assertStringContainsString('<p>body</p>', $html);
        $this->assertStringContainsString('Close', $html);
    }

    public function testRenderConfirmAttributes(): void
    {
        $attrs = renderConfirmAttributes('Title', 'Message', 'Submit');
        $this->assertStringContainsString('data-confirm-title', $attrs);
        $this->assertStringContainsString('Title', $attrs);
        $this->assertStringContainsString('Message', $attrs);
        $this->assertStringContainsString('Submit', $attrs);
    }

    public function testFormatVersionBadge(): void
    {
        $this->assertStringContainsString('unknown', formatVersionBadge('unknown'));
        $this->assertStringContainsString('1.0.0', formatVersionBadge('1.0.0'));
        $this->assertStringContainsString('unknown', formatVersionBadge(''));
    }

    public function testRenderWelcomeBox(): void
    {
        $html = renderWelcomeBox('Welcome', 'Subtitle', [
            ['label' => 'Docs', 'href' => 'https://example.com', 'icon' => 'external-link', 'external' => true],
        ], []);

        $this->assertStringContainsString('welcome-card', $html);
        $this->assertStringContainsString('Welcome', $html);
        $this->assertStringContainsString('Subtitle', $html);
        $this->assertStringContainsString('Docs', $html);
        $this->assertStringContainsString('target="_blank"', $html);
    }

    public function testRenderHomeSectionsWithItems(): void
    {
        $sections = [
            [
                'title' => 'Section A',
                'icon' => 'settings',
                'href' => '?view=environment',
                'items' => [
                    ['icon' => 'database', 'label' => 'DB', 'value' => 'db1'],
                ],
            ],
        ];

        $html = renderHomeSections('System', [], $sections);

        $this->assertStringContainsString('home-stack', $html);
        $this->assertStringContainsString('Section A', $html);
        $this->assertStringContainsString('db1', $html);
    }

    public function testRenderHomeSectionsWithInfoItems(): void
    {
        $infoItems = [
            ['icon' => 'code', 'label' => 'PHP', 'value' => '8.5'],
        ];

        $html = renderHomeSections('System Info', $infoItems, []);

        $this->assertStringContainsString('System Info', $html);
        $this->assertStringContainsString('8.5', $html);
    }

    public function testRenderPageProducesFullHtml(): void
    {
        $html = renderPage('My Page', '<p>Content</p>', null, null, false);

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('My Page', $html);
        $this->assertStringContainsString('<p>Content</p>', $html);
        $this->assertStringContainsString('</html>', $html);
    }

    public function testRenderPageShowsError(): void
    {
        $html = renderPage('My Page', '', 'An error occurred', null, false);

        $this->assertStringContainsString('class="error"', $html);
        $this->assertStringContainsString('An error occurred', $html);
    }

    public function testRenderDashboardStateInputsEmptyForHome(): void
    {
        $this->assertSame('', renderDashboardStateInputs('home'));
    }

    public function testRenderDashboardStateInputsForView(): void
    {
        $html = renderDashboardStateInputs('updates');
        $this->assertStringContainsString('name="view"', $html);
        $this->assertStringContainsString('value="updates"', $html);
    }

    public function testRenderDashboardStateInputsForInstaller(): void
    {
        $html = renderDashboardStateInputs('installer', 'tags');
        $this->assertStringContainsString('name="view"', $html);
        $this->assertStringContainsString('value="installer"', $html);
        $this->assertStringContainsString('name="itab"', $html);
        $this->assertStringContainsString('value="tags"', $html);
    }
}