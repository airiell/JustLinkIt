<?php

declare(strict_types=1);

namespace JustLinkIt\Server;

require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../src/ErrorHandler.php';
require_once __DIR__ . '/../../src/Gallery.php';

ErrorHandler::installJson();

// 一括操作（タグ付け・削除）で1リクエストに指定できるハッシュ数の上限。
const MAX_BATCH = 200;

header('Content-Type: application/json');

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

if ($method === 'GET' && ($_GET['action'] ?? '') === 'get_tags') {
    echo json_encode(['success' => true, 'tags' => $gallery->getAllTags()]);
    exit;
}

if ($method === 'GET') {
    $limit = (int) ($_GET['limit'] ?? 30);
    $offset = (int) ($_GET['offset'] ?? 0);
    $untagged = !empty($_GET['untagged']);
    $tagsFilter = $untagged ? [] : array_filter(array_map(
        'trim',
        explode(',', (string) ($_GET['tags'] ?? ''))
    ), static fn (string $tag): bool => $tag !== '');

    $result = $gallery->list($limit, $offset, $tagsFilter, $untagged);
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
    $input = json_decode(file_get_contents('php://input') ?: '', true);
    $action = is_array($input) ? (string) ($input['action'] ?? '') : '';

    // ボディに hashes 配列があれば一括モード。無ければ従来の単体 ?hash= パス。
    if (is_array($input) && isset($input['hashes']) && is_array($input['hashes'])) {
        $hashes = array_map(static fn ($h): string => is_string($h) ? $h : '', $input['hashes']);

        if (count($hashes) > MAX_BATCH) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '一度に操作できる件数の上限を超えています。', 'code' => 400]);
            exit;
        }

        foreach ($hashes as $h) {
            // 64桁は本アプリ自身が生成するSHA-256形式。それより短い桁数は、他システムから
            // 移行した既存データ(例: MD5ベースの32桁ハッシュ)を許容するためのもの。
            if (!preg_match('/^[a-f0-9]{8,64}$/', $h)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => '不正なハッシュ値です。', 'code' => 400]);
                exit;
            }
        }

        if ($hashes === []) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '対象が指定されていません。', 'code' => 400]);
            exit;
        }

        if ($action === 'delete') {
            $result = $gallery->deleteMany($hashes);
            echo json_encode([
                'success' => true,
                'deleted' => $result['deleted'],
                'not_found' => $result['not_found'],
            ]);
            exit;
        }

        $tag = (string) ($input['tag'] ?? '');
        $result = match ($action) {
            'add_tag' => $gallery->addTagToMany($hashes, $tag),
            'remove_tag' => $gallery->removeTagFromMany($hashes, $tag),
            default => null,
        };

        if ($result === null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '不正な操作です。', 'code' => 400]);
            exit;
        }

        echo json_encode([
            'success' => true,
            'action' => $action,
            'tag' => $tag,
            'items' => $result['items'],
            'not_found' => $result['not_found'],
        ]);
        exit;
    }

    $hash = (string) ($_GET['hash'] ?? '');
    // 64桁は本アプリ自身が生成するSHA-256形式。それより短い桁数は、他システムから
    // 移行した既存データ(例: MD5ベースの32桁ハッシュ)を許容するためのもの。
    if (!preg_match('/^[a-f0-9]{8,64}$/', $hash)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '不正なハッシュ値です。', 'code' => 400]);
        exit;
    }

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

    // 64桁は本アプリ自身が生成するSHA-256形式。それより短い桁数は、他システムから
    // 移行した既存データ(例: MD5ベースの32桁ハッシュ)を許容するためのもの。
    if (!preg_match('/^[a-f0-9]{8,64}$/', $hash)) {
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
