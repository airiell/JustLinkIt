using System.IO;

namespace JustLinkIt.Client.Models;

// トレイオンリー構成でUIにログ画面がないため、事後診断用に永続ログをファイルへ書き出す。
// FileWatcherServiceは画像/動画で2インスタンス並行稼働するため、書き込みは排他制御する。
public static class Logger
{
    private static readonly string LogFilePath = Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "JustLinkIt",
        "app.log");

    private static readonly object WriteLock = new();

    public static void Log(string message, Exception? ex = null)
    {
        var line = $"[{DateTime.Now:yyyy-MM-dd HH:mm:ss}] {message}";
        if (ex is not null)
        {
            line += $"{Environment.NewLine}{ex}";
        }

        try
        {
            lock (WriteLock)
            {
                var directory = Path.GetDirectoryName(LogFilePath);
                if (directory is not null && !Directory.Exists(directory))
                {
                    Directory.CreateDirectory(directory);
                }

                File.AppendAllText(LogFilePath, line + Environment.NewLine);
            }
        }
        catch (IOException)
        {
            // ログ書き込みの失敗でアプリ本体の動作を止めるべきではないため握りつぶす。
        }
        catch (UnauthorizedAccessException)
        {
            // 同上。
        }
    }
}
