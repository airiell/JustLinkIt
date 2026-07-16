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
            InternalBufferSize = 65536,
            EnableRaisingEvents = true,
        };
        _watcher.Created += OnFileEvent;
        // 多くのキャプチャツールは一時ファイル名で書き込み、完了後に最終ファイル名へ
        // リネームする。リネームはCreatedを発火させずRenamedのみが発火するため、
        // こちらも購読しないと「たまにアップロードされない」事象が起きる。
        _watcher.Renamed += OnFileEvent;
        _watcher.Error += OnError;
    }

    public void Stop()
    {
        if (_watcher is null)
        {
            return;
        }

        _watcher.Created -= OnFileEvent;
        _watcher.Renamed -= OnFileEvent;
        _watcher.Error -= OnError;
        _watcher.Dispose();
        _watcher = null;
    }

    private void OnError(object sender, ErrorEventArgs e)
    {
        // 短時間に大量のイベントが発生しバッファが溢れた場合など。
        // 監視自体を再開させることで復旧を試みる。
        if (_watcher is not null)
        {
            Start(_watcher.Path);
        }
    }

    private async void OnFileEvent(object sender, FileSystemEventArgs e)
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

    // 排他オープンできるだけでは「書き込み完了」の証明にならない（例: 保存元アプリが
    // ファイルを作成した直後、内容を書き込む前の一瞬だけロックが外れているケースがあり、
    // そのタイミングで0バイトのまま「準備完了」と判定してしまう不具合があった）。
    // そのため、サイズが非ゼロかつ前回チェック時から変化していない（＝書き込みが
    // 落ち着いた）ことも合わせて確認する。
    private static async Task<bool> WaitUntilFileIsReadyAsync(string filePath, int maxAttempts = 15, int delayMs = 200)
    {
        long previousSize = -1;

        for (var attempt = 0; attempt < maxAttempts; attempt++)
        {
            try
            {
                var currentSize = new FileInfo(filePath).Length;

                await using var stream = File.Open(filePath, FileMode.Open, FileAccess.Read, FileShare.None);

                if (currentSize > 0 && currentSize == previousSize)
                {
                    return true;
                }

                previousSize = currentSize;
            }
            catch (IOException)
            {
                // ロック中。次のリトライへ。
            }

            await Task.Delay(delayMs);
        }

        return false;
    }

    public void Dispose()
    {
        Stop();
        GC.SuppressFinalize(this);
    }
}
