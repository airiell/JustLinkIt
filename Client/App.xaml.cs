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
    private Window? _hiddenOwnerWindow;

    // 設定用ダイアログ(OpenFileDialog/OpenFolderDialog)の親として使う、画面には一切表示されない
    // ウィンドウ。このアプリはMainWindowを持たないトレイオンリー構成のため、親を明示的に渡さないと
    // ダイアログのオーナー解決がコンテキストメニューの一時的なポップアップに巻き込まれてしまい、
    // ポップアップが閉じると同時にダイアログも道連れで閉じてしまう(即座に消えるバグの原因だった)。
    public Window HiddenOwnerWindow => _hiddenOwnerWindow!;

    protected override async void OnStartup(StartupEventArgs e)
    {
        base.OnStartup(e);

        // Left/TopはWindowStyle.None+0x0サイズで画面には映らないが、実在するモニタの範囲外に
        // 置くと、WPFがこのウィンドウを暗黙のMainWindow（PlacementTarget省略時のPopupのDPI
        // 基準）として使うため、トレイの右クリックメニューがマルチモニタ環境で全く関係ない
        // モニタに表示される不具合の原因になっていた。プライマリモニタ原点に置いて回避する。
        _hiddenOwnerWindow = new Window
        {
            WindowStyle = WindowStyle.None,
            ShowInTaskbar = false,
            AllowsTransparency = true,
            Background = null,
            Width = 0,
            Height = 0,
            Left = 0,
            Top = 0,
            ShowActivated = false,
        };
        _hiddenOwnerWindow.Show();

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

        _mainViewModel.UploadFailed += (_, message) =>
            _trayIcon.ShowNotification("JustLinkIt", message, H.NotifyIcon.Core.NotificationIcon.Error);

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
        _hiddenOwnerWindow?.Close();
        base.OnExit(e);
    }
}
