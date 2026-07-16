<?php

declare(strict_types=1);

namespace JustLinkIt\Server;

use PDO;

final class Uploader
{
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
    ];

    private const VIDEO_EXTENSIONS = ['mp4'];

    public static function isVideoExtension(string $extension): bool
    {
        return in_array($extension, self::VIDEO_EXTENSIONS, true);
    }

    private readonly \Closure $uploadedFileChecker;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
        ?\Closure $uploadedFileChecker = null,
    ) {
        $this->uploadedFileChecker = $uploadedFileChecker
            ?? static fn (string $path): bool => is_uploaded_file($path);
    }

    /**
     * @param array{name?: string, type?: string, tmp_name?: string, error?: int, size?: int} $file
     * @return array{success: true, hash: string, extension: string, is_video: bool}|array{success: false, message: string, code: int}
     */
    public function handleUpload(array $file): array
    {
        $validationError = $this->validate($file);
        if ($validationError !== null) {
            return $validationError;
        }

        $tmpPath = (string) $file['tmp_name'];

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $tmpPath) ?: '';
        finfo_close($finfo);

        $extension = self::ALLOWED_MIME_TYPES[$mimeType] ?? null;
        if ($extension === null) {
            return $this->failure('許可されていないファイル形式です。', 415);
        }

        $hash = hash_file('sha256', $tmpPath);
        if ($hash === false) {
            return $this->failure('ファイルの処理に失敗しました。', 500);
        }

        if (!$this->exists($hash)) {
            $this->persist($tmpPath, $hash, $extension, $mimeType);
        }

        return [
            'success' => true,
            'hash' => $hash,
            'extension' => $extension,
            'is_video' => self::isVideoExtension($extension),
        ];
    }

    /**
     * @param array{name?: string, type?: string, tmp_name?: string, error?: int, size?: int} $file
     * @return array{success: false, message: string, code: int}|null
     */
    private function validate(array $file): ?array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return $this->failure('アップロードに失敗しました。', 400);
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !($this->uploadedFileChecker)($tmpPath)) {
            return $this->failure('不正なアップロードです。', 400);
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            return $this->failure('ファイルが空か、読み取りに失敗しました。', 400);
        }
        if ($size > $this->config->maxFileSize()) {
            return $this->failure('ファイルサイズが上限を超えています。', 413);
        }

        return null;
    }

    private function exists(string $hash): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM files WHERE hash = :hash');
        $stmt->execute(['hash' => $hash]);

        return (bool) $stmt->fetchColumn();
    }

    private function persist(string $tmpPath, string $hash, string $extension, string $mimeType): void
    {
        $dir = $this->config->uploadDirPath();
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // $hash/$extension は常にこのクラス内部で計算・固定リストから決定される値だが、
        // ファイルパス構築部分自体は入力元を信頼しない多層防御としてbasename()を適用する。
        $destination = $dir . '/' . basename($hash) . '.' . basename($extension);
        if (!move_uploaded_file($tmpPath, $destination)) {
            copy($tmpPath, $destination);
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO files (hash, extension, mime_type) VALUES (:hash, :extension, :mime_type)'
        );
        $stmt->execute(['hash' => $hash, 'extension' => $extension, 'mime_type' => $mimeType]);
    }

    /**
     * @return array{success: false, message: string, code: int}
     */
    private function failure(string $message, int $code): array
    {
        return ['success' => false, 'message' => $message, 'code' => $code];
    }
}
