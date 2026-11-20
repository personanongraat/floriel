@echo off
setlocal
cd /d "%~dp0" || exit /b 1
set "PHP_EXE=php.exe"
if exist ".local-php\php.exe" set "PHP_EXE=%~dp0.local-php\php.exe"
if "%PHP_EXE%"=="php.exe" (
    where /q php.exe
    if errorlevel 1 (
        echo PHP is missing. Install PHP 8.3 or newer and add it to PATH.
        pause
        exit /b 1
    )
)
echo Open http://127.0.0.1:4173/ in your browser.
"%PHP_EXE%" -S 127.0.0.1:4173 -t public_html
if errorlevel 1 pause
