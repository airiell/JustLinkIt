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
- [x] **追記（デザイン調整）**: `_workspace/gallery2026.php`（参考デザイン）に寄せてビジュアルを調整。ヘッダーのグラデーション＋文字間隔を広げた大文字タイトル、カードのホバー時浮き上がり＋影＋角丸拡大、カード下部の日付キャプション（グラデーションオーバーレイ）、モーダルをフルスクリーン半透明＋左右30%のホバー表示ナビゲーションゾーン＋背景クリックで閉じる、に変更。カスタムフォント「あんずもじ2020」はファイルがリポジトリに無いため代替のシステムフォントのまま。Playwrightでグリッド表示・モーダル・ナビゲーション・背景クリックでの終了を確認済み。
- [x] **追記2（フォント追加）**: `Server/public/gallery/fonts/AP.ttf`（あんずもじ2020）をユーザーが追加。`@font-face`で読み込み、bodyに適用。Playwrightで`document.fonts`のロード状態と実際の適用結果を確認済み。約5.4MBとやや大きいため`font-display: swap`でレンダリングブロックは回避しているが、初回表示の帯域次第では読み込みに時間がかかる点は留意。
- [x] **追記3（実機フィードバックによる細部修正）**:
  - 矢印記号（‹›）にあんずもじ2020フォントが適用され表示が崩れていたため、`.viewer-nav-arrow`にシステムフォントを明示指定。
  - モーダル背景クリックで閉じない不具合を修正。原因は(1)参考ソースに無い余計なラッパーdiv（`.viewer-media-wrap`）を追加していたこと、(2)クリック判定が`e.target === wrapper`という狭い一致条件で、日付テキスト等の兄弟要素上のクリックを拾えていなかったこと。ラッパーを削除し参考ソースのDOM構造に合わせた上で、判定を「クリック座標が画像/動画要素の実測`getBoundingClientRect()`の内側かどうか」という座標ベースの方式に変更（DOM要素の当たり判定の曖昧さを排除するため）。画像クリックでは閉じない仕様（参考ソースと同じ）は維持。
  - ナビゲーションゾーンの当たり判定が体感で大きすぎるとの指摘があり、幅を参考ソースの30%から15%に変更（協議の上のカスタマイズ、参考ソースからの意図的な逸脱）。
  - 連打でテキスト/画像が選択状態になる問題に対応し`user-select: none`を追加。`<dialog>`が`showModal()`でトップレイヤーに移動するとbodyからの継承が効かない（Chromeの既知の挙動）ことが実機検証で判明したため、`dialog`要素に直接指定する形に修正。ログインパスワード欄のみ`user-select: text`で除外。
  - 各カード左上に🔗ボタンを追加し、共有用URL（`item.url`）をワンクリックでクリップボードにコピーできるようにした。クリックはカード全体のビューアー起動と競合しないよう`stopPropagation()`で分離。
  - 上記いずれもPlaywrightで実機に近い条件（小さい画像、実際のクリップボード権限付与等）で再現・検証済み。
