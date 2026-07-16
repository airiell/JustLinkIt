@echo off
chcp 65001 >nul
setlocal

echo JustLinkIt のお掃除を行います。
echo   - 「送る」メニューのショートカットを削除
echo   - 設定ファイル（settings.json）を削除
echo.
set /p CONFIRM="続行しますか？ (Y/N): "
if /i not "%CONFIRM%"=="Y" goto :cancelled

REM 実行中のJustLinkIt.Client.exeを終了させる（設定ファイルのロック解除のため）
taskkill /IM JustLinkIt.Client.exe /F >nul 2>&1

REM 「送る」メニューのショートカットを削除
set "SENDTO_SHORTCUT=%APPDATA%\Microsoft\Windows\SendTo\JustLinkIt.lnk"
if not exist "%SENDTO_SHORTCUT%" goto :no_shortcut
del /f /q "%SENDTO_SHORTCUT%"
echo 「送る」メニューのショートカットを削除しました。
goto :check_startup

:no_shortcut
echo 「送る」メニューのショートカットは見つかりませんでした。

:check_startup
REM スタートアップフォルダのショートカットを削除
set "STARTUP_SHORTCUT=%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup\JustLinkIt.lnk"
if not exist "%STARTUP_SHORTCUT%" goto :no_startup_shortcut
del /f /q "%STARTUP_SHORTCUT%"
echo スタートアップのショートカットを削除しました。
goto :check_settings

:no_startup_shortcut
echo スタートアップのショートカットは見つかりませんでした。

:check_settings
REM 設定ファイルを削除（このバッチファイルと同じフォルダにある想定）
set "SETTINGS_FILE=%~dp0settings.json"
if not exist "%SETTINGS_FILE%" goto :no_settings
del /f /q "%SETTINGS_FILE%"
echo 設定ファイルを削除しました。
goto :done

:no_settings
echo 設定ファイルは見つかりませんでした。

:done
echo.
echo お掃除が完了しました。JustLinkItのフォルダは手動で削除してください。
goto :end

:cancelled
echo 中止しました。

:end
pause
endlocal
