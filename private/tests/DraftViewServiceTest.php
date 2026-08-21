<?php

declare(strict_types=1);

namespace App\Tests;

use App\DAO\JsonlWriter;
use App\Services\DraftViewService;
use App\Services\ToolLogger;
use App\Support\CssViewport;
use App\Support\HtmlToText;
use App\Support\PathGuard;
use App\Support\PathGuardException;
use PHPUnit\Framework\TestCase;

final class DraftViewServiceTest extends TestCase
{
    private string $root;
    private DraftViewService $drafts;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/cp-view-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/public_html/staging/css', 0777, true);
        mkdir($this->root . '/public_html/staging/includes', 0777, true);
        mkdir($this->root . '/private/var/data', 0777, true);
        file_put_contents($this->root . '/public_html/staging/css/site.css', "body{color:#111}
@media (max-width: 800px) { body { font-size: 15px; } }
@media (min-width: 901px) { .hero { min-height: 20rem; } }
");
        file_put_contents($this->root . '/public_html/staging/index.php', '<?php
$title = "Hello";
?><html><head><title><?= htmlspecialchars($title) ?></title></head><body>
<h1>Welcome</h1>
<img src="<?= htmlspecialchars($BASE) ?>images/hero.jpg" alt="Hero">
<p>BASE=<?= htmlspecialchars($BASE) ?></p>
</body></html>
');
        $guard = new PathGuard(
            $this->root . '/public_html/staging',
            $this->root . '/public_html',
            $this->root . '/private',
        );
        $this->drafts = new DraftViewService(
            $guard,
            new ToolLogger(new JsonlWriter($this->root . '/private/var/data/tools.jsonl')),
            new HtmlToText(),
        );
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testHttpRendersPhpWithPreviewBase(): void
    {
        $out = $this->drafts->http('');
        self::assertSame(200, $out['status']);
        self::assertStringContainsString('text/html', $out['headers']['Content-Type'] ?? '');
        self::assertStringContainsString('Welcome', $out['body']);
        self::assertStringContainsString('BASE=/cp/preview/', $out['body']);
        self::assertStringContainsString('/cp/preview/images/hero.jpg', $out['body']);
    }

    public function testViewDraftUsesDesktopMedia(): void
    {
        $result = $this->drafts->view(['path' => 'index.php', 'size' => 'desktop']);
        self::assertTrue($result['success'], $result['message']);
        $data = $result['data'];
        self::assertSame(1440, $data['viewport']['width']);
        self::assertSame('Hello', $data['title']);
        self::assertContains('h1 Welcome', $data['headings']);
        self::assertFalse($data['screenshot']);
        $media = implode("\n", $data['media']);
        self::assertStringContainsString('min-width: 901px', $media);
        self::assertStringNotContainsString('max-width: 800px', $media);
    }

    public function testViewDraftPhoneMatchesNarrowMedia(): void
    {
        $result = $this->drafts->view(['path' => '/', 'size' => 'phone']);
        self::assertTrue($result['success'], $result['message']);
        $media = implode("\n", $result['data']['media']);
        self::assertStringContainsString('max-width: 800px', $media);
        self::assertSame(390, $result['data']['viewport']['width']);
    }

    public function testRejectsParentEscape(): void
    {
        $this->expectException(PathGuardException::class);
        $this->drafts->resolveFile('../index.php');
    }

    public function testCssViewportClampsAndMaps(): void
    {
        self::assertSame(1440, CssViewport::width('desktop', null));
        self::assertSame(1920, CssViewport::width('wide', null));
        self::assertSame(320, CssViewport::width(null, 10));
        self::assertSame(2560, CssViewport::width(null, 9000));
    }

    private function rm(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() && !$file->isLink() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
