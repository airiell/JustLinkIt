# JustLinkIt 実装計画書

本ドキュメントは、Sonnet（AIアシスタント）に実装を依頼するためのタスク一覧です。
一度に処理するコード量を最小限に抑え、依存関係の順序（バックエンド → フロントエンド → クライアント）に従って段階的に開発を進められるよう細分化されています。
各タスクが完了するごとにチェックボックス（`- [x]`）を埋めて進捗を管理してください。

## Phase 1: サーバーサイド基盤構築
サーバー側のディレクトリ構成とデータベースの準備を行います。
- [x] `Server/public/api`, `Server/public/u`, `Server/public/gallery`, `Server/src` ディレクトリを作成する。
- [x] SQLiteデータベース（`gallery.sqlite3`）を初期化するスクリプトを作成する。（`Server/init_db.php` + `Server/src/Database.php`）
- [x] データベース内に、画像情報を保存するテーブル（ファイル名ハッシュ、拡張子、作成日時等）を定義する。（`files` テーブル: `id` PK autoincrement, `hash` TEXT UNIQUE, `extension`, `mime_type`, `created_at`）

## Phase 2: サーバーサイド アップロードAPIの実装
画像・動画を受け取り、ハッシュ化して保存する処理を作ります。
- [x] `Server/src/Uploader.php` を作成し、ファイル受け取りとバリデーション（拡張子・MIMEチェック）処理を実装する。（`finfo`による実体MIME検証、拡張子はクライアント申告を信用せずMIMEから決定）
- [x] `Uploader.php` にて、SHA-256を用いたファイル名のハッシュ化処理を実装する。
- [x] `Uploader.php` にて、ファイルの保存処理とSQLiteへのレコード追加処理を実装する。（同一hashが既存の場合は保存・INSERTをスキップし重複防止）
- [x] `Server/public/api/upload.php` を作成し、POSTリクエストを受け付けてJSONレスポンス（成功/失敗、URL）を返すエンドポイントを実装する。

## Phase 2.5: 仕様と実装の乖離調査・修正
現在の実装（Phase 2まで）が、`architecture.md` で定義された本来の仕様からズレていないか検証し、乖離があれば修正します。
- [x] `docs/architecture.md` を読み込み、「画像の場合は実ファイルへの直リンクを返す」「動画の場合のみビューアー用URLを返す」という仕様を再確認する。
- [x] 現在の `Server/public/api/upload.php` および `Server/src/Uploader.php` のコードを確認し、APIのレスポンスURL生成ロジックが上記仕様と乖離していないか調査する。
- [x] 乖離が発見された場合、修正方法を提示する。（乖離あり：画像・動画とも常に拡張子なしビューアーURLを返していた。§3.2/§4の記述矛盾も併せて`docs/architecture.md`を修正した上で、`Uploader::handleUpload()`の戻り値に`extension`/`is_video`を追加し、`upload.php`でURL生成をファイル種別により分岐するよう修正・テスト追加・実HTTPリクエストで検証済み）

## Phase 3: サーバーサイド ビューアーとルーティング設定
OGP対応のHTMLを返し、SNS等で展開されるようにします。
※ Phase 2.5の仕様確定により、Viewer.phpが呼び出されるのは**動画（mp4）のみ**。画像は直リンク（`/u/{hash}.{ext}`）で完結し、Viewer.phpを経由しない。
- [x] `Server/src/Viewer.php` を作成し、ハッシュ値から対象の動画ファイルを特定する処理を実装する。（`Viewer::findByHash()`）
- [x] `Viewer.php` にて、OGPタグ（`og:video` 等、動画向け）を含むビューアーHTMLを動的に生成する処理を実装する。画像用のOGP分岐は不要（Viewer.phpは動画専用のため）。（`Viewer::renderHtml()`、XSS対策としてHTMLエスケープ済み）
- [x] `Server/public/viewer.php` を作成し、`.htaccess` からハッシュ値を受け取って `Viewer.php` を呼び出すHTTPエントリーポイントを実装する。（未知/不正なハッシュは404、画像ハッシュが誤って渡された場合は直リンクへ302リダイレクトする防御処理を含む）
- [x] `Server/public/.htaccess` を作成し、**実ファイルが存在しない**`/u/{hash}`（拡張子なし＝動画のみ）へのアクセスのみを `viewer.php` にルーティングする設定を記述する（`RewriteCond %{REQUEST_FILENAME} !-f` 相当の条件が必須）。拡張子付きの `/u/{hash}.{ext}`（画像の直リンク）は通常の静的ファイル配信のままとし、誤ってviewer.php行きにしないこと。DocumentRootは`Server/public/`とし、`src/`・`data/`・`tests/`・`config.php`はDocumentRoot外に置くことで直接アクセス不可にする。（実際にApache(mod_rewrite)を一時起動して全パターンを検証済み）

