<?php

declare(strict_types=1);

namespace JustLinkIt\Server;

use PDO;

require_once __DIR__ . '/Uploader.php';

final class Gallery
{
    private const MAX_TAG_LENGTH = 50;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
    ) {
    }

    /**
     * @return array{
     *     items: array<int, array{hash: string, extension: string, mime_type: string, created_at: string, is_video: bool, tags: array<int, string>}>,
     *     has_more: bool
     * }
     */
    /**
     * @param array<int, string> $tagsFilter 指定された場合、これらすべてのタグを持つファイルのみ返す(AND検索)
     */
    public function list(int $limit, int $offset, array $tagsFilter = [], bool $untaggedOnly = false): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $tagsFilter = array_values(array_unique(array_filter(
            array_map('trim', $tagsFilter),
            static fn (string $tag): bool => $tag !== ''
        )));

        $sql = 'SELECT files.id, files.hash, files.extension, files.mime_type, files.created_at FROM files';
        $params = [];
        if ($tagsFilter !== []) {
            $placeholders = [];
            foreach ($tagsFilter as $i => $tag) {
                $placeholder = ":tag{$i}";
                $placeholders[] = $placeholder;
                $params[$placeholder] = $tag;
            }
            $sql .= ' WHERE files.id IN (
                SELECT ft.file_id FROM file_tags ft
                INNER JOIN tags t ON ft.tag_id = t.id
                WHERE t.name IN (' . implode(', ', $placeholders) . ')
                GROUP BY ft.file_id
                HAVING COUNT(DISTINCT t.name) = :tag_count
            )';
            $params[':tag_count'] = count($tagsFilter);
        } elseif ($untaggedOnly) {
            $sql .= ' WHERE files.id NOT IN (SELECT DISTINCT file_id FROM file_tags)';
        }
        $sql .= ' ORDER BY files.created_at DESC, files.id DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $placeholder => $value) {
            $stmt->bindValue($placeholder, $value, $placeholder === ':tag_count' ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        $items = array_map(
            fn (array $row): array => [
                'hash' => $row['hash'],
                'extension' => $row['extension'],
                'mime_type' => $row['mime_type'],
                'created_at' => $row['created_at'],
                'is_video' => Uploader::isVideoExtension($row['extension']),
                'tags' => $this->getTagsForFileId((int) $row['id']),
            ],
            $rows
        );

        return ['items' => $items, 'has_more' => $hasMore];
    }

    /**
     * @return array<int, string>
     */
    public function getAllTags(): array
    {
        return $this->pdo->query('SELECT name FROM tags ORDER BY name ASC')->fetchAll(PDO::FETCH_COLUMN);
    }

    public function delete(string $hash): bool
    {
        $stmt = $this->pdo->prepare('SELECT extension FROM files WHERE hash = :hash');
        $stmt->execute(['hash' => $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return false;
        }

        // $hash はAPI層（gallery.php）で64桁16進数に正規表現検証済みだが、
        // ファイルパス構築部分自体は入力元を信頼しない多層防御としてbasename()を適用する。
        $filePath = $this->config->uploadDirPath() . '/' . basename($hash) . '.' . basename($row['extension']);
        if (is_file($filePath)) {
            unlink($filePath);
        }

        $stmt = $this->pdo->prepare('DELETE FROM files WHERE hash = :hash');
        $stmt->execute(['hash' => $hash]);

        return true;
    }

    /**
     * @return array<int, string>|null null は該当ファイルが存在しない場合
     */
    public function addTag(string $hash, string $tagName): ?array
    {
        $fileId = $this->findFileId($hash);
        if ($fileId === null) {
            return null;
        }

        $tagName = trim($tagName);
        if ($tagName === '' || mb_strlen($tagName) > self::MAX_TAG_LENGTH) {
            return $this->getTagsForFileId($fileId);
        }

        $this->pdo->prepare('INSERT OR IGNORE INTO tags (name) VALUES (:name)')
            ->execute(['name' => $tagName]);

        $stmt = $this->pdo->prepare('SELECT id FROM tags WHERE name = :name');
        $stmt->execute(['name' => $tagName]);
        $tagId = (int) $stmt->fetchColumn();

        $this->pdo->prepare('INSERT OR IGNORE INTO file_tags (file_id, tag_id) VALUES (:file_id, :tag_id)')
            ->execute(['file_id' => $fileId, 'tag_id' => $tagId]);

        return $this->getTagsForFileId($fileId);
    }

    /**
     * @return array<int, string>|null null は該当ファイルが存在しない場合
     */
    public function removeTag(string $hash, string $tagName): ?array
    {
        $fileId = $this->findFileId($hash);
        if ($fileId === null) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'DELETE FROM file_tags WHERE file_id = :file_id AND tag_id IN (
                SELECT id FROM tags WHERE name = :name
            )'
        );
        $stmt->execute(['file_id' => $fileId, 'name' => trim($tagName)]);

        return $this->getTagsForFileId($fileId);
    }

    private function findFileId(string $hash): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM files WHERE hash = :hash');
        $stmt->execute(['hash' => $hash]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * @return array<int, string>
     */
    private function getTagsForFileId(int $fileId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.name FROM tags t
             INNER JOIN file_tags ft ON ft.tag_id = t.id
             WHERE ft.file_id = :file_id
             ORDER BY t.name ASC'
        );
        $stmt->execute(['file_id' => $fileId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
