<?php

declare(strict_types=1);

namespace JustLinkIt\Server\Tests;

use JustLinkIt\Server\Config;
use JustLinkIt\Server\Database;
use JustLinkIt\Server\Gallery;
use PDO;

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Gallery.php';

/**
 * @return array{gallery: Gallery, pdo: PDO, uploadDirPath: string, cleanup: callable}
 */
function makeGalleryTestContext(): array
{
    $base = sys_get_temp_dir() . '/justlinkit_gallery_' . uniqid();
    $uploadDirPath = $base . '/public/u';
    mkdir($uploadDirPath, 0755, true);

    $pdo = Database::initialize(':memory:');
    $config = new Config(['upload_dir_path' => $uploadDirPath]);

    return [
        'gallery' => new Gallery($pdo, $config),
        'pdo' => $pdo,
        'uploadDirPath' => $uploadDirPath,
        'cleanup' => static function () use ($base): void {
            array_map('unlink', glob($base . '/public/u/*') ?: []);
            @rmdir($base . '/public/u');
            @rmdir($base . '/public');
            @rmdir($base);
        },
    ];
}

function insertGalleryTestFile(PDO $pdo, string $hash, string $extension, string $mimeType): void
{
    $stmt = $pdo->prepare('INSERT INTO files (hash, extension, mime_type) VALUES (:hash, :extension, :mime_type)');
    $stmt->execute(['hash' => $hash, 'extension' => $extension, 'mime_type' => $mimeType]);
}

return function (TestRunner $runner): void {
    $runner->test('list() returns items newest-first with is_video flags', function (TestRunner $t): void {
        $ctx = makeGalleryTestContext();
        insertGalleryTestFile($ctx['pdo'], 'hash-image', 'png', 'image/png');
        insertGalleryTestFile($ctx['pdo'], 'hash-video', 'mp4', 'video/mp4');

        $result = $ctx['gallery']->list(30, 0);

        $t->assertSame(2, count($result['items']));
        $t->assertSame(false, $result['has_more']);
        $t->assertSame('hash-video', $result['items'][0]['hash']);
        $t->assertSame(true, $result['items'][0]['is_video']);
        $t->assertSame('hash-image', $result['items'][1]['hash']);
        $t->assertSame(false, $result['items'][1]['is_video']);

        ($ctx['cleanup'])();
    });

    $runner->test('list() paginates with limit/offset and reports has_more', function (TestRunner $t): void {
        $ctx = makeGalleryTestContext();
        for ($i = 0; $i < 5; $i++) {
            insertGalleryTestFile($ctx['pdo'], "hash-{$i}", 'png', 'image/png');
        }

        $firstPage = $ctx['gallery']->list(2, 0);
        $secondPage = $ctx['gallery']->list(2, 2);
        $lastPage = $ctx['gallery']->list(2, 4);

        $t->assertSame(2, count($firstPage['items']));
        $t->assertSame(true, $firstPage['has_more']);
        $t->assertSame(2, count($secondPage['items']));
        $t->assertSame(true, $secondPage['has_more']);
        $t->assertSame(1, count($lastPage['items']));
        $t->assertSame(false, $lastPage['has_more']);

        ($ctx['cleanup'])();
    });

    $runner->test('delete() removes the DB record and the stored file', function (TestRunner $t): void {
        $ctx = makeGalleryTestContext();
        insertGalleryTestFile($ctx['pdo'], 'hash-to-delete', 'png', 'image/png');
        $filePath = $ctx['uploadDirPath'] . '/hash-to-delete.png';
        file_put_contents($filePath, 'dummy');

        $deleted = $ctx['gallery']->delete('hash-to-delete');

        $t->assertSame(true, $deleted);
        $t->assertTrue(!file_exists($filePath), 'stored file should be removed');

        $count = (int) $ctx['pdo']->query('SELECT COUNT(*) FROM files')->fetchColumn();
        $t->assertSame(0, $count);

        ($ctx['cleanup'])();
    });

    $runner->test('delete() returns false for an unknown hash', function (TestRunner $t): void {
        $ctx = makeGalleryTestContext();

        $t->assertSame(false, $ctx['gallery']->delete('does-not-exist'));

        ($ctx['cleanup'])();
    });
};
