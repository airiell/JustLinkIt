# JustLinkIt

*Read this in other languages: [English](README.md) | **日本語***

## これは令和版オレオレGyazoである

**Snipping Tool で撮ったスクリーンショット・画面録画を、保存した瞬間に自動アップロードしてURLをクリップボードにコピーする、Windows常駐型の画像・動画共有ツール。**

**「撮る → 気づいたら共有用URLがクリップボードにある」というシームレスな共有を実現しつつ、蓄積されたデータは世界中どこからでも閲覧・タグ検索できる自分専用のスクショアーカイブとして機能します。**

個人〜小規模チーム向けの軽量セルフホスト基盤として設計されており、バックエンドはPHP + SQLiteのみで動作。フレームワークやDBサーバーのセットアップは不要で、VPSや共有レンタルサーバーにそのまま置けます。

## 特徴

- **完全自動アップロード** — Snipping Toolの保存フォルダ（画像・動画それぞれ）を監視し、新しいファイルを検知したら自動でアップロード、URLをクリップボードへコピー。撮ってからDiscordやSlackに貼るまでの操作がゼロになります。
- **動画のOGP展開対応** — `.mp4`の画面録画はOGPタグ付きのビューアーHTMLとしてURLを発行するため、Discord/SlackなどにURLを貼るだけでGIFのようにループ再生プレビューが自動展開されます（画像は直リンクなのでそのままプレビュー展開されます）。
- **「送る」メニュー / クリップボード手動アップロード** — 監視フォルダ以外のファイルも、エクスプローラーの「送る」メニューやトレイメニューからワンクリックでアップロード可能。
- **SQLite + Vanilla JSの爆速ギャラリー** — アップロード履歴はSQLiteで管理し、フレームワーク不使用の素のHTML/CSS/JSでギャラリーUIを構築。無限スクロール・キーボード操作対応のライトボックス・タグによる絞り込み検索など、軽量なのに機能は本格派です。
- **鍵付きURL + パスワード保護ギャラリー** — アップロードファイル名はSHA-256ハッシュ（推測困難）。ギャラリーの閲覧・削除・タグ編集は共有パスワードでログインしたセッションのみ許可されます。アップロードAPI自体もオプションでAPIキー認証をかけられます。
- **安全志向のローカルファイル削除** — 「アップロード後に元ファイルを削除」はゴミ箱への移動として実装（完全削除ではない）。かつ、監視フォルダの自動検知経由のみに適用され、「送る」やファイル選択で明示的に指定したファイルは誤って消えません。

## 技術スタック

| レイヤー | 技術 |
| :--- | :--- |
| クライアント | C# / WPF (`net8.0-windows`)、[CommunityToolkit.Mvvm](https://github.com/CommunityToolkit/dotnet)、[H.NotifyIcon.Wpf](https://github.com/HavenDV/H.NotifyIcon)（タスクトレイ常駐） |
| サーバー | PHP 8.1+（素のPHP、composer/フレームワーク依存なし）、SQLite3（PDO経由） |
| ギャラリーフロントエンド | 素のHTML / CSS / JavaScript（React等のフレームワーク不使用） |

クライアント・サーバーともに外部依存を最小限に抑えており、composerやnpmのインストール作業は一切不要です。

## 動作環境・前提条件

### サーバー

- PHP **8.1以上**（`readonly`プロパティ・コンストラクタプロモーション等を使用）
- 有効化が必要なPHP拡張:
  - `pdo_sqlite`（SQLiteへの接続）
  - `fileinfo`（アップロードファイルの実MIMEタイプ判定。拡張子ではなく内容で検証しています）
  - `session`（ギャラリーのログインセッション。多くの環境で標準有効）
  - `json`（多くの環境で標準有効）
- Webサーバー: Apache（`mod_rewrite` と `mod_headers` が必須）
  - `mod_rewrite`: 動画URL（拡張子なし `/u/{hash}`）を`viewer.php`へルーティングするために使用
  - `mod_headers`: セキュリティヘッダー（CSP等）の付与に使用
  - Nginx等、`.htaccess`に対応しないWebサーバーを使う場合は、`Server/public/.htaccess` と `Server/public/u/.htaccess` の内容を手動で相当する設定に移植してください。
- `php.ini` の `upload_max_filesize` / `post_max_size` を、後述する `max_file_size` 設定値以上に設定すること

### クライアント

