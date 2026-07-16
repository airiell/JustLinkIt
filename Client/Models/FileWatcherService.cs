using System.IO;
using System.Linq;

namespace JustLinkIt.Client.Models;

public enum WatchedFileType
{
    Image,
    Video,
}

public class WatchedFileDetectedEventArgs : EventArgs
{
    public required string FilePath { get; init; }
    public required WatchedFileType FileType { get; init; }
}

public class FileWatcherService : IDisposable
{
    private static readonly string[] ImageExtensions = [".png", ".jpg", ".jpeg", ".gif", ".webp"];
    private static readonly string[] VideoExtensions = [".mp4"];

    private FileSystemWatcher? _watcher;

    public event EventHandler<WatchedFileDetectedEventArgs>? FileDetected;

    public void Start(string folderPath)
    {
        Stop();

        if (!Directory.Exists(folderPath))
        {
            Directory.CreateDirectory(folderPath);
        }

        _watcher = new FileSystemWatcher(folderPath)
        {
            NotifyFilter = NotifyFilters.FileName | NotifyFilters.LastWrite,
            EnableRaisingEvents = true,
        };
        _watcher.Created += OnCreated;
    }

    public void Stop()
    {
        if (_watcher is null)
        {
            return;
        }

        _watcher.Created -= OnCreated;
        _watcher.Dispose();
        _watcher = null;
    }

    private async void OnCreated(object sender, FileSystemEventArgs e)
    {
        try
        {
            var fileType = GetFileType(e.FullPath);
            if (fileType is null)
            {
                return;
            }

            if (!await WaitUntilFileIsReadyAsync(e.FullPath))
            {
                return;
            }

            FileDetected?.Invoke(this, new WatchedFileDetectedEventArgs
            {
                FilePath = e.FullPath,
                FileType = fileType.Value,
            });
        }
        catch (IOException)
        {
            // ファイルがロックされたまま、あるいは監視中に削除された等のケース。
            // 監視自体を止めるべきではないため、ここで握りつぶして次のイベントを待つ。
        }
    }

    private static WatchedFileType? GetFileType(string filePath)
    {
        var extension = Path.GetExtension(filePath).ToLowerInvariant();

        if (ImageExtensions.Contains(extension))
        {
            return WatchedFileType.Image;
        }

        if (VideoExtensions.Contains(extension))
        {
            return WatchedFileType.Video;
        }

        return null;
    }

    private static async Task<bool> WaitUntilFileIsReadyAsync(string filePath, int maxAttempts = 10, int delayMs = 200)
    {
        for (var attempt = 0; attempt < maxAttempts; attempt++)
        {
            try
            {
                await using var stream = File.Open(filePath, FileMode.Open, FileAccess.Read, FileShare.None);
                return true;
            }
            catch (IOException)
            {
                await Task.Delay(delayMs);
            }
        }

        return false;
    }

    public void Dispose()
    {
        Stop();
        GC.SuppressFinalize(this);
    }
}
