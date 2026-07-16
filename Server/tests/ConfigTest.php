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
    });

    $runner->test('Config values override defaults', function (TestRunner $t): void {
        $config = new Config([
            'upload_dir' => 'custom',
            'max_file_size' => 1024,
            'database_path' => '/tmp/custom.sqlite3',
            'upload_dir_path' => '/tmp/custom-dir',
        ]);

        $t->assertSame('custom', $config->uploadDir());
        $t->assertSame(1024, $config->maxFileSize());
        $t->assertSame('/tmp/custom.sqlite3', $config->databasePath());
        $t->assertSame('/tmp/custom-dir', $config->uploadDirPath());
    });
};
