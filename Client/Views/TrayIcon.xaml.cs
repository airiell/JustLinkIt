using System.Drawing;
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

        if (dialog.ShowDialog() == true)
        {
            viewModel.Settings.UploadApiKey = dialog.InputText;
            await viewModel.SaveSettingsAsync();
        }
    }
}
