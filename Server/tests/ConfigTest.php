<?php

declare(strict_types=1);

namespace JustLinkIt\Server\Tests;

use JustLinkIt\Server\Config;

require_once __DIR__ . '/../src/Config.php';

return function (TestRunner $runner): void {
    $runner->test('Config falls back to defaults when no values are given', function (TestRunner $t): void {
        $config = new Config();

        $t->assertSame('u', $config->uploadDir());
        $t->assertSame(30 * 1024 * 1024, $config->maxFileSize());
        $t->assertSame('', $config->galleryPasswordHash());
    });

    $runner->test('Config values override defaults', function (TestRunner $t): void {
        $config = new Config([
            'upload_dir' => 'custom',
            'max_file_size' => 1024,
            'database_path' => '/tmp/custom.sqlite3',
            'upload_dir_path' => '/tmp/custom-dir',
            'gallery_password_hash' => 'hashed-value',
        ]);

        $t->assertSame('custom', $config->uploadDir());
        $t->assertSame(1024, $config->maxFileSize());
        $t->assertSame('/tmp/custom.sqlite3', $config->databasePath());
        $t->assertSame('/tmp/custom-dir', $config->uploadDirPath());
        $t->assertSame('hashed-value', $config->galleryPasswordHash());
    });

    $runner->test('uploadDirPath() resolves the default upload_dir under Server/public', function (TestRunner $t): void {
        $config = new Config();
        $path = str_replace('\\', '/', $config->uploadDirPath());

        $t->assertTrue(str_ends_with($path, '/public/u'), "unexpected path: {$path}");
    });

    $runner->test('uploadDirPath() rejects a traversal sequence in upload_dir', function (TestRunner $t): void {
        $config = new Config(['upload_dir' => '../../etc']);

        $t->assertThrows(\RuntimeException::class, fn () => $config->uploadDirPath());
    });

    $runner->test('uploadDirPath() rejects an absolute upload_dir', function (TestRunner $t): void {
        $config = new Config(['upload_dir' => '/etc/passwd']);

        $t->assertThrows(\RuntimeException::class, fn () => $config->uploadDirPath());
    });

    $runner->test('uploadDirPath() still honors an explicit upload_dir_path override even when upload_dir looks unsafe', function (TestRunner $t): void {
        $config = new Config(['upload_dir' => '../unsafe', 'upload_dir_path' => '/tmp/explicit-override']);

        $t->assertSame('/tmp/explicit-override', $config->uploadDirPath());
    });
};
