@echo off
REM ============================================================================
REM  setup.bat - FIRST-TIME setup for QForge on Windows (Docker Desktop).
REM
REM  Windows counterpart of setup-fresh.sh. Run this once, right after cloning:
REM  it creates the env files from their templates, builds the images, starts
REM  the stack, installs dependencies, and creates + seeds the database.
REM
REM  DESTRUCTIVE: it tears down this project's Docker volumes (-v), so any
REM  existing local QForge database is wiped. After pulling new code later,
REM  use update.bat instead (non-destructive).
REM
REM  Usage (from the repo root):  setup.bat
REM ============================================================================
setlocal enabledelayedexpansion
cd /d "%~dp0"

echo.
echo ==^> QForge - first-time setup (Windows)
echo.

REM --- 1. Prerequisites -------------------------------------------------------
where docker >nul 2>&1
if errorlevel 1 (
  echo   [X] Docker is not installed. Install Docker Desktop: https://docs.docker.com/get-docker/
  goto :fail
)
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

REM --- 2. Confirm (this wipes local volumes) ----------------------------------
echo.
echo   This performs a FRESH install and will REMOVE this project's Docker
echo   volumes (local QForge database will be wiped).
set /p "CONFIRM=  Continue? [y/N] "
if /i not "%CONFIRM%"=="y" (
  echo   Aborted.
  goto :end
)

REM --- 3. Environment files ---------------------------------------------------
echo.
echo ==^> Preparing environment files
if not exist ".env" (
  copy /y ".env.example" ".env" >nul && echo   [ok] Created .env from .env.example
) else (
  echo   [ok] .env already present
)
if not exist "code\.env" (
  copy /y "code\.env.example" "code\.env" >nul && echo   [ok] Created code\.env from code\.env.example
) else (
  echo   [ok] code\.env already present
)

REM Read the values the script needs out of .env (fallbacks match .env.example).
set "NGINX_HTTP_PORT=8040"
set "FRONTEND_PORT=5173"
set "PYTHON_PORT=8000"
set "MYSQL_ROOT_PASSWORD=root"
for /f "usebackq tokens=1,* delims==" %%A in (".env") do (
  if /i "%%A"=="NGINX_HTTP_PORT" set "NGINX_HTTP_PORT=%%B"
  if /i "%%A"=="FRONTEND_PORT" set "FRONTEND_PORT=%%B"
  if /i "%%A"=="PYTHON_PORT" set "PYTHON_PORT=%%B"
  if /i "%%A"=="MYSQL_ROOT_PASSWORD" set "MYSQL_ROOT_PASSWORD=%%B"
)

REM --- 4. Clean slate + build + start -----------------------------------------
echo.
echo ==^> Tearing down any previous containers and volumes (fresh start)
%DC% down -v --remove-orphans

echo.
echo ==^> Building images (no cache) and starting the stack
%DC% build --no-cache
if errorlevel 1 goto :fail
%DC% up -d
if errorlevel 1 goto :fail
echo   [ok] Containers are up

REM --- 5. PHP dependencies ----------------------------------------------------
echo.
echo ==^> Installing PHP dependencies (composer install)
%DC% exec -T qforge_app composer install --no-interaction --prefer-dist
if errorlevel 1 goto :fail

REM --- 6. Wait for the database ----------------------------------------------
echo.
echo ==^> Waiting for the database to be ready
set /a tries=0
:waitdb
%DC% exec -T qforge_db mysqladmin ping -h localhost -uroot -p%MYSQL_ROOT_PASSWORD% --silent >nul 2>&1
if not errorlevel 1 goto :dbready
set /a tries+=1
if %tries% geq 60 (
  echo   [X] Database did not become ready. Check: %DC% logs qforge_db
  goto :fail
)
<nul set /p "=."
timeout /t 2 /nobreak >nul
goto :waitdb
:dbready
echo.
echo   [ok] Database is up

REM --- 7. App key -------------------------------------------------------------
echo.
echo ==^> Ensuring Laravel application key
findstr /b /c:"APP_KEY=base64:" "code\.env" >nul 2>&1
if errorlevel 1 (
  %DC% exec -T qforge_app php artisan key:generate --force
  echo   [ok] APP_KEY generated
) else (
  echo   [ok] APP_KEY already set
)

REM --- 8. Schema + demo dataset ----------------------------------------------
echo.
echo ==^> Creating schema and loading the demo dataset (migrate:fresh --seed)
%DC% exec -T qforge_app php artisan migrate:fresh --seed --force
if errorlevel 1 goto :fail

echo.
echo ==^> Seeding a larger question bank (BulkQuestionSeeder, optional)
%DC% exec -T qforge_app php artisan db:seed --class=BulkQuestionSeeder --force
if errorlevel 1 echo   [!] BulkQuestionSeeder skipped (not fatal)

REM --- 9. Caches --------------------------------------------------------------
%DC% exec -T qforge_app php artisan optimize:clear >nul 2>&1

REM --- Done -------------------------------------------------------------------
echo.
echo ========================================
echo   QForge is ready
echo ========================================
echo.
echo   Frontend (Vue)   http://localhost:%FRONTEND_PORT%
echo   App (via Nginx)  http://localhost:%NGINX_HTTP_PORT%
echo   API health       http://localhost:%NGINX_HTTP_PORT%/api/health
echo   Python service   http://localhost:%PYTHON_PORT%/health
echo.
echo   Demo logins (password: password):
echo     admin@qforge.com     - role: admin
echo     teacher@qforge.com   - role: teacher
echo.
echo   After pulling new code later, run:  update.bat
echo.
goto :end

:fail
echo.
echo   Setup failed. Review the output above.
echo   Logs:  %DC% logs
endlocal
exit /b 1

:end
endlocal
exit /b 0
