<?php

declare(strict_types=1);

namespace JustLinkIt\Server;

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
$url = "{$scheme}://{$host}/{$config->uploadDir()}/{$result['hash']}";

echo json_encode(['success' => true, 'url' => $url]);
