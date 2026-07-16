<?php

declare(strict_types=1);

// 実際の設定は本ファイルをコピーして Server/config.php を作成し、そちらを編集する。
// Server/config.php は .gitignore 対象（環境ごとに値が変わるため）。

return [
    // アップロードされた実ファイルを保存するディレクトリ名（Server/public/ 以下）。
    // .htaccess の動画ルーティングはディレクトリ名を問わないパターンで判定しているため、
    // ここを変更しても .htaccess 側の修正は不要（ただし Server/public/u/.htaccess の
    // スクリプト実行禁止設定は、変更後のディレクトリへ手動で移すこと）。
    'upload_dir' => 'u',

    // アップロードを許可する最大サイズ（バイト）。
    // ※ php.ini の upload_max_filesize / post_max_size もこの値以上に設定すること。
    'max_file_size' => 30 * 1024 * 1024,

    // ギャラリー（一覧取得・削除API）へのアクセスに必要なパスワードのハッシュ値。
    // 平文パスワードは保存しない。以下のコマンドで生成した値を設定すること。
    //   php -r "echo password_hash('好きなパスワード', PASSWORD_DEFAULT), \"\n\";"
    // 空文字のままだとログインは常に失敗する（未設定時に誰でも入れてしまう事故を防ぐため）。
    'gallery_password_hash' => '',

    // アップロードAPI（/api/upload.php）を保護するAPIキー。
    // 空文字の場合は検証をスキップする（従来通り誰でもアップロード可能）。
    // クライアントは `Authorization: Bearer <このキー>` ヘッダーを送信する必要がある。
    'upload_api_key' => '',
];
