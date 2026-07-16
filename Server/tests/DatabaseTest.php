<?php

declare(strict_types=1);

namespace JustLinkIt\Server\Tests;

use JustLinkIt\Server\Database;
use PDO;

require_once __DIR__ . '/../src/Database.php';

return function (TestRunner $runner): void {
    $runner->test('initialize() creates the sqlite file', function (TestRunner $t): void {
        $path = sys_get_temp_dir() . '/justlinkit_test_' . uniqid() . '.sqlite3';

        Database::initialize($path);

        $t->assertTrue(file_exists($path), 'Database file should be created');

        unlink($path);
    });

    $runner->test('initialize() creates the files table with expected columns', function (TestRunner $t): void {
        $path = sys_get_temp_dir() . '/justlinkit_test_' . uniqid() . '.sqlite3';

        $pdo = Database::initialize($path);
        $columns = $pdo->query('PRAGMA table_info(files)')->fetchAll(PDO::FETCH_COLUMN, 1);

        foreach (['id', 'hash', 'extension', 'mime_type', 'created_at'] as $expected) {
            $t->assertTrue(in_array($expected, $columns, true), "Column '{$expected}' should exist");
        }

        $pdo = null;
        unlink($path);
    });

    $runner->test('initialize() enforces uniqueness on hash', function (TestRunner $t): void {
        $path = sys_get_temp_dir() . '/justlinkit_test_' . uniqid() . '.sqlite3';

        $pdo = Database::initialize($path);
        $pdo->exec("INSERT INTO files (hash, extension, mime_type) VALUES ('abc', 'png', 'image/png')");

        $threw = false;
        try {
            $pdo->exec("INSERT INTO files (hash, extension, mime_type) VALUES ('abc', 'jpg', 'image/jpeg')");
        } catch (\PDOException) {
            $threw = true;
        }

        $t->assertTrue($threw, 'Duplicate hash should violate UNIQUE constraint');

        $pdo = null;
        unlink($path);
    });

    $runner->test('initialize() is idempotent when called twice on the same file', function (TestRunner $t): void {
        $path = sys_get_temp_dir() . '/justlinkit_test_' . uniqid() . '.sqlite3';

        Database::initialize($path);
        Database::initialize($path);

        $t->assertTrue(file_exists($path));

        unlink($path);
    });

    $runner->test('initialize() creates the tags and file_tags tables', function (TestRunner $t): void {
        $path = sys_get_temp_dir() . '/justlinkit_test_' . uniqid() . '.sqlite3';

        $pdo = Database::initialize($path);

        $tagColumns = $pdo->query('PRAGMA table_info(tags)')->fetchAll(PDO::FETCH_COLUMN, 1);
        foreach (['id', 'name'] as $expected) {
            $t->assertTrue(in_array($expected, $tagColumns, true), "tags.{$expected} should exist");
        }

        $fileTagColumns = $pdo->query('PRAGMA table_info(file_tags)')->fetchAll(PDO::FETCH_COLUMN, 1);
        foreach (['file_id', 'tag_id'] as $expected) {
            $t->assertTrue(in_array($expected, $fileTagColumns, true), "file_tags.{$expected} should exist");
        }

        $pdo = null;
        unlink($path);
    });

    $runner->test('deleting a file cascades to remove its file_tags links', function (TestRunner $t): void {
        $path = sys_get_temp_dir() . '/justlinkit_test_' . uniqid() . '.sqlite3';

        $pdo = Database::initialize($path);
        $pdo->exec("INSERT INTO files (hash, extension, mime_type) VALUES ('abc', 'png', 'image/png')");
        $fileId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO tags (name) VALUES ('foo')");
        $tagId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO file_tags (file_id, tag_id) VALUES ({$fileId}, {$tagId})");

        $pdo->exec("DELETE FROM files WHERE id = {$fileId}");
        $remaining = (int) $pdo->query('SELECT COUNT(*) FROM file_tags')->fetchColumn();

        $t->assertSame(0, $remaining, 'file_tags row should be removed via ON DELETE CASCADE');

        $pdo = null;
        unlink($path);
    });
};