- [x] **追記4（ナビゲーションゾーンのクリック判定見直し／Phase 9作業中の追加修正）**: 幅15%のナビゲーションゾーン全体がクリックで画像送りに反応する挙動が「気持ち悪い」との指摘を受け、クリック対象を実際に見えている矢印ボタン（`.viewer-nav-arrow`）のみに限定。ボタンを`<div>`から実の`<button>`要素に変更し、`id`/`aria-label`もゾーン側からボタン側へ移動。ゾーン自体はホバーで矢印を表示するための当たり判定として残すが、クリックには反応しない（`cursor: pointer`もボタン側に移動）。ボタン以外の空欄をクリックした場合は既存の背景クリック閉じるロジックにフォールスルーする（除外リストを`.viewer-nav-zone`から`.viewer-nav-arrow`に変更）。Playwrightでボタンクリック時のみ画像送りが発生すること、ゾーン内の空欄クリックで閉じることを確認済み（PHPテスト34/34は影響なし）。

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
  - **追記（実機検証で発覚・Phase8後に修正）**: Snipping Toolは画像と動画（画面録画）を別フォルダに保存するため、`AppSettings.WatchFolderPathVideo`を追加し`MainViewModel`が2つの`FileWatcherService`（画像用・動画用）を並行稼働させる方式に変更。動画側フォルダ名はWindowsの表示言語でローカライズされる（日本語版では`Videos\画面録画`）ため既定値はあくまで目安、トレイメニューから変更可能にした。また、一時ファイル名で書き込んでから最終ファイル名にリネームする保存方式のツールでは`Created`イベントが発火せず`Renamed`のみ発火するため「たまにアップロードされない」不具合があった。`Renamed`イベントの購読、`InternalBufferSize`拡張、`Error`（バッファオーバーフロー）時の自動再起動を追加して解消。リネーム検知については再現テストで修正を確認済み。実機テストでは、これでも1件だけ検知漏れが発生（システム負荷が高い状況下と推測、原因は完全には特定できず）。アプリにログが一切なくデバッグ手段がなかったため、`MainViewModel.UploadFailed`イベント→`TrayIcon`の`ShowNotification`（H.NotifyIconのバルーン通知）で失敗を即時に可視化する仕組みを追加した。
  - **追記2（バルーン通知導入直後に発覚・修正）**: 「とても小さいスクショ」でサイズ上限エラーが出るという実機報告があり調査。(1) `Uploader::validate()`で「サイズ0（空/読み取り失敗）」と「サイズ超過」が同じ`413`+「ファイルサイズが上限を超えています。」というメッセージに束ねられており、実際にはサイズ0が原因なのに「超過」と誤表示するバグをテストケース込みで修正（サイズ0は`400`+専用メッセージに分離）。(2) 根本原因は`WaitUntilFileIsReadyAsync`が「排他オープンできること」だけを条件にしており、保存元アプリがファイルを作成した直後・内容書き込み前の一瞬だけロックが外れているタイミングで0バイトのまま「準備完了」と誤判定しうる欠陥だった。ファイルサイズが非ゼロかつ前回チェックから変化していないことも条件に追加し、0バイト作成→遅延書き込みのパターンを再現するテストで修正を確認済み。
  - **追記3（実機確認で発覚・協議の上修正）**: 「アップロード後にローカルファイルを削除」設定が、監視フォルダの自動検知だけでなく「送る」・ファイル選択でユーザーが明示的に選んだ既存ファイルにも無条件適用されており、意図しないデータロスの恐れがあった。`MainViewModel.UploadFileAsync()`に`allowDeleteAfterUpload`引数を追加し、監視フォルダ検知（`OnFileDetected`）からの呼び出しのみ`true`を渡す方式に変更。「送る」・ファイル選択・コマンドライン引数経由は削除設定に関わらず元ファイルを残す。クリップボードアップロードの一時ファイルは、設定に関わらず常にアップロード後に削除するよう分離した。
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
- [x] `Client/uninstall.bat` を作成し、SendToショートカットや設定ファイルを安全に削除する処理を実装する。（確認プロンプト付き。実行中プロセスの終了→SendToショートカット削除→settings.json削除の順。検証中、LF改行+日本語混在でcmd.exeが誤パースするバグを発見しCRLF化＋`.gitattributes`で`*.bat`をCRLF固定して解消）
- [x] C#アプリの実行ファイルを単一ファイル（Single File）としてビルドするためのプロジェクト設定を行う。（`Client/Properties/PublishProfiles/FolderProfile.pubxml`。Framework-dependent（ランタイム非同梱）+ Single File、`win-x64`。実際に`dotnet publish`し、単一exe(約976KB)が生成され正常起動することを確認済み）
- [x] 全体の動作確認テスト（監視、アップロード、表示、ギャラリーからの削除）を実施する。（publish済みexeを隔離された監視フォルダ・設定で実際に起動し、テスト画像投下→自動検知→アップロード→サーバーDB/実ファイル生成→ギャラリーAPIでの一覧表示→削除までEnd-to-Endで確認済み）

