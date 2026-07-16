<?php

declare(strict_types=1);

namespace JustLinkIt\Server;

require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/Database.php';

$dbPath = Config::load()->databasePath();

Database::initialize($dbPath);

echo "Initialized: {$dbPath}\n";
