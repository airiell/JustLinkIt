<?php

declare(strict_types=1);

namespace JustLinkIt\Server;

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Uploader.php';
require_once __DIR__ . '/../src/Viewer.php';

$hash = (string) ($_GET['hash'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
    http_response_code(404);
    echo 'Not Found';
    exit;
}

$config = Config::load();
$pdo = Database::initialize($config->databasePath());
$viewer = new Viewer($pdo);

$file = $viewer->findByHash($hash);
if ($file === null) {
    http_response_code(404);
    echo 'Not Found';
    exit;
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$fileUrl = "{$scheme}://{$host}/{$config->uploadDir()}/{$file['hash']}.{$file['extension']}";

if (!Uploader::isVideoExtension($file['extension'])) {
    // 画像は本来.htaccessで拡張子付きの直リンクへ静的配信されるためここには到達しないはずだが、
    // 手動で拡張子なしURLを叩かれた場合の防御として直リンクへリダイレクトする。
    header("Location: {$fileUrl}", true, 302);
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
$ogImageUrl = "{$scheme}://{$host}/og-image.png";
echo $viewer->renderHtml($fileUrl, $file['mime_type'], $ogImageUrl);
