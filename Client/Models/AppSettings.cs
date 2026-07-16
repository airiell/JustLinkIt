using System.IO;
using System.Text.Json;

namespace JustLinkIt.Client.Models;

public class AppSettings
{
    private static readonly string SettingsFilePath = Path.Combine(AppContext.BaseDirectory, "settings.json");

    // アップロード先サーバーのエンドポイント（例: https://yourdomain.com/api/upload.php）。
    // 設計書(§3.1)には明記されていないが、クライアントが動作するために必須のため追加。
    public string ServerUploadUrl { get; set; } = string.Empty;

    // アップロード成功時、取得したURLを既定のブラウザで自動的に開くか（§3.1）。
    public bool OpenBrowserOnUpload { get; set; } = true;

    // アップロード完了後、PC内の元ファイルを削除するか（§3.1）。
    public bool DeleteLocalFileAfterUpload { get; set; }

    // Snipping Toolの保存先フォルダ（監視対象）。設計書に明記はないが、
    // ユーザー環境で変更されている可能性があるため設定可能にする。
    // 既定値はWindows 11のSnipping Tool自動保存の既定フォルダ。
    public string WatchFolderPath { get; set; } =
        Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.MyPictures), "Screenshots");

    public static async Task<AppSettings> LoadAsync()
    {
        if (!File.Exists(SettingsFilePath))
        {
            return new AppSettings();
        }

        try
        {
            await using var stream = File.OpenRead(SettingsFilePath);
            var settings = await JsonSerializer.DeserializeAsync<AppSettings>(stream);
            return settings ?? new AppSettings();
        }
        catch (JsonException)
        {
            // 設定ファイルが壊れている場合はアプリをクラッシュさせず既定値にフォールバックする。
            return new AppSettings();
        }
    }

    public async Task SaveAsync()
    {
        await using var stream = File.Create(SettingsFilePath);
        await JsonSerializer.SerializeAsync(stream, this, new JsonSerializerOptions { WriteIndented = true });
    }
}
