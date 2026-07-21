<?php

declare(strict_types=1);

namespace JustLinkIt\Server\Tests;

use JustLinkIt\Server\Database;
use JustLinkIt\Server\Viewer;
use PDO;

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Viewer.php';

function makeViewerTestDb(): PDO
{
    return Database::initialize(':memory:');
}

return function (TestRunner $runner): void {
    $runner->test('findByHash() returns null for an unknown hash', function (TestRunner $t): void {
        $pdo = makeViewerTestDb();
        $viewer = new Viewer($pdo);

        $t->assertSame(null, $viewer->findByHash('unknown'));
    });

    $runner->test('findByHash() returns the matching record', function (TestRunner $t): void {
        $pdo = makeViewerTestDb();
        $pdo->exec("INSERT INTO files (hash, extension, mime_type) VALUES ('abc123', 'mp4', 'video/mp4')");

        $viewer = new Viewer($pdo);
        $file = $viewer->findByHash('abc123');

        $t->assertSame('abc123', $file['hash'] ?? null);
        $t->assertSame('mp4', $file['extension'] ?? null);
        $t->assertSame('video/mp4', $file['mime_type'] ?? null);
    });

    $runner->test('renderHtml() includes OGP video tags pointing at the given URL', function (TestRunner $t): void {
        $viewer = new Viewer(makeViewerTestDb());
        $html = $viewer->renderHtml('https://example.com/u/abc123.mp4', 'video/mp4', 'https://example.com/og-image.png');

        $t->assertTrue(str_contains($html, 'property="og:video" content="https://example.com/u/abc123.mp4"'));
        $t->assertTrue(str_contains($html, 'property="og:video:type" content="video/mp4"'));
        $t->assertTrue(str_contains($html, 'src="https://example.com/u/abc123.mp4"'));
    });

    $runner->test('renderHtml() includes video size, OGP image, and Twitter card tags', function (TestRunner $t): void {
        $viewer = new Viewer(makeViewerTestDb());
        $html = $viewer->renderHtml('https://example.com/u/abc123.mp4', 'video/mp4', 'https://example.com/og-image.png');

        // Discord向け: 動画サイズ情報
        $t->assertTrue(str_contains($html, 'property="og:video:width" content="1280"'), 'og:video:width が出力されていること');
        $t->assertTrue(str_contains($html, 'property="og:video:height" content="720"'), 'og:video:height が出力されていること');

        // Discord / Bluesky / Twitter共通: OGP画像
        $t->assertTrue(str_contains($html, 'property="og:image" content="https://example.com/og-image.png"'), 'og:image が出力されていること');

        // Twitter/X向け: summary_large_image カード
        $t->assertTrue(str_contains($html, 'name="twitter:card" content="summary_large_image"'), 'twitter:card が出力されていること');
        $t->assertTrue(str_contains($html, 'name="twitter:title" content="JustLinkIt"'), 'twitter:title が出力されていること');
        $t->assertTrue(str_contains($html, 'name="twitter:image" content="https://example.com/og-image.png"'), 'twitter:image が出力されていること');
    });

    $runner->test('renderHtml() escapes special characters to prevent XSS', function (TestRunner $t): void {
        $viewer = new Viewer(makeViewerTestDb());
        $html = $viewer->renderHtml('https://example.com/u/"><script>alert(1)</script>.mp4', 'video/mp4', 'https://example.com/og-image.png');

        $t->assertTrue(!str_contains($html, '<script>alert(1)</script>'), 'raw script tag must not appear in output');
        $t->assertTrue(str_contains($html, '&lt;script&gt;'), 'special characters should be HTML-escaped');
    });
};
