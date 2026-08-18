@echo off
setlocal
cd /d "%~dp0"
set "PHP=php"
where php >nul 2>nul
if errorlevel 1 (
  if exist "C:\xampp\php\php.exe" set "PHP=C:\xampp\php\php.exe"
)

if /I "%PHP%"=="php" (
  where php >nul 2>nul
  if errorlevel 1 goto nophp
) else (
  if not exist "%PHP%" goto nophp
)

echo Checking PHP...
"%PHP%" -r "if(!extension_loaded('pdo_sqlite')){fwrite(STDERR,'MISSING_SQLITE');exit(2);} echo 'SQLite OK';"
if errorlevel 2 (
  echo.
  echo PHP SQLite is not enabled.
  echo Open C:\xampp\php\php.ini
  echo Enable these lines:
  echo extension=pdo_sqlite
  echo extension=sqlite3
  echo Save the file and run this again.
  pause
  exit /b 1
)

echo.
"%PHP%" -r "echo function_exists('curl_init') ? 'Google Sheet connection: cURL OK' : 'NOTE: cURL is not enabled. Enable extension=curl in php.ini for Google Sheet sync.';"
echo.
echo Starting Daily Task Manager...
echo Open this in Chrome: http://localhost:8000
start "" http://localhost:8000
"%PHP%" -S localhost:8000 -t public
pause
exit /b 0

:nophp
echo PHP was not found.
echo.
echo Easy option: install XAMPP, then run this file again.
echo https://www.apachefriends.org/
pause
exit /b 1
