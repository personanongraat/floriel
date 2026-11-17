@echo off
cd /d "%~dp0"
php -S 127.0.0.1:4173 -t public_html
pause
