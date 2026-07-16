using System.Runtime.InteropServices;
using System.Windows;
using JustLinkIt.Client.Models;
using JustLinkIt.Client.ViewModels;
using JustLinkIt.Client.Views;

namespace JustLinkIt.Client;

/// <summary>
/// Interaction logic for App.xaml
/// </summary>
public partial class App : Application
{
    private TrayIcon? _trayIcon;
    private MainViewModel? _mainViewModel;
    private SingleInstanceManager? _singleInstance;

    protected override async void OnStartup(StartupEventArgs e)
    {
        base.OnStartup(e);

        var filePathArg = e.Args.Length > 0 ? e.Args[0] : null;

        _singleInstance = new SingleInstanceManager();
        if (!_singleInstance.TryAcquire())
        {
            // 既に常駐インスタンスが起動中。「送る」経由ならファイルパスを引き渡して即終了する。
            if (filePathArg is not null)
            {
                await SingleInstanceManager.SendFilePathToRunningInstanceAsync(filePathArg);
            }

            Shutdown();
            return;
        }

        _mainViewModel = new MainViewModel();
        await _mainViewModel.InitializeAsync();

        _singleInstance.StartListening(filePath => _mainViewModel.UploadFileAsync(filePath));

        await PromptSendToRegistrationIfNeededAsync();

        _trayIcon = new TrayIcon
        {
            DataContext = _mainViewModel,
        };
        _trayIcon.ForceCreate();

        if (filePathArg is not null)
        {
            await _mainViewModel.UploadFileAsync(filePathArg);
        }
    }

    private async Task PromptSendToRegistrationIfNeededAsync()
    {
        if (_mainViewModel is null || _mainViewModel.Settings.HasPromptedSendToRegistration)
        {
            return;
        }

        var result = MessageBox.Show(
            "エクスプローラーの「送る」メニューにJustLinkItを追加しますか？\n" +
            "右クリック→送る→JustLinkIt で画像・動画を直接アップロードできるようになります。",
            "JustLinkIt",
            MessageBoxButton.YesNo,
            MessageBoxImage.Question);

        if (result == MessageBoxResult.Yes)
        {
            try
            {
                SendToRegistrar.Register();
            }
            catch (Exception ex) when (ex is COMException or InvalidOperationException)
            {
                MessageBox.Show(
                    $"「送る」メニューへの追加に失敗しました: {ex.Message}",
                    "JustLinkIt",
                    MessageBoxButton.OK,
                    MessageBoxImage.Warning);
            }
        }

        _mainViewModel.Settings.HasPromptedSendToRegistration = true;
        await _mainViewModel.Settings.SaveAsync();
    }

    protected override void OnExit(ExitEventArgs e)
    {
        _trayIcon?.Dispose();
        _singleInstance?.Dispose();
        base.OnExit(e);
    }
}