## Phase 4: Webギャラリー機能（サーバー・フロント統合）
アップロード済み画像を管理・閲覧する超高速なギャラリーを作ります。
- [x] ギャラリー機能全体に簡易パスワード認証を追加する（設計書に記載がなかったため協議の上決定。`Server/src/Auth.php`、`Server/public/api/login.php`、PHPセッションで一覧・削除APIを保護）。
- [x] ギャラリー用のAPI（一覧取得用）：SQLiteからページネーション（LIMIT/OFFSET等）付きで画像リストを返すPHP処理を作成する。各アイテムのURLは4章のレスポンス仕様と同じ規則（画像＝拡張子付き直リンク、動画＝拡張子なしビューアーリンク）で組み立てること。（`Server/src/Gallery.php` + `Server/public/api/gallery.php` GET）
- [x] ギャラリー用のAPI（削除用）：SQLiteのレコード物理削除および実ファイルを削除するPHP処理を作成する。（`Gallery::delete()` + `gallery.php` DELETE）
- [x] `Server/public/gallery/index.html` および `style.css` を作成し、ダークモード基調のCSS Gridカードレイアウトを構築する。ログイン用`<dialog>`も同時に実装。
- [x] ピュアVanilla JSで、APIからデータを取得しDOMにレンダリングする処理を実装する。（`Server/public/gallery/app.js`）画像/動画の実体表示には`gallery.php`が返す`file_url`（常に拡張子付き直リンク）を使用し、共有用の`url`（動画は拡張子なしビューアーリンク）とは区別すること。
- [x] JSで、IntersectionObserverを用いた「無限スクロール」による追加読み込み処理を実装する。
- [x] ネイティブ `<dialog>` タグを用いた画像クリック時の半透明モーダル表示処理を実装する。削除ボタンも同モーダルに実装。
- [x] モーダル表示中の、キーボード（左右キー）による画像遷移処理を実装する。（Escで閉じる処理も追加）

## Phase 5: クライアントサイド基盤（C# WPF）
Windows用常駐アプリのプロジェクト初期化を行います。
- [x] C# WPFプロジェクト（`.NET`）として `Client/JustLinkIt.Client` を作成する。（前フェーズの骨組み作成タスクで作成済み。`Client/JustLinkIt.Client.csproj`、`net10.0-windows`）
- [x] HTTP通信、JSON解析などに必要なNuGetパッケージをインストールする。（HttpClient/System.Text.Jsonは.NET標準機能のためNuGet追加は不要と判断。トレイアイコン用ライブラリの選定はPhase7に協議の上で保留）
- [x] `Models/AppSettings.cs` を作成し、「ブラウザ自動表示」「ローカルファイルの削除」などの設定情報を保持するクラスを定義する。（`ServerUploadUrl`は設計書§3.1に明記はないが、クライアントの動作上必須のため追加）
- [x] JSONファイルへの設定情報の保存と読み込みロジックを実装する。（`AppSettings.LoadAsync()`/`SaveAsync()`、保存先はexeと同じフォルダの`settings.json`（協議の上ポータブル方式に決定）。壊れたJSONは既定値にフォールバックしクラッシュしない）

