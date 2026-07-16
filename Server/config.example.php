<?php

declare(strict_types=1);

// 実際の設定は本ファイルをコピーして Server/config.php を作成し、そちらを編集する。
// Server/config.php は .gitignore 対象（環境ごとに値が変わるため）。

return [
    // アップロードされた実ファイルを保存するディレクトリ名（Server/public/ 以下）。
    // 変更する場合、Phase3 で作成する .htaccess のルーティング設定も合わせて変更すること。
    'upload_dir' => 'u',

    // アップロードを許可する最大サイズ（バイト）。
    // ※ php.ini の upload_max_filesize / post_max_size もこの値以上に設定すること。
    'max_file_size' => 30 * 1024 * 1024,

    // ギャラリー（一覧取得・削除API）へのアクセスに必要なパスワードのハッシュ値。
    // 平文パスワードは保存しない。以下のコマンドで生成した値を設定すること。
    //   php -r "echo password_hash('好きなパスワード', PASSWORD_DEFAULT), \"\n\";"
    // 空文字のままだとログインは常に失敗する（未設定時に誰でも入れてしまう事故を防ぐため）。
    'gallery_password_hash' => '',
];
