using System.IO;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace JustLinkIt.Client.Models;

public record UploadResult(bool Success, string? Url, string? Message, int? Code);

public class ApiClient
{
    private static readonly HttpClient HttpClient = new();

    public async Task<UploadResult> UploadAsync(string filePath, string endpointUrl, string apiKey = "")
    {
        try
        {
            await using var fileStream = File.OpenRead(filePath);
            using var content = new MultipartFormDataContent();
            using var fileContent = new StreamContent(fileStream);
            fileContent.Headers.ContentType = new MediaTypeHeaderValue(GetMimeType(filePath));
            content.Add(fileContent, "image", Path.GetFileName(filePath));

            using var request = new HttpRequestMessage(HttpMethod.Post, endpointUrl) { Content = content };
            if (!string.IsNullOrWhiteSpace(apiKey))
            {
                request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", apiKey);
            }

            using var response = await HttpClient.SendAsync(request);
            var body = await response.Content.ReadAsStringAsync();

            var parsed = JsonSerializer.Deserialize<UploadApiResponse>(body);
            if (parsed is null)
            {
                return new UploadResult(false, null, "サーバーからの応答を解析できませんでした。", null);
            }

            return new UploadResult(parsed.Success, parsed.Url, parsed.Message, parsed.Code);
        }
        catch (Exception ex) when (ex is HttpRequestException or IOException or TaskCanceledException or JsonException)
        {
            Logger.Log($"UploadAsync で例外が発生しました: {filePath}", ex);
            return new UploadResult(false, null, $"アップロードに失敗しました: {ex.Message}", null);
        }
    }

    private static string GetMimeType(string filePath) => Path.GetExtension(filePath).ToLowerInvariant() switch
    {
        ".png" => "image/png",
        ".jpg" or ".jpeg" => "image/jpeg",
        ".gif" => "image/gif",
        ".webp" => "image/webp",
        ".mp4" => "video/mp4",
        _ => "application/octet-stream",
    };

    private class UploadApiResponse
    {
        [JsonPropertyName("success")]
        public bool Success { get; set; }

        [JsonPropertyName("url")]
        public string? Url { get; set; }

        [JsonPropertyName("message")]
        public string? Message { get; set; }

        [JsonPropertyName("code")]
        public int? Code { get; set; }
    }
}
