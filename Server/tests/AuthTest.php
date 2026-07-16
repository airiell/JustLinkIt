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
};
