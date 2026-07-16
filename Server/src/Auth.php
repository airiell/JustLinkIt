<?php

declare(strict_types=1);

namespace JustLinkIt\Server;

final class Auth
{
    public static function verifyPassword(string $password, string $passwordHash): bool
    {
        return $passwordHash !== '' && password_verify($password, $passwordHash);
    }

    /**
     * @param string $authorizationHeader `Authorization` ヘッダーの生値（例: "Bearer xxx"）。
     * @param string $apiKey 設定されているAPIキー。空文字の場合は未設定＝検証スキップとみなす。
     */
    public static function verifyApiKey(string $authorizationHeader, string $apiKey): bool
    {
        if ($apiKey === '') {
            return true;
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', trim($authorizationHeader), $matches)) {
            return false;
        }

        return hash_equals($apiKey, $matches[1]);
    }
}
