@echo off
setlocal
cd /d "%~dp0"

set "MAWARED_ENV=local"
set "MAWARED_DB_HOST=127.0.0.1"
set "MAWARED_DB_PORT=3307"
set "MAWARED_DB_USER=root"
set "MAWARED_DB_PASS="
set "MAWARED_DB_NAME=mawared_local"
set "MAWARED_DB_CHARSET=utf8mb4"
set "MAWARED_SITE_URL=http://127.0.0.1:8000"
set "MAWARED_APP_VERSION=3.2.0"

set "PHP_EXE="
for /d %%D in ("%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.4*") do if exist "%%~fD\php.exe" set "PHP_EXE=%%~fD\php.exe"
if not defined PHP_EXE for /f "delims=" %%P in ('where php 2^>nul') do if not defined PHP_EXE set "PHP_EXE=%%P"
if not defined PHP_EXE (
  echo PHP 8.4 was not found. Install it with: winget install --id PHP.PHP.8.4
  pause
  exit /b 1
)
for %%I in ("%PHP_EXE%") do set "PHP_HOME=%%~dpI"

set "MARIADB_BIN="
for /d %%D in ("%ProgramFiles%\MariaDB *") do if exist "%%~fD\bin\mysqld.exe" set "MARIADB_BIN=%%~fD\bin"
if not defined MARIADB_BIN (
  echo MariaDB was not found. Install MariaDB 11.4, then run setup_local_database.bat.
  pause
  exit /b 1
)

set "DB_DATA=%LOCALAPPDATA%\Mawared\MariaDB\data"
if not exist "%DB_DATA%\mysql" (
  echo The local database is not initialized. Run setup_local_database.bat first.
  pause
  exit /b 1
)

powershell -NoProfile -Command "$c=New-Object Net.Sockets.TcpClient; try{$c.Connect('127.0.0.1',3307);exit 0}catch{exit 1}finally{$c.Dispose()}" >nul 2>nul
if errorlevel 1 (
  start "Mawared Local MariaDB" /min "%MARIADB_BIN%\mysqld.exe" --defaults-file="%DB_DATA%\my.ini" --bind-address=127.0.0.1
)

set "DB_READY="
for /l %%I in (1,1,25) do (
  "%MARIADB_BIN%\mariadb.exe" --protocol=tcp --host=127.0.0.1 --port=3307 --user=root --batch --skip-column-names -e "USE mawared_local;" >nul 2>nul
  if not errorlevel 1 set "DB_READY=1"
  if defined DB_READY goto :database_ready
  timeout /t 1 /nobreak >nul
)
:database_ready
if not defined DB_READY (
  echo Could not connect to the local database on port 3307.
  pause
  exit /b 1
)

"%PHP_EXE%" -d "extension_dir=%PHP_HOME%ext" -d extension=pdo_mysql -d extension=mbstring -r "exit(extension_loaded('pdo_mysql') && extension_loaded('mbstring') ? 0 : 1);"
if errorlevel 1 (
  echo PHP could not load its PDO MySQL extension.
  pause
  exit /b 1
)

echo Local application: http://127.0.0.1:8000/
echo Database: local MariaDB on 127.0.0.1:3307
echo Press Ctrl+C to stop the PHP web server. The minimized MariaDB window can be closed separately.
"%PHP_EXE%" -d "extension_dir=%PHP_HOME%ext" -d extension=pdo_mysql -d extension=mbstring -d date.timezone=Africa/Cairo -d upload_max_filesize=20M -d post_max_size=25M -d max_execution_time=180 -S 127.0.0.1:8000 -t "%~dp0"
endlocal
