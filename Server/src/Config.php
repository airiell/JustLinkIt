<?php

declare(strict_types=1);

namespace JustLinkIt\Server;

final class Config
{
    private const DEFAULTS = [
        'upload_dir' => 'u',
        'max_file_size' => 30 * 1024 * 1024,
        'gallery_password_hash' => '',
    ];

    /** @var array<string, mixed> */
    private array $values;

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(array $values = [])
    {
        $this->values = $values + self::DEFAULTS;
    }

    public static function load(): self
    {
        $localPath = dirname(__DIR__) . '/config.php';
        $examplePath = dirname(__DIR__) . '/config.example.php';
        $path = is_file($localPath) ? $localPath : $examplePath;

        return new self(require $path);
    }

    public function uploadDir(): string
    {
        return (string) $this->values['upload_dir'];
    }

    public function uploadDirPath(): string
    {
        if (isset($this->values['upload_dir_path'])) {
            return (string) $this->values['upload_dir_path'];
        }

        return $this->resolveDefaultUploadDirPath();
    }

    // upload_dir_path による明示的な絶対パス上書きは意図した機能（テスト等での
    // 隔離ディレクトリ指定に使用）のため検証対象外とし、upload_dir から導出する
    // 既定パスのみを対象にディレクトリトラバーサル対策を行う。
    private function resolveDefaultUploadDirPath(): string
    {
        $base = dirname(__DIR__) . '/public';
        $uploadDir = $this->uploadDir();

        if ($uploadDir === '' || str_contains($uploadDir, '..') || preg_match('#^[/\\\\]|^[a-zA-Z]:#', $uploadDir) === 1) {
            throw new \RuntimeException("upload_dir に不正なパス指定が含まれています: {$uploadDir}");
        }

        $path = $base . '/' . $uploadDir;

        // 実ディレクトリが既に存在する場合、シンボリックリンク等でベースディレクトリの
        // 外を指していないかもrealpath()で追加検証する（多層防御）。
        $realBase = realpath($base);
        $realPath = realpath($path);
        if ($realBase !== false && $realPath !== false && !str_starts_with($realPath, $realBase)) {
            throw new \RuntimeException("upload_dir が意図したベースディレクトリの外を指しています: {$uploadDir}");
        }

        return $path;
    }

    public function maxFileSize(): int
    {
        return (int) $this->values['max_file_size'];
    }

    public function databasePath(): string
    {
        return (string) ($this->values['database_path'] ?? dirname(__DIR__) . '/data/gallery.sqlite3');
    }

    public function galleryPasswordHash(): string
    {
        return (string) $this->values['gallery_password_hash'];
    }
}
