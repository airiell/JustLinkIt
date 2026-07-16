<?php

declare(strict_types=1);

namespace JustLinkIt\Server\Tests;

use JustLinkIt\Server\Auth;

require_once __DIR__ . '/../src/Auth.php';

return function (TestRunner $runner): void {
    $runner->test('verifyPassword() accepts the correct password', function (TestRunner $t): void {
        $hash = password_hash('correct-horse-battery-staple', PASSWORD_DEFAULT);

        $t->assertSame(true, Auth::verifyPassword('correct-horse-battery-staple', $hash));
    });

    $runner->test('verifyPassword() rejects an incorrect password', function (TestRunner $t): void {
        $hash = password_hash('correct-horse-battery-staple', PASSWORD_DEFAULT);

        $t->assertSame(false, Auth::verifyPassword('wrong-password', $hash));
    });

    $runner->test('verifyPassword() rejects when no password hash is configured', function (TestRunner $t): void {
        $t->assertSame(false, Auth::verifyPassword('anything', ''));
    });

    $runner->test('verifyApiKey() accepts a matching Bearer token', function (TestRunner $t): void {
        $t->assertSame(true, Auth::verifyApiKey('Bearer secret-key', 'secret-key'));
    });

    $runner->test('verifyApiKey() rejects a non-matching Bearer token', function (TestRunner $t): void {
        $t->assertSame(false, Auth::verifyApiKey('Bearer wrong-key', 'secret-key'));
    });

    $runner->test('verifyApiKey() rejects a missing Authorization header', function (TestRunner $t): void {
        $t->assertSame(false, Auth::verifyApiKey('', 'secret-key'));
    });

    $runner->test('verifyApiKey() rejects a header without the Bearer scheme', function (TestRunner $t): void {
        $t->assertSame(false, Auth::verifyApiKey('secret-key', 'secret-key'));
    });

    $runner->test('verifyApiKey() skips verification when no API key is configured', function (TestRunner $t): void {
        $t->assertSame(true, Auth::verifyApiKey('', ''));
        $t->assertSame(true, Auth::verifyApiKey('anything', ''));
    });
};
