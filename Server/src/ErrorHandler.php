<?php

declare(strict_types=1);

namespace JustLinkIt\Server;

final class ErrorHandler
{
    public static function installJson(): void
    {
        set_exception_handler(static function (\Throwable $e): void {
            error_log((string) $e);
            http_response_code(500);
            header('Content-Type: application/json');
            echo self::renderJson();
        });
    }

    public static function installHtml(): void
    {
        set_exception_handler(static function (\Throwable $e): void {
            error_log((string) $e);
            http_response_code(500);
            echo self::renderHtml();
        });
    }

    public static function renderJson(): string
    {
        return (string) json_encode(['success' => false, 'message' => 'サーバー内部エラーが発生しました。', 'code' => 500]);
    }

    public static function renderHtml(): string
    {
        return 'Internal Server Error';
    }
}
