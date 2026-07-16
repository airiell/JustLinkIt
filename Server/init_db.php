<?php

declare(strict_types=1);

namespace JustLinkIt\Server;

require_once __DIR__ . '/src/Database.php';

$dbPath = __DIR__ . '/data/gallery.sqlite3';

Database::initialize($dbPath);

echo "Initialized: {$dbPath}\n";
