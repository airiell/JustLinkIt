using System.Drawing;
using System.Runtime.InteropServices;
using System.Windows;
using H.NotifyIcon;
using JustLinkIt.Client.ViewModels;
using Microsoft.Win32;

namespace JustLinkIt.Client.Views;

/// <summary>
/// Interaction logic for TrayIcon.xaml
/// </summary>
public partial class TrayIcon : TaskbarIcon
{
    // このアプリはMainWindowを持たないトレイオンリー構成のため、ダイアログのオーナーを
    // 明示的に渡さないとコンテキストメニューの一時的なポップアップがオーナーとして解決されてしまい、
    // ポップアップが閉じると同時にダイアログも道連れで閉じてしまう(App.xaml.cs参照)。
    private static Window OwnerWindow => ((App)Application.Current).HiddenOwnerWindow;

    public TrayIcon()
    {
        InitializeComponent();
        Icon = SystemIcons.Application;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct Win32Point
    {
        public int X;
        public int Y;
    }

    [DllImport("user32.dll")]
    private static extern bool GetCursorPos(out Win32Point point);

    // CenterOwnerではオーナー(HiddenOwnerWindow)が画面外の0x0ウィンドウのため画面中央に
    // フォールバックしてしまう。トレイメニュー経由で開くダイアログはメニュー操作時の
    // カーソル位置＝トレイアイコン付近に出したいため、代わりにこちらでカーソル基準に配置する。
    private static void PositionNearCursor(Window window)
    {
        GetCursorPos(out var cursor);

        var source = PresentationSource.FromVisual(OwnerWindow);
        var cursorPos = source?.CompositionTarget is { } target
            ? target.TransformFromDevice.Transform(new System.Windows.Point(cursor.X, cursor.Y))
            : new System.Windows.Point(cursor.X, cursor.Y);

        window.Loaded += (_, _) =>
        {
            var workArea = SystemParameters.WorkArea;
            window.Left = Math.Clamp(cursorPos.X, workArea.Left, Math.Max(workArea.Left, workArea.Right - window.ActualWidth));
            window.Top = Math.Clamp(cursorPos.Y, workArea.Top, Math.Max(workArea.Top, workArea.Bottom - window.ActualHeight));
        };
    }

    private async void UploadFile_Click(object sender, RoutedEventArgs e)
    {
        if (DataContext is not MainViewModel viewModel)
        {
            return;
        }

        var dialog = new OpenFileDialog
        {
            Filter = "画像/動画ファイル|*.png;*.jpg;*.jpeg;*.gif;*.webp;*.mp4",
        };

        if (dialog.ShowDialog(OwnerWindow) == true)
        {
            await viewModel.UploadFileAsync(dialog.FileName);
        }
    }

    private async void SettingToggled_Click(object sender, RoutedEventArgs e)
    {
        if (DataContext is MainViewModel viewModel)
        {
            await viewModel.SaveSettingsAsync();
        }
    }

    private async void ChangeWatchFolder_Click(object sender, RoutedEventArgs e)
    {
        if (DataContext is not MainViewModel viewModel)
        {
            return;
        }

        var dialog = new OpenFolderDialog
        {
            InitialDirectory = viewModel.Settings.WatchFolderPath,
        };

        if (dialog.ShowDialog(OwnerWindow) == true && dialog.FolderName is not null)
        {
            viewModel.Settings.WatchFolderPath = dialog.FolderName;
            await viewModel.SaveSettingsAsync();
        }
    }

    private async void ChangeWatchFolderVideo_Click(object sender, RoutedEventArgs e)
    {
        if (DataContext is not MainViewModel viewModel)
        {
            return;
        }

        var dialog = new OpenFolderDialog
        {
            InitialDirectory = viewModel.Settings.WatchFolderPathVideo,
        };

        if (dialog.ShowDialog(OwnerWindow) == true && dialog.FolderName is not null)
        {
            viewModel.Settings.WatchFolderPathVideo = dialog.FolderName;
            await viewModel.SaveSettingsAsync();
        }
    }

    private async void ChangeServerUrl_Click(object sender, RoutedEventArgs e)
    {
        if (DataContext is not MainViewModel viewModel)
        {
            return;
        }

        var dialog = new TextInputDialog(
            "JustLinkIt 設定",
            "アップロード先サーバーのURLを入力してください（例: https://yourdomain.com/api/upload.php）",
            viewModel.Settings.ServerUploadUrl)
        {
            Owner = OwnerWindow,
        };
        PositionNearCursor(dialog);

        if (dialog.ShowDialog() == true && !string.IsNullOrWhiteSpace(dialog.InputText))
        {
            viewModel.Settings.ServerUploadUrl = dialog.InputText;
            await viewModel.SaveSettingsAsync();
        }
    }

    private async void ChangeApiKey_Click(object sender, RoutedEventArgs e)
    {
        if (DataContext is not MainViewModel viewModel)
        {
            return;
        }

        var dialog = new TextInputDialog(
            "JustLinkIt 設定",
            "アップロードAPIキーを入力してください（サーバー側で未設定の場合は空欄のままで構いません）",
            viewModel.Settings.UploadApiKey)
        {
            Owner = OwnerWindow,
        };
        PositionNearCursor(dialog);

        if (dialog.ShowDialog() == true)
        {
            viewModel.Settings.UploadApiKey = dialog.InputText;
            await viewModel.SaveSettingsAsync();
        }
    }
}
