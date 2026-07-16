using System.ComponentModel;
using System.Diagnostics;
using System.IO;
using System.Runtime.InteropServices;
using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using JustLinkIt.Client.Models;

namespace JustLinkIt.Client.ViewModels;

public partial class MainViewModel : ObservableObject
{
    // Altキー押下時にアップロードをキャンセルする判定用（FileSystemWatcherのスレッドから
    // 呼ばれるため、WPFのKeyboard.Modifiersではなく物理キー状態を直接見るこちらを使う）。
    [System.Runtime.InteropServices.DllImport("user32.dll")]
    private static extern short GetAsyncKeyState(int vKey);
    private const int VK_MENU = 0x12; // Altキー

    private readonly ApiClient _apiClient = new();
    private readonly ClipboardManager _clipboardManager = new();
    // Snipping Toolは画像（スクリーンショット）と動画（画面録画）を別フォルダに保存するため、
    // フォルダごとに独立したウォッチャーで監視する。
    private readonly FileWatcherService _imageWatcher = new();
    private readonly FileWatcherService _videoWatcher = new();

    [ObservableProperty]
    private AppSettings _settings = new();

    [ObservableProperty]
    private string _statusMessage = string.Empty;

    // アップロード失敗をトレイのバルーン通知として即時に気づけるようにするためのイベント。
    // ViewModelはH.NotifyIcon等のView固有APIを知らないため、通知の実際の表示はView(TrayIcon)側で行う。
    public event EventHandler<string>? UploadFailed;

    public MainViewModel()
    {
        _imageWatcher.FileDetected += OnFileDetected;
        _videoWatcher.FileDetected += OnFileDetected;
    }

    public async Task InitializeAsync()
    {
        Settings = await AppSettings.LoadAsync();
        RestartWatching();
        Logger.Log("アプリケーションを初期化しました。");
    }

    public void RestartWatching()
    {
        _imageWatcher.Stop();
        _videoWatcher.Stop();

        if (!string.IsNullOrWhiteSpace(Settings.WatchFolderPath))
        {
            _imageWatcher.Start(Settings.WatchFolderPath);
            Logger.Log($"画像の監視を開始しました: {Settings.WatchFolderPath}");
        }

        if (!string.IsNullOrWhiteSpace(Settings.WatchFolderPathVideo))
        {
            _videoWatcher.Start(Settings.WatchFolderPathVideo);
            Logger.Log($"動画の監視を開始しました: {Settings.WatchFolderPathVideo}");
        }
    }

    // トレイメニューでの設定変更（トグル/監視フォルダ/サーバーURL）は
    // すべてこのメソッド経由で即時保存する。設定はウィンドウを持たないため
    // 明示的な「保存」操作がなく、変更＝保存とする。
    public async Task SaveSettingsAsync()
    {
        await Settings.SaveAsync();
        RestartWatching();
        ApplyStartupRegistration();
    }

    // トグルの度に無条件でCOM経由のショートカット作成/削除を行うと無駄なため、
    // 現在の登録状態と設定値が一致しない場合のみ反映する。
    private void ApplyStartupRegistration()
    {
        try
        {
            if (Settings.RunOnStartup && !StartupRegistrar.IsRegistered())
            {
                StartupRegistrar.Register();
                Logger.Log("スタートアップへの登録を行いました。");
            }
            else if (!Settings.RunOnStartup && StartupRegistrar.IsRegistered())
            {
                StartupRegistrar.Unregister();
                Logger.Log("スタートアップ登録を解除しました。");
            }
        }
        catch (Exception ex) when (ex is COMException or UnauthorizedAccessException or IOException)
        {
            Logger.Log("スタートアップ登録の更新に失敗しました。", ex);
            StatusMessage = "スタートアップ登録の更新に失敗しました。";
        }
    }

