# JustLinkIt

*Read this in other languages: **English** | [日本語](README_ja.md)*

## Your Personal Screenshot Archive, Accessible from Anywhere

**A self-hosted media platform that automatically uploads your Snipping Tool screenshots and screen recordings the moment you save them, organizing them into a beautiful, searchable web gallery.**

While it offers a seamless "Capture → URL in clipboard" workflow just like Gyazo or Lightshot, JustLinkIt's true strength lies in its archiving capabilities. 
It serves as your private cloud archive for all your screen captures. With the built-in fast, tag-searchable gallery UI backed by SQLite, you can access, browse, and manage your entire capture history from anywhere in the world. 

Lightweight and easy to deploy: the backend runs solely on PHP and SQLite. No complex frameworks or database server setups are required. Just drop it onto any VPS or shared hosting, and your ultimate personal archive is ready to go.

## Features

- **Fully Automated Uploads** — Monitors Snipping Tool's save folders (for both images and videos). When a new file is detected, it automatically uploads it and copies the URL to your clipboard. Zero extra steps from capture to pasting in Discord or Slack.
- **OGP Preview for Videos** — Screen recordings (`.mp4`) are served as viewer HTML pages with OGP tags. Just paste the URL into Discord/Slack, and it will automatically expand into a looping video preview, just like a GIF (Images are direct links and expand natively).
- **"Send To" Menu / Manual Clipboard Uploads** — For files outside the monitored folders, you can upload them with a single click via the Explorer "Send To" menu or the system tray menu.
- **Blazing Fast SQLite + Vanilla JS Gallery** — Upload history is managed via SQLite, with a gallery UI built purely on vanilla HTML/CSS/JS without heavy frameworks. Despite being lightweight, it features infinite scrolling, keyboard-navigable lightboxes, and tag-based filtering.
- **Private URLs & Password-Protected Gallery** — Uploaded filenames are SHA-256 hashed (making them hard to guess). Viewing, deleting, and tag editing in the gallery are restricted to sessions authenticated with a shared password. The upload API itself can also be optionally protected by an API key.
- **Safe Local File Deletion** — The "delete local file after upload" feature moves files to the Recycle Bin rather than permanently deleting them. Furthermore, it only applies to files detected automatically in the watch folders; files explicitly chosen via the "Send To" menu or file picker will not be accidentally deleted.

## Tech Stack

| Layer | Technology |
| :--- | :--- |
| Client | C# / WPF (`net8.0-windows`), [CommunityToolkit.Mvvm](https://github.com/CommunityToolkit/dotnet), [H.NotifyIcon.Wpf](https://github.com/HavenDV/H.NotifyIcon) (System tray resident) |
| Server | PHP 8.1+ (Vanilla PHP, no composer/framework dependencies), SQLite3 (via PDO) |
| Gallery Frontend | Vanilla HTML / CSS / JavaScript (No frameworks like React) |

Both client and server keep external dependencies to an absolute minimum. No `composer` or `npm install` is required.

## Requirements

### Server

- PHP **8.1 or higher** (Uses `readonly` properties, constructor property promotion, etc.)
- Required PHP extensions:
  - `pdo_sqlite` (For SQLite connection)
  - `fileinfo` (For authentic MIME type validation, checking file contents rather than extensions)
  - `session` (For gallery login sessions. Usually enabled by default)
  - `json` (Usually enabled by default)
- Web Server: Apache (Requires `mod_rewrite` and `mod_headers`)
  - `mod_rewrite`: Routes video URLs (extensionless `/u/{hash}`) to `viewer.php`
  - `mod_headers`: Appends security headers (e.g., CSP)
  - If using a server like Nginx that doesn't support `.htaccess`, you will need to manually port the rules from `Server/public/.htaccess` and `Server/public/u/.htaccess`.
- Ensure that `upload_max_filesize` and `post_max_size` in your `php.ini` are equal to or greater than the `max_file_size` setting discussed below.

### Client

