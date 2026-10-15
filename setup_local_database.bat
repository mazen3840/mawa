@echo off
setlocal

set "SQL_FILE=%~1"
if not defined SQL_FILE set "SQL_FILE=%USERPROFILE%\Desktop\if0_42960633_maw.sql"
if not exist "%SQL_FILE%" (
  echo SQL export not found. Pass its full path as an argument:
  echo setup_local_database.bat "C:\path\to\database.sql"
  pause
  exit /b 1
)

set "MARIADB_BIN="
for /d %%D in ("%ProgramFiles%\MariaDB *") do if exist "%%~fD\bin\mysqld.exe" set "MARIADB_BIN=%%~fD\bin"
if not defined MARIADB_BIN (
  echo MariaDB was not found. Install MariaDB 11.4 first.
  pause
  exit /b 1
)

set "DB_DATA=%LOCALAPPDATA%\Mawared\MariaDB\data"
if not exist "%DB_DATA%\mysql" (
  if not exist "%DB_DATA%" mkdir "%DB_DATA%"
  "%MARIADB_BIN%\mariadb-install-db.exe" --datadir="%DB_DATA%" --port=3307 --silent
  if errorlevel 1 (
    echo Failed to initialize the local MariaDB data directory.
    pause
    exit /b 1
  )
)

powershell -NoProfile -Command "$c=New-Object Net.Sockets.TcpClient; try{$c.Connect('127.0.0.1',3307);exit 0}catch{exit 1}finally{$c.Dispose()}" >nul 2>nul
if errorlevel 1 start "Mawared Local MariaDB" /min "%MARIADB_BIN%\mysqld.exe" --defaults-file="%DB_DATA%\my.ini" --bind-address=127.0.0.1

set "DB_READY="
for /l %%I in (1,1,25) do (
  "%MARIADB_BIN%\mariadb.exe" --protocol=tcp --host=127.0.0.1 --port=3307 --user=root --batch --skip-column-names -e "SELECT 1;" >nul 2>nul
  if not errorlevel 1 set "DB_READY=1"
  if defined DB_READY goto :database_ready
  timeout /t 1 /nobreak >nul
)
:database_ready
if not defined DB_READY (
  echo Could not start MariaDB on 127.0.0.1:3307.
  pause
  exit /b 1
)

"%MARIADB_BIN%\mariadb.exe" --protocol=tcp --host=127.0.0.1 --port=3307 --user=root --batch --skip-column-names -e "USE mawared_local;" >nul 2>nul
if not errorlevel 1 (
  echo mawared_local already exists. Import was stopped to protect existing local data.
  echo To re-import, back up or rename that database first; this script never overwrites it.
  pause
  exit /b 1
)

"%MARIADB_BIN%\mariadb.exe" --protocol=tcp --host=127.0.0.1 --port=3307 --user=root -e "CREATE DATABASE mawared_local CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if errorlevel 1 (
  echo Could not create the local application database.
  pause
  exit /b 1
)

echo Importing the database to local MariaDB. This may take a few minutes...
%ComSpec% /d /c ""%MARIADB_BIN%\mariadb.exe" --protocol=tcp --host=127.0.0.1 --port=3307 --user=root mawared_local < "%SQL_FILE%""
if errorlevel 1 (
  echo Import failed. The local database may be partial; it was not deleted automatically.
  pause
  exit /b 1
)

"%MARIADB_BIN%\mariadb.exe" --protocol=tcp --host=127.0.0.1 --port=3307 --user=root mawared_local -e "DELETE FROM sessions; DELETE FROM login_attempts; UPDATE user_webhooks SET call_url=NULL, hangup_url=NULL;"
if errorlevel 1 (
  echo Import succeeded, but local session and call-webhook cleanup failed.
  echo Do not use this local copy until those fields are cleared.
  pause
  exit /b 1
)

"%MARIADB_BIN%\mariadb.exe" --protocol=tcp --host=127.0.0.1 --port=3307 --user=root --batch --skip-column-names -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='mawared_local';"
echo Local database setup completed. Run start_localhost.bat to launch the application.
pause
endlocal