## Phase 9: ギャラリーのタグ機能（当初計画にない追加スコープ）
各ファイルにタグを付けられるようにする。自由記述＋タグテーブルの正規化構造を採用（タグマスターの事前定義はしない）。今回は付与・表示のみで、タグによる絞り込み検索は次回以降のスコープ。
- [x] DBスキーマ：`tags`（`id`, `name` UNIQUE）と中間テーブル`file_tags`（`file_id`, `tag_id`、`files`削除時にON DELETE CASCADEで連動削除）を追加。SQLiteの`PRAGMA foreign_keys = ON`を有効化。
- [x] `Server/src/Gallery.php`：`addTag()`/`removeTag()`を実装（タグ名はtrim・50文字上限、空文字は無視、同名タグは`tags`テーブルで使い回し重複させない）。`list()`の各アイテムに`tags`（アルファベット順の配列）を含めるよう拡張。
- [x] `Server/public/api/gallery.php`：`POST`メソッドに`action=add_tag`/`remove_tag`を追加（`{"action":"...","tag":"..."}`のJSONボディ、`?hash=`はクエリ）。
- [x] フロントエンド（編集UI）：当初はビューアーモーダル内にタグUIを実装したが、実装方針を合意しないまま進めてしまったとの指摘を受け、ユーザーとUIを再検討。最終的にグリッドのカード面に🔗コピーボタンと並べて🏷️タグボタンを追加し、クリックでカード直下にポップオーバー（タグチップ一覧＋削除ボタン＋追加フォーム）を表示する方式に変更（右クリックメニュー案は不採用）。タグ自体の常時表示はカード下部の日付キャプション部分にまとめて表示。ポップオーバーは外側クリックまたはEscapeで閉じる。
- [x] フロントエンド（ビューアー側の表示）：ここでも一度「モーダル側は完全に削除」で実装してしまい、再度指摘を受けて手戻り。ビューアーにも読み取り専用のタグ表示を残す方針で合意（編集はカードのポップオーバーのみ、モーダルでは編集UIを持たない＝二重実装を避ける）。表示位置は当初「画像の左上に少しはみ出す形」で`getBoundingClientRect()`ベースの実測位置合わせを実装したが、見た目が不自然との指摘を受けてさらに変更。最終的には日付（`viewer-date`）の下に通常のドキュメントフロー内で横並び表示する、JSでの位置計算を要さないシンプルな形に落ち着いた（`getBoundingClientRect()`ベースの実装・`resize`リスナーは撤去）。
- [x] `Server/tests/{DatabaseTest,GalleryTest}.php`にテストを追加（スキーマ、CASCADE削除、追加・重複防止・空文字無視・削除・存在しないhash）。Playwrightで（本物のconfig/DBは触らずテスト用に一時差し替えて）ポップオーバーの開閉・タグ追加/削除・カードキャプションへの反映・ビューアー側の読み取り専用タグ表示（日付直下・横並び）・背景クリックでの各種クローズ動作を確認済み（PHPテストは計34/34成功）。

