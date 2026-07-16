using System.Diagnostics;
using System.IO;
using System.Runtime.InteropServices;

namespace JustLinkIt.Client.Models;

public static class StartupRegistrar
{
    public static bool IsRegistered() => File.Exists(GetShortcutPath());

    // SendToRegistrarと同様、WScript.Shell COMを遅延バインディングで呼び出し、
    // COM参照をcsprojに追加せずにスタートアップフォルダへ.lnkショートカットを作成する。
    public static void Register()
    {
        var exePath = Environment.ProcessPath ?? Process.GetCurrentProcess().MainModule?.FileName
            ?? throw new InvalidOperationException("実行ファイルのパスを取得できませんでした。");

        var shellType = Type.GetTypeFromProgID("WScript.Shell")
            ?? throw new InvalidOperationException("WScript.Shellを利用できません。");

        dynamic shell = Activator.CreateInstance(shellType)!;
        try
        {
            dynamic shortcut = shell.CreateShortcut(GetShortcutPath());
            try
            {
                shortcut.TargetPath = exePath;
                shortcut.WorkingDirectory = Path.GetDirectoryName(exePath);
                shortcut.Save();
            }
            finally
            {
                Marshal.ReleaseComObject(shortcut);
            }
        }
        finally
        {
            Marshal.ReleaseComObject(shell);
        }
    }

    public static void Unregister()
    {
        var path = GetShortcutPath();
        if (File.Exists(path))
        {
            File.Delete(path);
        }
    }

    private static string GetShortcutPath()
    {
        var startupDir = Environment.GetFolderPath(Environment.SpecialFolder.Startup);
        return Path.Combine(startupDir, "JustLinkIt.lnk");
    }
}