- Windows 10/11
- [.NET 8 デスクトップランタイム](https://dotnet.microsoft.com/download/dotnet/8.0)（クライアントはフレームワーク依存配布のため、実行対象マシンに別途インストールが必要です。自己完結型配布ではありません）

## インストールとセットアップ

### 1. サーバー側のセットアップ

1. リポジトリを任意のディレクトリへ配置します。
2. **WebサーバーのDocumentRootを `Server/public/` に設定してください。** `Server/` そのものをDocumentRootにすると、`src/`・`data/`（SQLite DB本体）・`config.php` がHTTP経由で直接見えてしまいます（`Server/public/`の外側には `.htaccess` によるアクセス拒否のフォールバックがありません）。

   ```apache
   <VirtualHost *:80>
       ServerName share.example.com
       DocumentRoot "/path/to/JustLinkIt/Server/public"

       <Directory "/path/to/JustLinkIt/Server/public">
           AllowOverride All
           Require all granted
       </Directory>
   </VirtualHost>
   ```

3. 設定ファイルを作成します。

   ```bash
   cp Server/config.example.php Server/config.php
   ```

4. `Server/config.php` を編集します。

   ```php
   <?php

   declare(strict_types=1);

   return [
       // アップロード実ファイルの保存先ディレクトリ名（Server/public/ 以下）
       'upload_dir' => 'u',

       // アップロード許可最大サイズ（バイト）。php.iniのupload_max_filesize/post_max_sizeも
       // これ以上の値にしておくこと。
       'max_file_size' => 30 * 1024 * 1024,

       // ギャラリー（一覧取得・削除・タグ編集）のログインパスワードのハッシュ値。
       // 下記コマンドで生成した値を設定する。空文字のままだと誰もログインできない
       // （未設定時に誰でも入れてしまう事故を防ぐための仕様）。
       'gallery_password_hash' => '$2y$10$xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',

       // アップロードAPI（/api/upload.php）を保護するAPIキー（任意）。
       // 空文字のままなら認証なしで誰でもアップロード可能（従来動作）。
       // 設定した場合、クライアント側のトレイメニューにも同じキーを入力する必要がある。
       'upload_api_key' => 'your-secret-api-key-here',
   ];
   ```

   ギャラリーのパスワードハッシュは、以下のコマンドで生成します（`好きなパスワード`部分を書き換えてください）。

   ```bash
   php -r "echo password_hash('好きなパスワード', PASSWORD_DEFAULT), \"\n\";"
   ```

5. SQLiteデータベースを初期化します（`files` / `tags` / `file_tags` テーブルを作成。再実行しても安全な冪等処理です）。

   ```bash
   php Server/init_db.php
   ```

6. パーミッションを設定します。Webサーバーの実行ユーザー（例: `www-data`）が以下のディレクトリに書き込みできる必要があります。

   ```bash
   chown -R www-data:www-data Server/data Server/public/u
   chmod -R 755 Server/data Server/public/u
   ```

7. HTTPS推奨。特にAPIキーやログインパスワードを平文送信しないよう、リバースプロキシ等でTLS終端を行ってください。

8. （任意）動作確認として、付属の自前テストランナーを実行できます（PHPUnit等の外部依存なし）。

   ```bash
   php Server/tests/run.php
   ```

セットアップが完了すると、以下のURLが有効になります。

| URL | 内容 |
| :--- | :--- |
| `https://yourdomain.com/api/upload.php` | アップロードAPI（クライアントの接続先） |
| `https://yourdomain.com/gallery/` | ギャラリーUI（ブラウザで直接アクセス） |
| `https://yourdomain.com/u/{hash}.{ext}` | 画像の直リンク |
| `https://yourdomain.com/u/{hash}` | 動画のOGPビューアー（拡張子なし） |

### 2. クライアント側のセットアップ

#### ビルド / 発行

```bash
# ビルドのみ（開発・デバッグ用）
dotnet build Client/JustLinkIt.Client.csproj

# 配布用ビルド（単一ファイル、フレームワーク依存）
dotnet publish Client/JustLinkIt.Client.csproj -p:PublishProfile=FolderProfile
```

発行後の成果物は `Client/bin/Release/net8.0-windows/win-x64/publish/JustLinkIt.Client.exe` に出力されます。このフォルダを対象マシンにそのままコピーして配布できます（対象マシンに.NET 8デスクトップランタイムが必要）。

#### 初回起動と設定

クライアントに設定ウィンドウはありません。**タスクトレイアイコンを右クリック**して出るメニューから、すべての設定を行います。

1. `JustLinkIt.Client.exe` を起動すると、タスクトレイに常駐します。
2. トレイアイコンを右クリック →「設定」→「アップロード先URLを設定...」から、サーバーのアップロードAPIのURLを入力します（例: `https://yourdomain.com/api/upload.php`）。
3. サーバー側で `upload_api_key` を設定した場合、同じく「設定」→「APIキーを設定...」から同じキーを入力します。
4. 必要に応じて監視フォルダを確認・変更します。
   - 「画像の監視フォルダを変更...」— 既定値はSnipping Toolのスクリーンショット保存先（`ピクチャ\Screenshots` 等）
   - 「動画の監視フォルダを変更...」— 既定値はSnipping Toolの画面録画保存先。**このフォルダ名はWindowsの表示言語によってローカライズされる**ため（日本語版では `ビデオ\画面録画` など）、既定値はあくまで目安です。初回起動後に実際の環境に合わせて変更してください。
5. その他のトグル設定もここから変更できます（後述）。

設定はメニュー操作と同時に、実行フォルダの `settings.json` へ即座に保存されます。

設定ファイルの例（`settings.json`、通常は手動編集不要）:

```json
{
  "ServerUploadUrl": "https://yourdomain.com/api/upload.php",
  "UploadApiKey": "your-secret-api-key-here",
  "OpenBrowserOnUpload": true,
  "DeleteLocalFileAfterUpload": false,
  "WatchFolderPath": "C:\\Users\\you\\Pictures\\Screenshots",
  "WatchFolderPathVideo": "C:\\Users\\you\\Videos\\Screen Recordings",
  "HasPromptedSendToRegistration": true,
  "RunOnStartup": false
}
```

## 使い方

### 常駐時の自動アップロード

クライアントを起動して常駐させておくだけで、以下が自動的に行われます。

1. 監視対象フォルダ（画像・動画それぞれ）に新しいファイルが作成される（Snipping Toolでスクリーンショット/画面録画を保存）
2. ファイルの書き込み完了を待ってから自動アップロード
3. アップロード成功後、公開URLをクリップボードへコピー
4. （設定がONの場合）既定のブラウザでURLを自動的に開く
5. （設定がONの場合）監視フォルダ経由のファイルのみ、アップロード後に元ファイルをゴミ箱へ移動

あとはDiscordやSlackなどで `Ctrl+V` するだけで共有できます。

### トレイメニューからの手動アップロード

タスクトレイアイコンを右クリックすると、以下が行えます。

- **ファイルを選択してアップロード** — ファイル選択ダイアログから任意の画像/動画をアップロード
- **クリップボードの画像をアップロード** — 現在クリップボードにコピーされている画像をそのままアップロード
- **ギャラリーを開く** — 設定中のサーバーURLを基に、既定のブラウザでギャラリーページを開く

### 「送る」メニューからの手動アップロード

初回起動時に「『送る』メニューに追加しますか？」という確認ダイアログが表示されます（承諾すると自動登録、拒否した場合は自動では再登録されません）。登録後は、エクスプローラーで画像/動画ファイルを右クリック →「送る」→「JustLinkIt」でアップロードできます。

いずれの手動アップロード経路（送る・ファイル選択）も、**「アップロード後にローカルファイルを削除」設定がONでも元ファイルは削除されません**（意図しないデータロスを防ぐため、この設定は監視フォルダの自動検知にのみ適用されます）。クリップボードアップロード時に生成される一時ファイルのみ、設定に関係なく常に削除されます。

### ギャラリー

`https://yourdomain.com/gallery/` にブラウザでアクセスすると、共有パスワードでのログイン後、アップロード済みファイルの一覧をカードグリッドで閲覧できます。

- 下スクロールで自動的に追加読み込み（無限スクロール）
- カードクリックで拡大表示（`<dialog>` ベースのライトボックス、キーボード左右キーで前後移動）
- カードの🔗ボタンでURLをワンクリックコピー
- カードの🏷️ボタンでタグの追加・削除、タグチップクリックで絞り込み検索
- 拡大表示内から削除・ダウンロードが可能

### アンインストール

`Client/uninstall.bat` を実行すると、確認プロンプトの後に以下を削除します。

- 「送る」メニューのショートカット
- スタートアップフォルダのショートカット（自動実行がONだった場合）
- `settings.json`

実行フォルダ自体は手動で削除してください。

## よくある質問・トラブルシューティング

### 動画のURLを開くと404エラーになる

動画（`.mp4`）のURLは拡張子なしの `/u/{hash}` という形式で、実ファイルではなく`viewer.php`が生成するOGPページへ`.htaccess`のルーティングで振り分けられています。404になる場合は、この振り分けが機能していない可能性が高いです。

- Apacheで `mod_rewrite` が有効になっているか確認してください（`a2enmod rewrite` など）。
- VirtualHost（または`<Directory>`）の設定で `AllowOverride All` になっているか確認してください。`AllowOverride None`のままだと`.htaccess`自体が読み込まれず、ルーティングが一切効きません（前述の[セットアップ手順](#1-サーバー側のセットアップ)のVirtualHost例を参照）。
- 設定変更後はApacheの再起動（`systemctl restart apache2` 等）を忘れずに行ってください。

### 大きな動画ファイルがアップロードできない

`config.php` の `max_file_size` を大きくしても失敗する場合、PHP側の制限に引っかかっている可能性があります。

- `php.ini` の `upload_max_filesize` と `post_max_size` を、`max_file_size` 以上の値に設定してください（両方とも引き上げる必要があります。どちらか一方だけでは不十分です）。
- 動画のような大きめのファイルは転送・保存に時間がかかることがあるため、`max_execution_time`（PHPスクリプトの実行時間制限）も合わせて余裕を持った値に増やしてください。
- 設定変更後はPHP（PHP-FPMやApacheのPHPモジュール）の再起動が必要です。

### アンインストール時にフォルダが削除できない

`uninstall.bat`実行後や手動削除時に「使用中のため削除できません」等のエラーが出る場合、`JustLinkIt.Client.exe` がまだバックグラウンドで常駐しており、ファイルがロックされていることが原因です。

- フォルダを削除する前に、タスクトレイアイコンを右クリックして「終了」を選び、アプリを確実に終了させてください。
- タスクトレイにアイコンが見当たらない場合は、タスクマネージャーで `JustLinkIt.Client.exe` プロセスが残っていないか確認し、残っていれば終了させてください。

## ライセンス

[MIT License](LICENSE)
