using System.Windows;
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

    protected override async void OnStartup(StartupEventArgs e)
    {
        base.OnStartup(e);

        _mainViewModel = new MainViewModel();
        await _mainViewModel.InitializeAsync();

        _trayIcon = new TrayIcon
        {
            DataContext = _mainViewModel,
        };
        _trayIcon.ForceCreate();
    }

    protected override void OnExit(ExitEventArgs e)
    {
        _trayIcon?.Dispose();
        base.OnExit(e);
    }
}
