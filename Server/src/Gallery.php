<?php

declare(strict_types=1);

namespace JustLinkIt\Server;

use PDO;

require_once __DIR__ . '/Uploader.php';

final class Gallery
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
    ) {
    }

    /**
     * @return array{
     *     items: array<int, array{hash: string, extension: string, mime_type: string, created_at: string, is_video: bool}>,
     *     has_more: bool
     * }
     */
    public function list(int $limit, int $offset): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $stmt = $this->pdo->prepare(
            'SELECT hash, extension, mime_type, created_at FROM files ORDER BY id DESC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        $items = array_map(
            static fn (array $row): array => [
                'hash' => $row['hash'],
                'extension' => $row['extension'],
                'mime_type' => $row['mime_type'],
                'created_at' => $row['created_at'],
                'is_video' => Uploader::isVideoExtension($row['extension']),
            ],
            $rows
        );

        return ['items' => $items, 'has_more' => $hasMore];
    }

    public function delete(string $hash): bool
    {
        $stmt = $this->pdo->prepare('SELECT extension FROM files WHERE hash = :hash');
        $stmt->execute(['hash' => $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return false;
        }

        $filePath = $this->config->uploadDirPath() . '/' . $hash . '.' . $row['extension'];
        if (is_file($filePath)) {
            unlink($filePath);
        }

        $stmt = $this->pdo->prepare('DELETE FROM files WHERE hash = :hash');
        $stmt->execute(['hash' => $hash]);

        return true;
    }
}
