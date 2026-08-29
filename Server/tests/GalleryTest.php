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

    $runner->test('delete() applies basename() so a traversal-crafted hash cannot escape the upload directory', function (TestRunner $t): void {
        $ctx = makeGalleryTestContext();
        // API層（gallery.php）の正規表現検証を経由しない前提で、DB内に不正なhashが
        // 混入したケースを直接再現し、Gallery側の多層防御（basename()）を検証する。
        $maliciousHash = '../../../evil';
        insertGalleryTestFile($ctx['pdo'], $maliciousHash, 'png', 'image/png');

        // basename()を適用しない実装であれば削除されてしまうはずのファイル。
        $sentinelPath = realpath(sys_get_temp_dir()) . '/evil.png';
        file_put_contents($sentinelPath, 'sentinel');

        $ctx['gallery']->delete($maliciousHash);

        $t->assertTrue(file_exists($sentinelPath), 'file outside the upload directory must survive');

        @unlink($sentinelPath);
        ($ctx['cleanup'])();
    });

    $runner->test('list() includes each item\'s tags in alphabetical order', function (TestRunner $t): void {
        $ctx = makeGalleryTestContext();
        insertGalleryTestFile($ctx['pdo'], 'hash-tagged', 'png', 'image/png');
        $ctx['gallery']->addTag('hash-tagged', 'zebra');
        $ctx['gallery']->addTag('hash-tagged', 'apple');

        $result = $ctx['gallery']->list(30, 0);

        $t->assertSame(['apple', 'zebra'], $result['items'][0]['tags']);

        ($ctx['cleanup'])();
    });

    $runner->test('list() filters by tag when a tagsFilter is given', function (TestRunner $t): void {
        $ctx = makeGalleryTestContext();
        insertGalleryTestFile($ctx['pdo'], 'hash-cat', 'png', 'image/png');
        insertGalleryTestFile($ctx['pdo'], 'hash-dog', 'png', 'image/png');
        $ctx['gallery']->addTag('hash-cat', 'cat');
        $ctx['gallery']->addTag('hash-dog', 'dog');

        $result = $ctx['gallery']->list(30, 0, ['cat']);

        $t->assertSame(1, count($result['items']));
        $t->assertSame('hash-cat', $result['items'][0]['hash']);

        ($ctx['cleanup'])();
    });

    $runner->test('list() with an unmatched tagsFilter returns no items', function (TestRunner $t): void {
        $ctx = makeGalleryTestContext();
        insertGalleryTestFile($ctx['pdo'], 'hash-cat', 'png', 'image/png');
        $ctx['gallery']->addTag('hash-cat', 'cat');

        $result = $ctx['gallery']->list(30, 0, ['does-not-exist']);

        $t->assertSame(0, count($result['items']));
        $t->assertSame(false, $result['has_more']);

        ($ctx['cleanup'])();
    });

    $runner->test('list() paginates correctly when a tagsFilter is applied', function (TestRunner $t): void {
        $ctx = makeGalleryTestContext();
        for ($i = 0; $i < 3; $i++) {
            insertGalleryTestFile($ctx['pdo'], "hash-{$i}", 'png', 'image/png');
            $ctx['gallery']->addTag("hash-{$i}", 'shared-tag');
        }
        insertGalleryTestFile($ctx['pdo'], 'hash-untagged', 'png', 'image/png');

        $firstPage = $ctx['gallery']->list(2, 0, ['shared-tag']);
        $secondPage = $ctx['gallery']->list(2, 2, ['shared-tag']);

        $t->assertSame(2, count($firstPage['items']));
        $t->assertSame(true, $firstPage['has_more']);
        $t->assertSame(1, count($secondPage['items']));
        $t->assertSame(false, $secondPage['has_more']);

        ($ctx['cleanup'])();
    });

    $runner->test('list() with multiple tagsFilter entries returns only files having all of them (AND)', function (TestRunner $t): void {
        $ctx = makeGalleryTestContext();
        insertGalleryTestFile($ctx['pdo'], 'hash-both', 'png', 'image/png');
        insertGalleryTestFile($ctx['pdo'], 'hash-cat-only', 'png', 'image/png');
        $ctx['gallery']->addTag('hash-both', 'cat');
        $ctx['gallery']->addTag('hash-both', 'dog');
        $ctx['gallery']->addTag('hash-cat-only', 'cat');

        $result = $ctx['gallery']->list(30, 0, ['cat', 'dog']);

        $t->assertSame(1, count($result['items']));
        $t->assertSame('hash-both', $result['items'][0]['hash']);

        ($ctx['cleanup'])();
    });

    $runner->test('list() with untaggedOnly returns only files with no tags', function (TestRunner $t): void {
        $ctx = makeGalleryTestContext();
        insertGalleryTestFile($ctx['pdo'], 'hash-tagged', 'png', 'image/png');
        insertGalleryTestFile($ctx['pdo'], 'hash-untagged', 'png', 'image/png');
        $ctx['gallery']->addTag('hash-tagged', 'cat');

        $result = $ctx['gallery']->list(30, 0, [], true);

        $t->assertSame(1, count($result['items']));
        $t->assertSame('hash-untagged', $result['items'][0]['hash']);

        ($ctx['cleanup'])();
    });

    $runner->test('list() ignores untaggedOnly when a tagsFilter is also given', function (TestRunner $t): void {
        $ctx = makeGalleryTestContext();
        insertGalleryTestFile($ctx['pdo'], 'hash-tagged', 'png', 'image/png');
        insertGalleryTestFile($ctx['pdo'], 'hash-untagged', 'png', 'image/png');
        $ctx['gallery']->addTag('hash-tagged', 'cat');

        $result = $ctx['gallery']->list(30, 0, ['cat'], true);

        $t->assertSame(1, count($result['items']));
        $t->assertSame('hash-tagged', $result['items'][0]['hash']);

        ($ctx['cleanup'])();
    });

    $runner->test('getAllTags() returns every distinct tag name in alphabetical order', function (TestRunner $t): void {
        $ctx = makeGalleryTestContext();
        insertGalleryTestFile($ctx['pdo'], 'hash-a', 'png', 'image/png');
        insertGalleryTestFile($ctx['pdo'], 'hash-b', 'png', 'image/png');
        $ctx['gallery']->addTag('hash-a', 'zebra');
        $ctx['gallery']->addTag('hash-a', 'apple');
        $ctx['gallery']->addTag('hash-b', 'zebra');

        $t->assertSame(['apple', 'zebra'], $ctx['gallery']->getAllTags());

        ($ctx['cleanup'])();
    });

    $runner->test('addTag() creates a new tag and links it to the file', function (TestRunner $t): void {
        $ctx = makeGalleryTestContext();
        insertGalleryTestFile($ctx['pdo'], 'hash-a', 'png', 'image/png');

        $tags = $ctx['gallery']->addTag('hash-a', 'landscape');

        $t->assertSame(['landscape'], $tags);

        $tagCount = (int) $ctx['pdo']->query('SELECT COUNT(*) FROM tags')->fetchColumn();
        $t->assertSame(1, $tagCount);

        ($ctx['cleanup'])();
    });

    $runner->test('addTag() reuses an existing tag row across files instead of duplicating it', function (TestRunner $t): void {
        $ctx = makeGalleryTestContext();
        insertGalleryTestFile($ctx['pdo'], 'hash-a', 'png', 'image/png');
        insertGalleryTestFile($ctx['pdo'], 'hash-b', 'png', 'image/png');

        $ctx['gallery']->addTag('hash-a', 'shared');
        $ctx['gallery']->addTag('hash-b', 'shared');

        $tagCount = (int) $ctx['pdo']->query('SELECT COUNT(*) FROM tags')->fetchColumn();
        $t->assertSame(1, $tagCount, 'the same tag name should not create a second tags row');

        ($ctx['cleanup'])();
    });

    $runner->test('addTag() adding the same tag twice does not duplicate it on the file', function (TestRunner $t): void {
        $ctx = makeGalleryTestContext();
        insertGalleryTestFile($ctx['pdo'], 'hash-a', 'png', 'image/png');

        $ctx['gallery']->addTag('hash-a', 'repeat');
        $tags = $ctx['gallery']->addTag('hash-a', 'repeat');

        $t->assertSame(['repeat'], $tags);

        ($ctx['cleanup'])();
    });

    $runner->test('addTag() trims whitespace and ignores an empty tag name', function (TestRunner $t): void {
        $ctx = makeGalleryTestContext();
        insertGalleryTestFile($ctx['pdo'], 'hash-a', 'png', 'image/png');

        $ctx['gallery']->addTag('hash-a', '  spaced  ');
        $tagsAfterEmpty = $ctx['gallery']->addTag('hash-a', '   ');

        $t->assertSame(['spaced'], $tagsAfterEmpty);

        ($ctx['cleanup'])();
    });

    $runner->test('addTag() ignores a tag name longer than the maximum allowed length', function (TestRunner $t): void {
        $ctx = makeGalleryTestContext();
        insertGalleryTestFile($ctx['pdo'], 'hash-a', 'png', 'image/png');

        $tooLong = str_repeat('a', 51);
        $tags = $ctx['gallery']->addTag('hash-a', $tooLong);

        $t->assertSame([], $tags);

        $tagCount = (int) $ctx['pdo']->query('SELECT COUNT(*) FROM tags')->fetchColumn();
        $t->assertSame(0, $tagCount, 'an over-length tag must not be persisted');

        ($ctx['cleanup'])();
    });

    $runner->test('addTag() returns null for an unknown hash', function (TestRunner $t): void {
        $ctx = makeGalleryTestContext();

        $t->assertSame(null, $ctx['gallery']->addTag('does-not-exist', 'x'));

        ($ctx['cleanup'])();
    });

    $runner->test('removeTag() unlinks the tag from the file', function (TestRunner $t): void {
        $ctx = makeGalleryTestContext();
        insertGalleryTestFile($ctx['pdo'], 'hash-a', 'png', 'image/png');
        $ctx['gallery']->addTag('hash-a', 'keep');
        $ctx['gallery']->addTag('hash-a', 'remove-me');

        $tags = $ctx['gallery']->removeTag('hash-a', 'remove-me');

        $t->assertSame(['keep'], $tags);

        ($ctx['cleanup'])();
    });

    $runner->test('removeTag() returns null for an unknown hash', function (TestRunner $t): void {
        $ctx = makeGalleryTestContext();

        $t->assertSame(null, $ctx['gallery']->removeTag('does-not-exist', 'x'));

        ($ctx['cleanup'])();
    });

    $runner->test('addTagToMany() adds one tag to every given file and returns updated tags per hash', function (TestRunner $t): void {
        $ctx = makeGalleryTestContext();
        insertGalleryTestFile($ctx['pdo'], 'hash-a', 'png', 'image/png');
        insertGalleryTestFile($ctx['pdo'], 'hash-b', 'png', 'image/png');
        $ctx['gallery']->addTag('hash-a', 'existing');

        $result = $ctx['gallery']->addTagToMany(['hash-a', 'hash-b'], 'shared');

        $t->assertSame([], $result['not_found']);
        $t->assertSame('hash-a', $result['items'][0]['hash']);
        $t->assertSame(['existing', 'shared'], $result['items'][0]['tags']);
        $t->assertSame('hash-b', $result['items'][1]['hash']);
        $t->assertSame(['shared'], $result['items'][1]['tags']);

        $tagCount = (int) $ctx['pdo']->query('SELECT COUNT(*) FROM tags')->fetchColumn();
        $t->assertSame(2, $tagCount, 'the shared tag must reuse a single tags row');

        ($ctx['cleanup'])();
    });

    $runner->test('addTagToMany() is idempotent when a file already has the tag', function (TestRunner $t): void {
        $ctx = makeGalleryTestContext();
        insertGalleryTestFile($ctx['pdo'], 'hash-a', 'png', 'image/png');
        $ctx['gallery']->addTag('hash-a', 'repeat');

        $result = $ctx['gallery']->addTagToMany(['hash-a'], 'repeat');

        $t->assertSame(['repeat'], $result['items'][0]['tags']);
        $linkCount = (int) $ctx['pdo']->query('SELECT COUNT(*) FROM file_tags')->fetchColumn();
        $t->assertSame(1, $linkCount);

        ($ctx['cleanup'])();
    });

    $runner->test('addTagToMany() keeps an all-numeric hash as a string in the result', function (TestRunner $t): void {
        $ctx = makeGalleryTestContext();
        insertGalleryTestFile($ctx['pdo'], '12345678901234567890123456789012', 'png', 'image/png');

        $result = $ctx['gallery']->addTagToMany(['12345678901234567890123456789012'], 'tag');

        $t->assertSame('12345678901234567890123456789012', $result['items'][0]['hash']);
        $t->assertTrue(is_string($result['items'][0]['hash']), 'hash must be a string, not coerced to int');

        ($ctx['cleanup'])();
    });

    $runner->test('addTagToMany() collects unknown hashes into not_found without failing', function (TestRunner $t): void {
        $ctx = makeGalleryTestContext();
        insertGalleryTestFile($ctx['pdo'], 'hash-a', 'png', 'image/png');

        $result = $ctx['gallery']->addTagToMany(['hash-a', 'hash-missing'], 'tag');

        $t->assertSame(1, count($result['items']));
        $t->assertSame('hash-a', $result['items'][0]['hash']);
        $t->assertSame(['hash-missing'], $result['not_found']);

        ($ctx['cleanup'])();
    });

    $runner->test('addTagToMany() leaves files unchanged for an empty or over-length tag', function (TestRunner $t): void {
        $ctx = makeGalleryTestContext();
        insertGalleryTestFile($ctx['pdo'], 'hash-a', 'png', 'image/png');

        $blank = $ctx['gallery']->addTagToMany(['hash-a'], '   ');
        $tooLong = $ctx['gallery']->addTagToMany(['hash-a'], str_repeat('a', 51));

        $t->assertSame([], $blank['items'][0]['tags']);
        $t->assertSame([], $tooLong['items'][0]['tags']);
        $tagCount = (int) $ctx['pdo']->query('SELECT COUNT(*) FROM tags')->fetchColumn();
        $t->assertSame(0, $tagCount);

        ($ctx['cleanup'])();
    });

    $runner->test('removeTagFromMany() removes the tag only from files that have it', function (TestRunner $t): void {
        $ctx = makeGalleryTestContext();
        insertGalleryTestFile($ctx['pdo'], 'hash-a', 'png', 'image/png');
        insertGalleryTestFile($ctx['pdo'], 'hash-b', 'png', 'image/png');
        $ctx['gallery']->addTag('hash-a', 'drop');
        $ctx['gallery']->addTag('hash-a', 'keep');
        $ctx['gallery']->addTag('hash-b', 'keep');

        $result = $ctx['gallery']->removeTagFromMany(['hash-a', 'hash-b'], 'drop');

        $t->assertSame(['keep'], $result['items'][0]['tags']);
        $t->assertSame(['keep'], $result['items'][1]['tags']);
        $t->assertSame([], $result['not_found']);

        ($ctx['cleanup'])();
    });

    $runner->test('removeTagFromMany() collects unknown hashes into not_found', function (TestRunner $t): void {
        $ctx = makeGalleryTestContext();
        insertGalleryTestFile($ctx['pdo'], 'hash-a', 'png', 'image/png');
        $ctx['gallery']->addTag('hash-a', 'x');

        $result = $ctx['gallery']->removeTagFromMany(['hash-a', 'nope'], 'x');

        $t->assertSame(['nope'], $result['not_found']);
        $t->assertSame([], $result['items'][0]['tags']);

        ($ctx['cleanup'])();
    });

    $runner->test('deleteMany() removes DB records and stored files, reporting deleted and not_found', function (TestRunner $t): void {
        $ctx = makeGalleryTestContext();
        insertGalleryTestFile($ctx['pdo'], 'hash-a', 'png', 'image/png');
        insertGalleryTestFile($ctx['pdo'], 'hash-b', 'mp4', 'video/mp4');
        $pathA = $ctx['uploadDirPath'] . '/hash-a.png';
        $pathB = $ctx['uploadDirPath'] . '/hash-b.mp4';
        file_put_contents($pathA, 'a');
        file_put_contents($pathB, 'b');

        $result = $ctx['gallery']->deleteMany(['hash-a', 'hash-b', 'hash-missing']);

        $t->assertSame(['hash-a', 'hash-b'], $result['deleted']);
        $t->assertSame(['hash-missing'], $result['not_found']);
        $t->assertTrue(!file_exists($pathA), 'stored file A should be removed');
        $t->assertTrue(!file_exists($pathB), 'stored file B should be removed');
        $count = (int) $ctx['pdo']->query('SELECT COUNT(*) FROM files')->fetchColumn();
        $t->assertSame(0, $count);

        ($ctx['cleanup'])();
    });

    $runner->test('deleteMany() with an empty list is a no-op', function (TestRunner $t): void {
        $ctx = makeGalleryTestContext();
        insertGalleryTestFile($ctx['pdo'], 'hash-a', 'png', 'image/png');

        $result = $ctx['gallery']->deleteMany([]);

        $t->assertSame(['deleted' => [], 'not_found' => []], $result);
        $count = (int) $ctx['pdo']->query('SELECT COUNT(*) FROM files')->fetchColumn();
        $t->assertSame(1, $count);

        ($ctx['cleanup'])();
    });

    $runner->test('deleteMany() dedupes repeated hashes in the input', function (TestRunner $t): void {
        $ctx = makeGalleryTestContext();
        insertGalleryTestFile($ctx['pdo'], 'hash-a', 'png', 'image/png');

        $result = $ctx['gallery']->deleteMany(['hash-a', 'hash-a']);

        $t->assertSame(['hash-a'], $result['deleted']);

        ($ctx['cleanup'])();
    });
};
