using System.Drawing;
using System.Windows;
using H.NotifyIcon;
using JustLinkIt.Client.ViewModels;
using Microsoft.VisualBasic;
using Microsoft.Win32;

namespace JustLinkIt.Client.Views;

/// <summary>
/// Interaction logic for TrayIcon.xaml
/// </summary>
public partial class TrayIcon : TaskbarIcon
{
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

        if (dialog.ShowDialog() == true)
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

        if (dialog.ShowDialog() == true && dialog.FolderName is not null)
        {
            viewModel.Settings.WatchFolderPath = dialog.FolderName;
            await viewModel.SaveSettingsAsync();
        }
    }

    private async void ChangeServerUrl_Click(object sender, RoutedEventArgs e)
    {
        if (DataContext is not MainViewModel viewModel)
        {
            return;
        }

        var input = Interaction.InputBox(
            "アップロード先サーバーのURLを入力してください（例: https://yourdomain.com/api/upload.php）",
            "JustLinkIt 設定",
            viewModel.Settings.ServerUploadUrl);

        if (!string.IsNullOrWhiteSpace(input))
        {
            viewModel.Settings.ServerUploadUrl = input;
            await viewModel.SaveSettingsAsync();
        }
    }
}
