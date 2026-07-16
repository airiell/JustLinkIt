<?php

declare(strict_types=1);

namespace JustLinkIt\Server\Tests;

use JustLinkIt\Server\Config;
use JustLinkIt\Server\Database;
use JustLinkIt\Server\Uploader;
use PDO;

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Uploader.php';

// 1x1 の透明PNG。MIME実体検証をパスできる最小のテスト用画像データ。
const TEST_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

// ftypボックスのみの最小MP4。finfoでvideo/mp4と判定される最小限のテスト用動画データ。
const TEST_MP4_HEX = '000000186674797069736f6d0000020069736f6d69736f326d703431';

/**
 * @return array{uploader: Uploader, pdo: PDO, uploadDirPath: string, cleanup: callable}
 */
function makeUploaderTestContext(int $maxFileSize = 1024 * 1024): array
{
    $base = sys_get_temp_dir() . '/justlinkit_uploader_' . uniqid();
    $uploadDirPath = $base . '/public/u';
    mkdir($uploadDirPath, 0755, true);

    $dbPath = $base . '/gallery.sqlite3';
    $pdo = Database::initialize($dbPath);

    $config = new Config([
        'max_file_size' => $maxFileSize,
        'database_path' => $dbPath,
        'upload_dir_path' => $uploadDirPath,
    ]);

    // 実際のHTTPアップロードではないため is_uploaded_file() は常にfalseを返す。
    // テストでは「ファイルが存在すること」で代替する。
    $uploader = new Uploader($pdo, $config, static fn (string $path): bool => file_exists($path));

    return [
        'uploader' => $uploader,
        'pdo' => $pdo,
        'uploadDirPath' => $uploadDirPath,
        'cleanup' => static function () use ($base): void {
            array_map('unlink', glob($base . '/public/u/*') ?: []);
            @rmdir($base . '/public/u');
            @rmdir($base . '/public');
            @unlink($base . '/gallery.sqlite3');
            @rmdir($base);
        },
    ];
}

function writeUploaderTestFile(string $contents): string
{
    $path = sys_get_temp_dir() . '/justlinkit_src_' . uniqid();
    file_put_contents($path, $contents);

    return $path;
}