## Phase 10: セキュリティ強化（ディレクトリトラバーサル対策）
セキュリティ監査に基づき、サーバーサイド（PHP）におけるディレクトリトラバーサルを未然に防ぐための多層防御（Defense in Depth）を実装します。
- [x] `Server/src/Config.php`: `uploadDirPath()` について、`upload_dir`（既定値`"u"`）に`../`や絶対パス指定が含まれる場合は`\RuntimeException`で拒否し、加えてディレクトリが既に存在する場合はシンボリックリンク経由の脱出も`realpath()`で検知する多層防御を追加した。**協議の上、`databasePath()`および`upload_dir_path`/`database_path`による明示的な絶対パス上書きは検証対象外とした**：これらは`ConfigTest.php`が最初から検証している「テスト等での隔離ディレクトリ指定に使う意図した機能」であり（例:`/tmp/custom-dir`）、ベースディレクトリ内への強制はこの設計と衝突するため。`databasePath()`の既定値も`upload_dir`のようなconfig由来の可変フラグメントから組み立てられておらず固定リテラルのため、そもそも検証対象となるトラバーサル経路が存在しない。
- [x] `Server/src/Uploader.php`: `persist()`のファイルパス構築部分に`basename($hash)`/`basename($extension)`を適用（多層防御。`$hash`/`$extension`は現状常にクラス内部でSHA-256計算・固定MIMEホワイトリストから決定されるため実害があるパスではないが、パス構築コード自体が入力元を信頼しない形にした）。
- [x] `Server/src/Gallery.php`: `delete()`のファイルパス構築部分に`basename($hash)`/`basename($row['extension'])`を適用（`$hash`はAPI層`gallery.php`で`^[a-f0-9]{64}$`の正規表現検証済みだが、同様に多層防御として追加）。
- [x] テストコードの拡充: `ConfigTest.php`に`upload_dir`へのトラバーサル文字列/絶対パス拒否と、`upload_dir_path`明示指定時は迂回されないことのテストを追加。`GalleryTest.php`にはDB内へ直接トラバーサル文字列入りhashを混入させ（API層の検証をバイパスした想定）、`delete()`が意図したディレクトリ外のファイルに触れないことを確認するテストを追加。`Uploader.php`側は`$hash`が常に内部計算値で外部から注入不可能なため、公開APIの契約を通した意味あるテストは書けず、コードレビューで確認可能な防御コードとして留めた。全39テスト成功（`php Server/tests/run.php`で確認）。

## Phase 11: 総合的なセキュリティ対策の強化（SQLi, XSS, アップロード, CSRF）
ディレクトリトラバーサルに加え、Webアプリケーションの主要な脆弱性に対する防御機構を点検・強化します。
- [x] **SQLインジェクション対策**: `Server/src/` 配下の全クエリを確認し、`Database.php` の固定DDL文字列（`CREATE TABLE`等、変数を含まない）を除き、全てPDOプリペアドステートメント＋バインドパラメータで実装済みであることを確認した。コード変更は不要。
- [x] **悪意のあるスクリプトの実行防止**: `Server/public/u/.htaccess` を追加し、当該ディレクトリでの`.php`等スクリプト実行をWebサーバーレベルで無効化（`mod_php`のengine off、`FilesMatch`での`Require all denied`、PHP-FPM等のハンドラ解除を併用した多層防御）。
- [x] **XSS対策**: `Server/public/.htaccess` に `Header always set` で `X-Content-Type-Options: nosniff`・`X-Frame-Options: DENY`・`Referrer-Policy`・`Content-Security-Policy`（`default-src 'self'`、外部CDN/インラインscript・styleを使わない構成のため厳格な設定で問題なし）を追加。DocumentRoot直下の一括設定のため全レスポンス（HTML/JSON/静的ファイル）に適用される。
- [x] **CSRF対策**: `Server/public/api/gallery.php` の POST/DELETE（状態変更あり）に対し、`X-Requested-With: XMLHttpRequest` ヘッダーが無ければ403で拒否する仕組みをセッション認証チェックの直後に追加。GET（一覧取得、状態変更なし）は対象外。`Server/public/gallery/app.js` 側の該当3箇所（タグ追加・タグ削除・ファイル削除）のfetch呼び出しにこのヘッダーを追加。
- 検証: `php Server/tests/run.php`（39/39成功、既存テストへの影響なし）に加え、`.htaccess`はPHP内蔵サーバーでは評価されないため、XAMPP同梱のApache 2.4を実データに触れない隔離コピー環境で一時起動し実HTTPリクエストで確認（Phase3の`.htaccess`検証時と同じ手法）：`u/`内に置いた`.php`ファイルへの直接アクセスが403になること、通常の画像ファイルは従来通り静的配信されること、認証済みセッションでもCSRFヘッダー無しのPOST/DELETEが403になること、ヘッダー付与時は正常にAPI処理まで到達すること、GET（一覧）はヘッダー無しでも（未認証なら401だが）CSRFでは弾かれないこと、全レスポンスに上記セキュリティヘッダーが付与されることを確認済み。

