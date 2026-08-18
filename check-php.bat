@echo off
setlocal
set "PHP=php"
where php >nul 2>nul
if errorlevel 1 if exist "C:\xampp\php\php.exe" set "PHP=C:\xampp\php\php.exe"

echo PHP modules needed for this project:
echo.
"%PHP%" -m | findstr /I "pdo_sqlite sqlite3 curl"
echo.
echo You should see: pdo_sqlite, sqlite3, and curl.
echo If one is missing, enable it in C:\xampp\php\php.ini
pause
