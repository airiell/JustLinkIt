using System.Runtime.InteropServices;
using System.Windows;
using System.Windows.Interop;

namespace JustLinkIt.Client.Models;

// 「次の1件はクリップボードのみ」を予約するWin+Shift+Xのグローバルホットキー。
// GetAsyncKeyStateや低レベルキーボードフックはUIPIにより、管理者権限で動くウィンドウ
// （タスクマネージャー等）がフォアグラウンドの間は非昇格プロセスから入力状態を読めない。
// RegisterHotKeyはOSがフォアグラウンドの権限レベルに関係なく登録プロセスへ直接
// WM_HOTKEYを配送する仕組みのため、この制限を受けない。
public sealed class GlobalHotKeyService : IDisposable
{
    [DllImport("user32.dll", SetLastError = true)]
    private static extern bool RegisterHotKey(nint hWnd, int id, uint fsModifiers, uint vk);

    [DllImport("user32.dll", SetLastError = true)]
    private static extern bool UnregisterHotKey(nint hWnd, int id);

    private const int WM_HOTKEY = 0x0312;
    private const int HotKeyId = 0xB001;

    private const uint MOD_SHIFT = 0x0004;
    private const uint MOD_WIN = 0x0008;
    private const uint VK_X = 0x58;

    private readonly HwndSource _source;

    public event EventHandler? Pressed;

    public GlobalHotKeyService(Window window)
    {
        var handle = new WindowInteropHelper(window).EnsureHandle();
        _source = HwndSource.FromHwnd(handle)
            ?? throw new InvalidOperationException("ウィンドウハンドルからHwndSourceを取得できませんでした。");
        _source.AddHook(WndProc);

        if (!RegisterHotKey(handle, HotKeyId, MOD_WIN | MOD_SHIFT, VK_X))
        {
            // 他のアプリが同じ組み合わせを登録済みなどで失敗しても、この機能が使えなくなるだけで
            // アプリ自体は継続させる。
            Logger.Log("グローバルホットキー(Win+Shift+X)の登録に失敗しました。");
        }
    }

    private nint WndProc(nint hwnd, int msg, nint wParam, nint lParam, ref bool handled)
    {
        if (msg == WM_HOTKEY && wParam.ToInt32() == HotKeyId)
        {
            Pressed?.Invoke(this, EventArgs.Empty);
            handled = true;
        }

        return nint.Zero;
    }

    public void Dispose()
    {
        UnregisterHotKey(_source.Handle, HotKeyId);
        _source.RemoveHook(WndProc);
    }
}
