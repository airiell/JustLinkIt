<?php

declare(strict_types=1);

namespace JustLinkIt\Server;

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/ErrorHandler.php';

ErrorHandler::installJson();

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POSTメソッドのみ許可されています。', 'code' => 405]);
    exit;
}

$input = json_decode(file_get_contents('php://input') ?: '', true);
$password = is_array($input) ? (string) ($input['password'] ?? '') : '';

$config = Config::load();

if (!Auth::verifyPassword($password, $config->galleryPasswordHash())) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'パスワードが正しくありません。', 'code' => 401]);
    exit;
}

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
$_SESSION['gallery_authenticated'] = true;

echo json_encode(['success' => true]);
