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
        return (string) ($this->values['upload_dir_path'] ?? dirname(__DIR__) . '/public/' . $this->uploadDir());
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
