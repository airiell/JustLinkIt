<?php

declare(strict_types=1);

namespace JustLinkIt\Server\Tests;

require_once __DIR__ . '/TestRunner.php';

$runner = new TestRunner();

foreach (glob(__DIR__ . '/*Test.php') as $testFile) {
    $register = require $testFile;
    $register($runner);
}

exit($runner->run());
