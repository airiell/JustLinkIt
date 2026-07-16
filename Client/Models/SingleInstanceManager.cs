using System.IO;
using System.IO.Pipes;

namespace JustLinkIt.Client.Models;

public sealed class SingleInstanceManager : IDisposable
{
    private const string MutexName = "JustLinkIt.SingleInstance";
    private const string PipeName = "JustLinkIt.Pipe";

    private Mutex? _mutex;
    private CancellationTokenSource? _listenCts;

    // trueが返るのは「自分がこのマシンで最初のインスタンスだった」場合のみ。
    public bool TryAcquire()
    {
        _mutex = new Mutex(initiallyOwned: true, MutexName, out var createdNew);
        return createdNew;
    }

    public void StartListening(Func<string, Task> onFilePathReceived)
    {
        _listenCts = new CancellationTokenSource();
        _ = ListenLoopAsync(onFilePathReceived, _listenCts.Token);
    }

    private static async Task ListenLoopAsync(Func<string, Task> onFilePathReceived, CancellationToken cancellationToken)
    {
        while (!cancellationToken.IsCancellationRequested)
        {
            try
            {
                await using var server = new NamedPipeServerStream(
                    PipeName, PipeDirection.In, 1, PipeTransmissionMode.Byte, PipeOptions.Asynchronous);
                await server.WaitForConnectionAsync(cancellationToken);

                using var reader = new StreamReader(server);
                var filePath = await reader.ReadLineAsync(cancellationToken);

                if (!string.IsNullOrWhiteSpace(filePath))
                {
                    await onFilePathReceived(filePath);
                }
            }
            catch (IOException)
            {
                // 接続中のクライアントが切断した等。次のループへ継続する。
            }
            catch (OperationCanceledException)
            {
                break;
            }
        }
    }

    // 既に起動中の別プロセス（先発インスタンス）へファイルパスを送る。
    public static async Task<bool> SendFilePathToRunningInstanceAsync(string filePath)
    {
        try
        {
            await using var client = new NamedPipeClientStream(".", PipeName, PipeDirection.Out);
            await client.ConnectAsync(2000);

            await using var writer = new StreamWriter(client) { AutoFlush = true };
            await writer.WriteLineAsync(filePath);

            return true;
        }
        catch (Exception ex) when (ex is IOException or TimeoutException)
        {
            return false;
        }
    }

    public void Dispose()
    {
        _listenCts?.Cancel();
        _listenCts?.Dispose();

        // ReleaseMutex()は取得時と同じOSスレッドからしか呼べず、async/await後の継続は
        // 別スレッドで再開されうるため、意図的に呼ばない。プロセス終了/Dispose時に
        // ハンドルが閉じられれば、OSがabandoned mutexとして自動的に解放する。
        _mutex?.Dispose();
    }
}
