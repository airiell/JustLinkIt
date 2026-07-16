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

    private void OpenSettings_Click(object sender, RoutedEventArgs e)
    {
        // 設定画面（MainWindow）の表示はPhase7の別タスクで実装予定。
    }
}