    // allowDeleteAfterUpload: Settings.DeleteLocalFileAfterUpload を実際に適用してよいか。
    // 監視フォルダでの自動検知（ユーザーが所有を意識しない一時的なスクショ）のみtrueにする。
    // 「送る」・ファイル選択でユーザーが明示的に選んだ既存ファイルは、設定がONでも
    // 勝手に削除しない（意図しないデータロスを避けるため）。
    public async Task UploadFileAsync(string filePath, bool allowDeleteAfterUpload = false)
    {
        if (string.IsNullOrWhiteSpace(Settings.ServerUploadUrl))
        {
            StatusMessage = "アップロード先サーバーが設定されていません。";
            UploadFailed?.Invoke(this, StatusMessage);
            return;
        }

        StatusMessage = $"アップロード中: {Path.GetFileName(filePath)}";

        var result = await _apiClient.UploadAsync(filePath, Settings.ServerUploadUrl, Settings.UploadApiKey);

        if (!result.Success || result.Url is null)
        {
            StatusMessage = $"アップロードに失敗しました: {result.Message}";
            Logger.Log($"アップロード失敗: {filePath} ({result.Message})");
            UploadFailed?.Invoke(this, $"{Path.GetFileName(filePath)} のアップロードに失敗しました。\n{result.Message}");
            return;
        }

        await _clipboardManager.CopyUrlToClipboardAsync(result.Url);
        StatusMessage = $"アップロード完了: {result.Url}";
        Logger.Log($"アップロード成功: {filePath} -> {result.Url}");

        if (Settings.OpenBrowserOnUpload)
        {
            OpenInBrowser(result.Url);
        }

        if (allowDeleteAfterUpload && Settings.DeleteLocalFileAfterUpload)
        {
            TryDeleteFile(filePath);
        }
    }

    [RelayCommand]
    private async Task UploadFromClipboardAsync()
    {
        var tempFilePath = await _clipboardManager.SaveClipboardImageToTempFileAsync();
        if (tempFilePath is null)
        {
            StatusMessage = "クリップボードに画像がありません。";
            UploadFailed?.Invoke(this, StatusMessage);
            return;
        }

        try
        {
            // アップロード専用に作った一時ファイルなので、設定に関わらず必ず後始末する。
            await UploadFileAsync(tempFilePath);
        }
        finally
        {
            TryDeleteFile(tempFilePath);
        }
    }

    [RelayCommand]
    private void OpenGallery()
    {
        if (!Uri.TryCreate(Settings.ServerUploadUrl, UriKind.Absolute, out var uploadUri))
        {
            StatusMessage = "アップロード先サーバーが設定されていません。";
            UploadFailed?.Invoke(this, StatusMessage);
            return;
        }

        OpenInBrowser($"{uploadUri.Scheme}://{uploadUri.Authority}/gallery/");
    }

    [RelayCommand]
    private void Exit()
    {
        _imageWatcher.Dispose();
        _videoWatcher.Dispose();
        System.Windows.Application.Current.Shutdown();
    }

    private async void OnFileDetected(object? sender, WatchedFileDetectedEventArgs e)
    {
        Logger.Log($"ファイルを検知しました: {e.FilePath} ({e.FileType})");

        if ((GetAsyncKeyState(VK_MENU) & 0x8000) != 0)
        {
            StatusMessage = "アップロードをキャンセルしました (Altキー)";
            Logger.Log($"アップロードをキャンセルしました (Altキー): {e.FilePath}");
            return;
        }

        await UploadFileAsync(e.FilePath, allowDeleteAfterUpload: true);
    }

    private static void OpenInBrowser(string url)
    {
        try
        {
            Process.Start(new ProcessStartInfo(url) { UseShellExecute = true });
        }
        catch (Exception ex) when (ex is Win32Exception or InvalidOperationException)
        {
            // ブラウザ起動に失敗してもアップロード自体は成功しているためアプリを継続させる。
        }
    }

    private static void TryDeleteFile(string filePath)
    {
        try
        {
            // 誤削除からの復旧余地を残すため、完全削除ではなくゴミ箱への移動にする。
            Microsoft.VisualBasic.FileIO.FileSystem.DeleteFile(
                filePath,
                Microsoft.VisualBasic.FileIO.UIOption.OnlyErrorDialogs,
                Microsoft.VisualBasic.FileIO.RecycleOption.SendToRecycleBin);
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
        {
            // 削除に失敗してもアップロード自体は成功しているため握りつぶす。
        }
    }
}
