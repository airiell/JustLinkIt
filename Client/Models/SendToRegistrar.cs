using System.Diagnostics;
using System.IO;
using System.Runtime.InteropServices;

namespace JustLinkIt.Client.Models;

public static class SendToRegistrar
{
    public static bool IsRegistered() => File.Exists(GetShortcutPath());

    // WScript.Shell COMを遅延バインディングで呼び出し、COM参照をcsprojに追加せずに
    // 「送る」フォルダへ.lnkショートカットを作成する。
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

    private static string GetShortcutPath()
    {
        var sendToDir = Environment.GetFolderPath(Environment.SpecialFolder.SendTo);
        return Path.Combine(sendToDir, "JustLinkIt.lnk");
    }
}
