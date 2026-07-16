<?php

declare(strict_types=1);

namespace JustLinkIt\Server;

use PDO;

final class Database
{
    public static function initialize(string $dbPath): PDO
    {
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS files (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                hash TEXT NOT NULL UNIQUE,
                extension TEXT NOT NULL,
                mime_type TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
            )'
        );

        return $pdo;
    }
}
