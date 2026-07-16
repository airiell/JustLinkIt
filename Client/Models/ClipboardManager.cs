using System.IO;
using System.Windows;
using System.Windows.Media.Imaging;

namespace JustLinkIt.Client.Models;

public class ClipboardManager
{
    public async Task<string?> SaveClipboardImageToTempFileAsync()
    {
        var pngBytes = await Application.Current.Dispatcher.InvokeAsync(() =>
        {
            if (!Clipboard.ContainsImage())
            {
                return null;
            }

            var image = Clipboard.GetImage();
            if (image is null)
            {
                return null;
            }

            var encoder = new PngBitmapEncoder();
            encoder.Frames.Add(BitmapFrame.Create(image));

            using var memoryStream = new MemoryStream();
            encoder.Save(memoryStream);
            return memoryStream.ToArray();
        });

        if (pngBytes is null)
        {
            return null;
        }

        var tempFilePath = Path.Combine(Path.GetTempPath(), $"{Guid.NewGuid()}.png");
        await File.WriteAllBytesAsync(tempFilePath, pngBytes);

        return tempFilePath;
    }

    public async Task CopyUrlToClipboardAsync(string url)
    {
        await Application.Current.Dispatcher.InvokeAsync(() => Clipboard.SetText(url));
    }
}