return function (TestRunner $runner): void {
    $runner->test('handleUpload() stores a valid PNG and returns its hash', function (TestRunner $t): void {
        $ctx = makeUploaderTestContext();
        $png = base64_decode(TEST_PNG_BASE64);
        $srcPath = writeUploaderTestFile($png);

        $result = $ctx['uploader']->handleUpload([
            'name' => 'screenshot.png',
            'type' => 'image/png',
            'tmp_name' => $srcPath,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($png),
        ]);

        $expectedHash = hash('sha256', $png);

        $t->assertSame(true, $result['success'] ?? null);
        $t->assertSame($expectedHash, $result['hash'] ?? null);
        $t->assertSame('png', $result['extension'] ?? null);
        $t->assertSame(false, $result['is_video'] ?? null);
        $t->assertTrue(
            file_exists($ctx['uploadDirPath'] . '/' . $expectedHash . '.png'),
            'stored file should exist'
        );

        $stmt = $ctx['pdo']->prepare('SELECT extension, mime_type FROM files WHERE hash = :hash');
        $stmt->execute(['hash' => $expectedHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $t->assertSame('png', $row['extension'] ?? null);
        $t->assertSame('image/png', $row['mime_type'] ?? null);

        @unlink($srcPath);
        ($ctx['cleanup'])();
    });

    $runner->test('handleUpload() stores a valid MP4 and flags it as video', function (TestRunner $t): void {
        $ctx = makeUploaderTestContext();
        $mp4 = hex2bin(TEST_MP4_HEX);
        $srcPath = writeUploaderTestFile($mp4);

        $result = $ctx['uploader']->handleUpload([
            'name' => 'recording.mp4',
            'type' => 'video/mp4',
            'tmp_name' => $srcPath,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($mp4),
        ]);

        $expectedHash = hash('sha256', $mp4);

        $t->assertSame(true, $result['success'] ?? null);
        $t->assertSame($expectedHash, $result['hash'] ?? null);
        $t->assertSame('mp4', $result['extension'] ?? null);
        $t->assertSame(true, $result['is_video'] ?? null);
        $t->assertTrue(
            file_exists($ctx['uploadDirPath'] . '/' . $expectedHash . '.mp4'),
            'stored file should exist'
        );

        @unlink($srcPath);
        ($ctx['cleanup'])();
    });

    $runner->test('handleUpload() rejects a file whose real content is not an allowed type', function (TestRunner $t): void {
        $ctx = makeUploaderTestContext();
        $srcPath = writeUploaderTestFile('this is not an image, just plain text');

        $result = $ctx['uploader']->handleUpload([
            'name' => 'fake.png',
            'type' => 'image/png',
            'tmp_name' => $srcPath,
            'error' => UPLOAD_ERR_OK,
            'size' => 40,
        ]);

        $t->assertSame(false, $result['success'] ?? null);
        $t->assertSame(415, $result['code'] ?? null);

        @unlink($srcPath);
        ($ctx['cleanup'])();
    });

    $runner->test('handleUpload() rejects a file larger than the configured limit', function (TestRunner $t): void {
        $ctx = makeUploaderTestContext(maxFileSize: 10);
        $png = base64_decode(TEST_PNG_BASE64);
        $srcPath = writeUploaderTestFile($png);

        $result = $ctx['uploader']->handleUpload([
            'name' => 'screenshot.png',
            'type' => 'image/png',
            'tmp_name' => $srcPath,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($png),
        ]);

        $t->assertSame(false, $result['success'] ?? null);
        $t->assertSame(413, $result['code'] ?? null);

        @unlink($srcPath);
        ($ctx['cleanup'])();
    });

    $runner->test('handleUpload() rejects a zero-byte file with a distinct message from size-limit', function (TestRunner $t): void {
        $ctx = makeUploaderTestContext();
        $png = base64_decode(TEST_PNG_BASE64);
        $srcPath = writeUploaderTestFile($png);

        $result = $ctx['uploader']->handleUpload([
            'name' => 'screenshot.png',
            'type' => 'image/png',
            'tmp_name' => $srcPath,
            'error' => UPLOAD_ERR_OK,
            'size' => 0,
        ]);

        $t->assertSame(false, $result['success'] ?? null);
        $t->assertSame(400, $result['code'] ?? null);
        $t->assertTrue(
            !str_contains($result['message'] ?? '', '上限'),
            'zero-size message must not claim the file exceeds the limit'
        );

        @unlink($srcPath);
        ($ctx['cleanup'])();
    });

    $runner->test('handleUpload() rejects a PHP upload error code', function (TestRunner $t): void {
        $ctx = makeUploaderTestContext();

        $result = $ctx['uploader']->handleUpload([
            'name' => 'screenshot.png',
            'type' => 'image/png',
            'tmp_name' => '',
            'error' => UPLOAD_ERR_INI_SIZE,
            'size' => 0,
        ]);

        $t->assertSame(false, $result['success'] ?? null);
        $t->assertSame(400, $result['code'] ?? null);

        ($ctx['cleanup'])();
    });

    $runner->test('handleUpload() rejects a tmp_name that fails the uploaded-file check', function (TestRunner $t): void {
        $ctx = makeUploaderTestContext();
        // このパスは意図的に作成せず、file_exists()チェッカーがfalseを返す状況を再現する。
        $missingPath = sys_get_temp_dir() . '/justlinkit_missing_' . uniqid();

        $result = $ctx['uploader']->handleUpload([
            'name' => 'screenshot.png',
            'type' => 'image/png',
            'tmp_name' => $missingPath,
            'error' => UPLOAD_ERR_OK,
            'size' => 100,
        ]);

        $t->assertSame(false, $result['success'] ?? null);
        $t->assertSame(400, $result['code'] ?? null);

        ($ctx['cleanup'])();
    });

    $runner->test('handleUpload() deduplicates identical content into a single record', function (TestRunner $t): void {
        $ctx = makeUploaderTestContext();
        $png = base64_decode(TEST_PNG_BASE64);

        $srcPath1 = writeUploaderTestFile($png);
        $first = $ctx['uploader']->handleUpload([
            'name' => 'a.png',
            'type' => 'image/png',
            'tmp_name' => $srcPath1,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($png),
        ]);

        $srcPath2 = writeUploaderTestFile($png);
        $second = $ctx['uploader']->handleUpload([
            'name' => 'b.png',
            'type' => 'image/png',
            'tmp_name' => $srcPath2,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($png),
        ]);

        $t->assertSame(true, $first['success'] ?? null);
        $t->assertSame(true, $second['success'] ?? null);
        $t->assertSame($first['hash'] ?? null, $second['hash'] ?? null);

        $count = (int) $ctx['pdo']->query('SELECT COUNT(*) FROM files')->fetchColumn();
        $t->assertSame(1, $count);

        @unlink($srcPath1);
        @unlink($srcPath2);
        ($ctx['cleanup'])();
    });
};