## Phase 6: クライアントサイド コアロジックの実装
常駐アプリの心臓部となる監視と通信処理を作ります。
- [x] `Models/ApiClient.cs` を作成し、`multipart/form-data` によるサーバーへのファイルPOST処理とJSON解析を実装する。（実際にローカルPHPサーバーへ疎通させ、成功/バリデーションエラー/接続失敗の3パターンを確認済み）
- [x] `Models/FileWatcherService.cs` を作成し、Snipping Toolの保存先フォルダを監視するロジックを実装する。（既定監視先は`AppSettings.WatchFolderPath`として追加、既定値は`Pictures\Screenshots`。書き込み中ファイルの排他ロック待ちを実装）
- [x] ファイル新規作成検知時に、動画・画像ファイルを識別してイベントを発火する処理を実装する。（`FileDetected`イベント、対象外拡張子は無視することを実際のファイル生成で確認済み）
- [x] `Models/ClipboardManager.cs` を作成し、クリップボード内の画像データを取得する処理を実装する。（実際のWindowsクリップボードで画像あり/なしの両パターンを確認済み）
- [x] サーバーから返却されたURLをクリップボードにコピーする処理を実装する。（実クリップボードへのテキストコピーをラウンドトリップで確認済み）

## Phase 7: クライアントサイド UIと統合
ユーザーが操作するインターフェースと全体の流れを繋ぎます。
- [x] `ViewModels/MainViewModel.cs` を作成し、ファイル検知 → API送信 → クリップボードコピー → 設定に応じたブラウザ表示/ファイル削除 の一連のフローを実装する。（CommunityToolkit.Mvvm導入、`ObservableObject`/`RelayCommand`でボイラープレート削減）
- [x] `Views/TrayIcon.xaml` 等を作成し、タスクトレイへの常駐アイコンと右クリックメニューを実装する。（協議の上H.NotifyIcon.Wpfを採用。`TaskbarIcon`をルート要素とし、`App.xaml`は`ShutdownMode=OnExplicitShutdown`+`StartupUri`削除でウィンドウ非表示のまま常駐する構成に変更）
- [x] トレイメニューから「手動アップロード（ファイル選択 / クリップボード）」を実行するイベント処理を紐付ける。（ファイル選択は`OpenFileDialog`をコード behind から呼び出し、選択後の処理はViewModelのメソッドに委譲）
- [x] 設定項目を変更・保存するUIを実装する。（協議の上、専用の設定画面(`MainWindow.xaml`)は作らず、トレイメニューから直接変更する方式に変更。`MainWindow.xaml`は削除。ON/OFFはチェック可能なメニュー項目、監視フォルダは`OpenFolderDialog`、サーバーURLは`Microsoft.VisualBasic.Interaction.InputBox`。変更と同時に`MainViewModel.SaveSettingsAsync()`で即時保存）
- [x] 初回起動時に「送る(SendTo)」メニューへショートカットを追加するか確認するダイアログ処理を実装する。（`Models/SendToRegistrar.cs`、`WScript.Shell`のCOM遅延バインディングで.lnk作成。確認要否は`AppSettings.HasPromptedSendToRegistration`で一度きりに制御）
- [x] コマンドライン引数からファイルパスを受け取り、直接アップロードする機能（送るメニュー連携用）を実装する。（協議の上、単一インスタンス化＋名前付きパイプで実装。`Models/SingleInstanceManager.cs`。既に起動中なら新規プロセスはファイルパスを引き渡して即終了、未起動ならそのまま常駐開始しつつアップロードする）

## Phase 8: 仕上げ・デプロイ準備
配布・アンインストールを容易にするための最終調整です。
- [ ] `Client/uninstall.bat` を作成し、SendToショートカットや設定ファイルを安全に削除する処理を実装する。
- [ ] C#アプリの実行ファイルを単一ファイル（Single File）としてビルドするためのプロジェクト設定を行う。
- [ ] 全体の動作確認テスト（監視、アップロード、表示、ギャラリーからの削除）を実施する。
