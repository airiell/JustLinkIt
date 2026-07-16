using System.ComponentModel;
using System.Diagnostics;
using System.IO;
using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using JustLinkIt.Client.Models;

namespace JustLinkIt.Client.ViewModels;

public partial class MainViewModel : ObservableObject
{
    private readonly ApiClient _apiClient = new();
    private readonly ClipboardManager _clipboardManager = new();
    private readonly FileWatcherService _fileWatcherService = new();

    [ObservableProperty]
    private AppSettings _settings = new();

    [ObservableProperty]
    private string _statusMessage = string.Empty;

    public MainViewModel()
    {
        _fileWatcherService.FileDetected += OnFileDetected;
    }

    public async Task InitializeAsync()
    {
        Settings = await AppSettings.LoadAsync();
        RestartWatching();
    }

    public void RestartWatching()
    {
        _fileWatcherService.Stop();

        if (!string.IsNullOrWhiteSpace(Settings.WatchFolderPath))
        {
            _fileWatcherService.Start(Settings.WatchFolderPath);
        }
    }

    // トレイメニューでの設定変更（トグル/監視フォルダ/サーバーURL）は
    // すべてこのメソッド経由で即時保存する。設定はウィンドウを持たないため
    // 明示的な「保存」操作がなく、変更＝保存とする。
    public async Task SaveSettingsAsync()
    {
        await Settings.SaveAsync();
        RestartWatching();
    }

    public async Task UploadFileAsync(string filePath)
    {
        if (string.IsNullOrWhiteSpace(Settings.ServerUploadUrl))
        {
            StatusMessage = "アップロード先サーバーが設定されていません。";
            return;
        }

        StatusMessage = $"アップロード中: {Path.GetFileName(filePath)}";

        var result = await _apiClient.UploadAsync(filePath, Settings.ServerUploadUrl);

        if (!result.Success || result.Url is null)
        {
            StatusMessage = $"アップロードに失敗しました: {result.Message}";
            return;
        }

        await _clipboardManager.CopyUrlToClipboardAsync(result.Url);
        StatusMessage = $"アップロード完了: {result.Url}";

        if (Settings.OpenBrowserOnUpload)
        {
            OpenInBrowser(result.Url);
        }

        if (Settings.DeleteLocalFileAfterUpload)
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
            return;
        }

        await UploadFileAsync(tempFilePath);
    }

    [RelayCommand]
    private void Exit()
    {
        _fileWatcherService.Dispose();
        System.Windows.Application.Current.Shutdown();
    }

    private async void OnFileDetected(object? sender, WatchedFileDetectedEventArgs e)
    {
        await UploadFileAsync(e.FilePath);
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
            File.Delete(filePath);
        }
        catch (IOException)
        {
            // 削除に失敗してもアップロード自体は成功しているため握りつぶす。
        }
    }
}
