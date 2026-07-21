<?php

declare(strict_types=1);

namespace JustLinkIt\Server;

use PDO;

final class Viewer
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{hash: string, extension: string, mime_type: string}|null
     */
    public function findByHash(string $hash): ?array
    {
        $stmt = $this->pdo->prepare('SELECT hash, extension, mime_type FROM files WHERE hash = :hash');
        $stmt->execute(['hash' => $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function renderHtml(string $videoUrl, string $mimeType, string $ogImageUrl): string
    {
        $escapedVideoUrl = htmlspecialchars($videoUrl, ENT_QUOTES, 'UTF-8');
        $escapedMimeType = htmlspecialchars($mimeType, ENT_QUOTES, 'UTF-8');
        $escapedOgImageUrl = htmlspecialchars($ogImageUrl, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>JustLinkIt</title>
<meta property="og:type" content="video.other">
<meta property="og:title" content="JustLinkIt">
<meta property="og:video" content="{$escapedVideoUrl}">
<meta property="og:video:secure_url" content="{$escapedVideoUrl}">
<meta property="og:video:type" content="{$escapedMimeType}">
<meta property="og:video:width" content="1280">
<meta property="og:video:height" content="720">
<meta property="og:image" content="{$escapedOgImageUrl}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="JustLinkIt">
<meta name="twitter:image" content="{$escapedOgImageUrl}">
</head>
<body>
<video src="{$escapedVideoUrl}" controls autoplay loop muted playsinline style="max-width:100%;"></video>
</body>
</html>
HTML;
    }
}
