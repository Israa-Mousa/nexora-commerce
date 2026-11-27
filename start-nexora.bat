@echo off
title Nexora Commerce - http://127.0.0.1:8000
cd /d "%~dp0"
set "PHP=%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"
if not exist "%PHP%" (
  echo PHP 8.4 was not found at "%PHP%".
  pause
  exit /b 1
)
echo Clearing page cache...
"%PHP%" artisan responsecache:clear
"%PHP%" artisan optimize:clear
start "" http://127.0.0.1:8000
echo.
echo Nexora is running at http://127.0.0.1:8000  (close this window to stop it)
"%PHP%" artisan serve --host=127.0.0.1 --port=8000
pause
