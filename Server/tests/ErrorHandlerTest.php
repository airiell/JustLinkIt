<?php

declare(strict_types=1);

namespace JustLinkIt\Server\Tests;

use JustLinkIt\Server\ErrorHandler;

require_once __DIR__ . '/../src/ErrorHandler.php';

return function (TestRunner $runner): void {
    $runner->test('renderJson() returns a generic failure message without leaking internals', function (TestRunner $t): void {
        $json = ErrorHandler::renderJson();
        $decoded = json_decode($json, true);

        $t->assertSame(false, $decoded['success']);
        $t->assertSame(500, $decoded['code']);
        $t->assertTrue(!str_contains($json, 'Exception'), 'response must not mention exception class names');
    });

    $runner->test('renderHtml() returns a plain generic message', function (TestRunner $t): void {
        $t->assertSame('Internal Server Error', ErrorHandler::renderHtml());
    });
};
