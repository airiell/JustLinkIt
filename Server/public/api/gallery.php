<?php

declare(strict_types=1);

namespace JustLinkIt\Server;

require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../src/Gallery.php';

header('Content-Type: application/json');

session_start();
if (empty($_SESSION['gallery_authenticated'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '認証が必要です。', 'code' => 401]);
    exit;
}

$config = Config::load();
$pdo = Database::initialize($config->databasePath());
$gallery = new Gallery($pdo, $config);

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$method = $_SERVER['REQUEST_METHOD'] ?? '';

// CSRF対策: 状態変更を伴うPOST/DELETEは、単純なクロスサイトの<form>送信では
// 付与できないカスタムヘッダーを要求する（クロスオリジンのfetch/XHRで付与しようとしても
// このオリジンはCORSを許可していないためプリフライトで弾かれる）。
// 状態変更のないGET（一覧取得）は対象外。
if (in_array($method, ['POST', 'DELETE'], true) && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '不正なリクエストです。', 'code' => 403]);
    exit;
}

if ($method === 'GET') {
    $limit = (int) ($_GET['limit'] ?? 30);
    $offset = (int) ($_GET['offset'] ?? 0);

    $result = $gallery->list($limit, $offset);
    $items = array_map(
        static function (array $item) use ($scheme, $host, $config): array {
            $base = "{$scheme}://{$host}/{$config->uploadDir()}/{$item['hash']}";
            // url: 共有用リンク（動画はOGPビューアーHTMLへのリンクで、動画バイナリではない）
            $item['url'] = $item['is_video'] ? $base : "{$base}.{$item['extension']}";
            // file_url: 実ファイルへの直リンク（<img>/<video>のsrcなど、実際に描画・再生する用途はこちら）
            $item['file_url'] = "{$base}.{$item['extension']}";

            return $item;
        },
        $result['items']
    );

    echo json_encode(['success' => true, 'items' => $items, 'has_more' => $result['has_more']]);
    exit;
}

if ($method === 'POST') {
    $hash = (string) ($_GET['hash'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '不正なハッシュ値です。', 'code' => 400]);
        exit;
    }

    $input = json_decode(file_get_contents('php://input') ?: '', true);
    $action = is_array($input) ? (string) ($input['action'] ?? '') : '';
    $tag = is_array($input) ? (string) ($input['tag'] ?? '') : '';

    $tags = match ($action) {
        'add_tag' => $gallery->addTag($hash, $tag),
        'remove_tag' => $gallery->removeTag($hash, $tag),
        default => false,
    };

    if ($tags === false) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '不正な操作です。', 'code' => 400]);
        exit;
    }

    if ($tags === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '見つかりません。', 'code' => 404]);
        exit;
    }

    echo json_encode(['success' => true, 'tags' => $tags]);
    exit;
}

if ($method === 'DELETE') {
    parse_str(file_get_contents('php://input') ?: '', $body);
    $hash = (string) ($_GET['hash'] ?? $body['hash'] ?? '');

    if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '不正なハッシュ値です。', 'code' => 400]);
        exit;
    }

    if (!$gallery->delete($hash)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '見つかりません。', 'code' => 404]);
        exit;
    }

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'サポートされていないメソッドです。', 'code' => 405]);
