@echo off
REM ============================================================================
REM  update.bat - routine setup AFTER pulling new code, on Windows.
REM
REM  Windows counterpart of setup.sh. Use this once you have already done the
REM  first-time install (setup.bat) and have just pulled changes. It is
REM  NON-destructive: it keeps your database and volumes, brings the stack up,
REM  syncs dependencies, and applies any new migrations.
REM
REM  Usage (from the repo root):  update.bat
REM ============================================================================
setlocal enabledelayedexpansion
cd /d "%~dp0"

echo.
echo ==^> QForge - sync after pull (Windows)
echo.

REM --- 1. Prerequisites -------------------------------------------------------
docker compose version >nul 2>&1
if errorlevel 1 (
  echo   [X] Docker Compose v2 not found. Start Docker Desktop and try again.
  goto :fail
)
docker info >nul 2>&1
if errorlevel 1 (
  echo   [X] Cannot talk to the Docker daemon. Is Docker Desktop running?
  goto :fail
)
set "DC=docker compose"
echo   [ok] Docker and Compose detected.

REM --- 2. Guard: this is not the first-time path ------------------------------
if not exist ".env" goto :needfresh
if not exist "code\.env" goto :needfresh
echo   [ok] Environment files present
goto :haveenv
:needfresh
echo   [X] Env files are missing - this looks like a first install. Run setup.bat instead.
goto :fail
:haveenv

REM Read the ports for the summary (fallbacks match .env.example).
set "NGINX_HTTP_PORT=8040"
set "FRONTEND_PORT=5173"
set "PYTHON_PORT=8000"
for /f "usebackq tokens=1,* delims==" %%A in (".env") do (
  if /i "%%A"=="NGINX_HTTP_PORT" set "NGINX_HTTP_PORT=%%B"
  if /i "%%A"=="FRONTEND_PORT" set "FRONTEND_PORT=%%B"
  if /i "%%A"=="PYTHON_PORT" set "PYTHON_PORT=%%B"
)

REM --- 3. Start (build picks up any Dockerfile changes; cached layers reused) --
echo.
echo ==^> Starting the stack (building changed images)
%DC% up -d --build
if errorlevel 1 (
  echo   [!] Rebuild failed - starting with existing images instead.
  %DC% up -d
  if errorlevel 1 goto :fail
)
echo   [ok] Containers are up

REM --- 4. Dependencies (in case composer.json / package.json changed) ---------
echo.
echo ==^> Syncing PHP dependencies
%DC% exec -T qforge_app composer install --no-interaction --prefer-dist
if errorlevel 1 goto :fail

echo.
echo ==^> Syncing frontend dependencies
%DC% exec -T qforge_frontend npm install
if errorlevel 1 echo   [!] npm install skipped/failed (frontend container may still be starting)

REM --- 5. Apply new migrations (NON-destructive - data preserved) -------------
echo.
echo ==^> Applying new migrations
%DC% exec -T qforge_app php artisan migrate --force
if errorlevel 1 goto :fail

REM --- 6. Refresh caches ------------------------------------------------------
%DC% exec -T qforge_app php artisan optimize:clear >nul 2>&1

echo.
echo ========================================
echo   QForge is up to date
echo ========================================
echo.
echo   Frontend (Vue)   http://localhost:%FRONTEND_PORT%
echo   API health       http://localhost:%NGINX_HTTP_PORT%/api/health
echo   Python service   http://localhost:%PYTHON_PORT%/health
echo.
echo   Data preserved. To rebuild from scratch instead, run setup.bat
echo.
goto :end

:fail
echo.
echo   Update failed. Review the output above.
echo   Logs:  %DC% logs
endlocal
exit /b 1

:end
endlocal
exit /b 0
