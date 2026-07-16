<?php

declare(strict_types=1);

namespace JustLinkIt\Server;

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../src/Uploader.php';

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POSTメソッドのみ許可されています。', 'code' => 405]);
    exit;
}

$config = Config::load();

// mod_php/php-fpm等の構成差により Authorization ヘッダーが $_SERVER に来ないことがあるため、
// getallheaders() へのフォールバックで確実に取得する。
$authorizationHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if ($authorizationHeader === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
        if (strcasecmp($name, 'Authorization') === 0) {
            $authorizationHeader = $value;
            break;
        }
    }
}

if (!Auth::verifyApiKey($authorizationHeader, $config->uploadApiKey())) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'APIキーが正しくありません。', 'code' => 401]);
    exit;
}

$pdo = Database::initialize($config->databasePath());
$uploader = new Uploader($pdo, $config);

$result = $uploader->handleUpload($_FILES['image'] ?? []);

if ($result['success'] !== true) {
    http_response_code($result['code']);
    echo json_encode($result);
    exit;
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base = "{$scheme}://{$host}/{$config->uploadDir()}/{$result['hash']}";

// 動画のみOGPビューアーURL（拡張子なし、Phase3のViewer.php行き）。
// 画像は実ファイルへの直リンク（拡張子付き、静的配信）を返す。
$url = $result['is_video'] ? $base : "{$base}.{$result['extension']}";

echo json_encode(['success' => true, 'url' => $url]);
