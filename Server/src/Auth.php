<?php

declare(strict_types=1);

namespace JustLinkIt\Server;

final class Auth
{
    public static function verifyPassword(string $password, string $passwordHash): bool
    {
        return $passwordHash !== '' && password_verify($password, $passwordHash);
    }
}