- Windows 10/11
- [.NET 8 Desktop Runtime](https://dotnet.microsoft.com/download/dotnet/8.0) (The client is distributed as framework-dependent, so the target machine must have this installed. It is not self-contained.)

## Installation & Setup

### 1. Server Setup

1. Place the repository in a directory of your choice.
2. **Set your web server's DocumentRoot to `Server/public/`.** Do NOT set `Server/` as the DocumentRoot, otherwise your `src/`, `data/` (the SQLite DB itself), and `config.php` will be exposed directly via HTTP (there is no fallback `.htaccess` denial outside of `Server/public/`).

   ```apache
   <VirtualHost *:80>
       ServerName share.example.com
       DocumentRoot "/path/to/JustLinkIt/Server/public"

       <Directory "/path/to/JustLinkIt/Server/public">
           AllowOverride All
           Require all granted
       </Directory>
   </VirtualHost>
   ```

3. Create the configuration file.

   ```bash
   cp Server/config.example.php Server/config.php
   ```

4. Edit `Server/config.php`.

   ```php
   <?php

   declare(strict_types=1);

   return [
       // Directory name for storing uploaded files (inside Server/public/)
       'upload_dir' => 'u',

       // Maximum allowed upload size in bytes. Ensure upload_max_filesize/post_max_size
       // in your php.ini are at least this value.
       'max_file_size' => 30 * 1024 * 1024,

       // Hash of the login password for the gallery (listing, deleting, editing tags).
       // Generate this value using the command below. If left empty, no one can log in
       // (a safety measure to prevent unauthorized access when unconfigured).
       'gallery_password_hash' => '$2y$10$xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',

       // Optional API key to protect the upload API (/api/upload.php).
       // If left empty, anyone can upload without authentication.
       // If set, you must enter the exact same key in the client's tray menu settings.
       'upload_api_key' => 'your-secret-api-key-here',
   ];
   ```

   Generate your gallery password hash with the following command (replace `your_password`):

   ```bash
   php -r "echo password_hash('your_password', PASSWORD_DEFAULT), \"\n\";"
   ```

5. Initialize the SQLite database (Creates `files`, `tags`, and `file_tags` tables. This script is idempotent and safe to run multiple times).

   ```bash
   php Server/init_db.php
   ```

6. Set permissions. The web server user (e.g., `www-data`) must have write access to the following directories:

   ```bash
   chown -R www-data:www-data Server/data Server/public/u
   chmod -R 755 Server/data Server/public/u
   ```

7. HTTPS is highly recommended. Please terminate TLS via a reverse proxy to avoid transmitting API keys and login passwords in plaintext.

8. (Optional) You can run the built-in test runner to verify functionality (No external dependencies like PHPUnit required).

   ```bash
   php Server/tests/run.php
   ```

Once setup is complete, the following URLs will be available:

| URL | Description |
| :--- | :--- |
| `https://yourdomain.com/api/upload.php` | Upload API (Client connection target) |
| `https://yourdomain.com/gallery/` | Gallery UI (Access directly via browser) |
| `https://yourdomain.com/u/{hash}.{ext}` | Direct link to image |
| `https://yourdomain.com/u/{hash}` | OGP video viewer (Extensionless) |

### 2. Client Setup

#### Build / Publish

```bash
# Build only (for development/debugging)
dotnet build Client/JustLinkIt.Client.csproj

# Publish for distribution (Single file, framework-dependent)
dotnet publish Client/JustLinkIt.Client.csproj -p:PublishProfile=FolderProfile
```

The published output will be in `Client/bin/Release/net8.0-windows/win-x64/publish/JustLinkIt.Client.exe`. You can copy this folder to the target machine for distribution (Requires .NET 8 Desktop Runtime).

#### First Launch and Configuration

The client does not have a traditional settings window. **All configuration is done via the context menu by right-clicking the system tray icon.**

1. Launch `JustLinkIt.Client.exe`, and it will reside in your system tray.
2. Right-click the tray icon → "Settings" → "Set Upload URL..." and enter your server's upload API URL (e.g., `https://yourdomain.com/api/upload.php`).
3. If you set an `upload_api_key` on the server, enter the same key via "Settings" → "Set API Key...".
4. Check and modify your watch folders if necessary.
   - "Change Image Watch Folder..." — Defaults to Snipping Tool's screenshot folder (e.g., `Pictures\Screenshots`).
   - "Change Video Watch Folder..." — Defaults to Snipping Tool's screen recording folder. **Note:** This folder name is localized by Windows (e.g., "Screen Recordings" in English, "画面録画" in Japanese). Adjust this to match your actual environment upon first launch.
5. Other toggle settings can also be modified from this menu (detailed below).

Settings are saved instantly to `settings.json` in the executable folder whenever you change them via the menu.

Example configuration file (`settings.json`, usually no manual editing required):

```json
{
  "ServerUploadUrl": "https://yourdomain.com/api/upload.php",
  "UploadApiKey": "your-secret-api-key-here",
  "OpenBrowserOnUpload": true,
  "DeleteLocalFileAfterUpload": false,
  "WatchFolderPath": "C:\\Users\\you\\Pictures\\Screenshots",
  "WatchFolderPathVideo": "C:\\Users\\you\\Videos\\Screen Recordings",
  "HasPromptedSendToRegistration": true,
  "RunOnStartup": false
}
```

## Usage

### Background Auto-Upload

By simply keeping the client running in your system tray, the following happens automatically:

1. A new file is created in the watched folders (e.g., you save a screenshot/recording via Snipping Tool).
2. The app waits for the file write to complete, then automatically uploads it.
3. Upon successful upload, the public URL is copied to your clipboard.
4. (If enabled) The URL is automatically opened in your default browser.
5. (If enabled) The original file is moved to the Recycle Bin (only applies to files detected via watch folders).

After that, just press `Ctrl+V` in Discord, Slack, etc. to share it.

### Manual Upload via Tray Menu

Right-clicking the system tray icon allows you to:

- **Upload via File Dialog** — Select any image/video to upload.
- **Upload from Clipboard** — Directly upload an image currently copied to your clipboard.
- **Open Gallery** — Opens the gallery page in your default browser based on the configured server URL.

### Manual Upload via "Send To" Menu

On the first launch, you will be prompted: "Do you want to add JustLinkIt to the 'Send To' menu?" (If accepted, it registers automatically; if declined, it will not ask again). Once registered, you can right-click any image/video file in Explorer → "Send to" → "JustLinkIt" to upload it.

For any manual upload method ("Send To" or File Dialog), **the original file is NEVER deleted**, even if the "Delete local file after upload" setting is enabled (This setting strictly applies to background auto-uploads to prevent accidental data loss). Only temporary files generated during clipboard uploads are deleted regardless of the setting.

### Gallery

By navigating to `https://yourdomain.com/gallery/` and logging in with your shared password, you can view your uploaded files in a card grid layout.

- Scroll down to load more automatically (Infinite Scroll).
- Click a card to view it full size (`<dialog>` based lightbox, use Left/Right arrow keys to navigate).
- Click the 🔗 button on a card to 1-click copy the URL.
- Click the 🏷️ button to add/remove tags; click a tag chip to filter the gallery.
- You can delete or download the file from within the lightbox view.

### Uninstallation

Running `Client/uninstall.bat` will prompt for confirmation and then remove:

- The "Send To" menu shortcut.
- The Startup folder shortcut (if "Run on Startup" was enabled).
- `settings.json`.

You will need to delete the executable folder itself manually.

## FAQ / Troubleshooting

### Video URLs return a 404 Error

Video (`.mp4`) URLs use an extensionless format (`/u/{hash}`). Rather than serving the raw file directly, `.htaccess` routing redirects these URLs to `viewer.php` which generates the OGP page. If you get a 404, this routing is likely failing.

- Ensure `mod_rewrite` is enabled in Apache (e.g., `a2enmod rewrite`).
- Check that your VirtualHost (or `<Directory>`) configuration allows overrides: `AllowOverride All`. If it's set to `AllowOverride None`, the `.htaccess` file is ignored completely, and routing won't work (See the [Server Setup](#1-server-setup) VirtualHost example).
- Don't forget to restart Apache (e.g., `systemctl restart apache2`) after making config changes.

### Cannot upload large video files

If uploads fail even after increasing `max_file_size` in `config.php`, you are likely hitting PHP's internal limits.

- In your `php.ini`, increase **both** `upload_max_filesize` and `post_max_size` to a value equal to or greater than `max_file_size`. Increasing just one is not enough.
- Large files take longer to transfer and save, so also consider increasing `max_execution_time` (PHP script execution time limit).
- You must restart PHP (PHP-FPM or the Apache PHP module) for these changes to take effect.

### Cannot delete the client folder during uninstallation

If you get a "File in Use" error when running `uninstall.bat` or deleting the folder manually, `JustLinkIt.Client.exe` is still running in the background and locking the files.

- Right-click the system tray icon and select "Exit" to close the application before attempting to delete the folder.
- If the tray icon is missing, open Task Manager, check if the `JustLinkIt.Client.exe` process is still running, and terminate it.

## License

[MIT License](LICENSE)
