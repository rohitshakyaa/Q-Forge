#!/usr/bin/env bash
#
# Shared helpers for setup.sh / setup-fresh.sh.
# Sourced, not executed directly.

set -euo pipefail

# --- pretty output -----------------------------------------------------------
c_reset=$'\033[0m'; c_blue=$'\033[34m'; c_green=$'\033[32m'
c_yellow=$'\033[33m'; c_red=$'\033[31m'; c_bold=$'\033[1m'

step() { printf '\n%s==>%s %s%s%s\n' "$c_blue" "$c_reset" "$c_bold" "$*" "$c_reset"; }
info() { printf '    %s\n' "$*"; }
ok()   { printf '%s  ✓%s %s\n' "$c_green" "$c_reset" "$*"; }
warn() { printf '%s  !%s %s\n' "$c_yellow" "$c_reset" "$*"; }
die()  { printf '%s  ✗ %s%s\n' "$c_red" "$*" "$c_reset" >&2; exit 1; }

# --- project root ------------------------------------------------------------
# Resolve to the directory that contains docker-compose.yml (one level up from
# this script's directory).
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# --- service names (keep in step with docker-compose.yml) --------------------
APP_SERVICE="qforge_app"
FRONTEND_SERVICE="qforge_frontend"
DB_SERVICE="qforge_db"

# --- docker compose, with sudo only when this host needs it ------------------
# Sets the DC array, e.g. DC=(docker compose) or DC=(sudo docker compose).
detect_compose() {
  command -v docker >/dev/null 2>&1 || die "Docker is not installed. See https://docs.docker.com/get-docker/"

  if docker compose version >/dev/null 2>&1; then
    DC_BASE=(docker compose)
  elif command -v docker-compose >/dev/null 2>&1; then
    DC_BASE=(docker-compose)
  else
    die "Docker Compose v2 not found. Install Docker Desktop or the compose plugin."
  fi

  if docker info >/dev/null 2>&1; then
    DC=("${DC_BASE[@]}")
  else
    warn "Docker needs elevated privileges on this host — using sudo (you may be prompted)."
    DC=(sudo "${DC_BASE[@]}")
    sudo -v || die "sudo is required to talk to the Docker daemon on this host."
  fi
}

dc() { ( cd "$PROJECT_ROOT" && "${DC[@]}" "$@" ); }

# Run a command inside the app container.
app() { dc exec -T "$APP_SERVICE" "$@"; }

# --- ensure an env file exists (copy from its .example if missing) -----------
ensure_env() {
  local target="$1" example="$2"
  if [[ -f "$target" ]]; then
    ok "$(basename "$target") already present"
  elif [[ -f "$example" ]]; then
    cp "$example" "$target"
    ok "Created $(basename "$target") from $(basename "$example")"
  else
    die "Missing $target and no template at $example"
  fi
}

# --- wait until MySQL is accepting connections -------------------------------
wait_for_db() {
  step "Waiting for the database to be ready"
  local user pass tries=60
  user="$(grep -E '^MYSQL_USER=' "$PROJECT_ROOT/.env" | cut -d= -f2-)"
  pass="$(grep -E '^MYSQL_USER_PASSWORD=' "$PROJECT_ROOT/.env" | cut -d= -f2-)"
  for ((i = 1; i <= tries; i++)); do
    if dc exec -T "$DB_SERVICE" mysqladmin ping -h localhost -u"$user" -p"$pass" --silent >/dev/null 2>&1; then
      ok "Database is up"
      return 0
    fi
    printf '\r    waiting… (%d/%d)' "$i" "$tries"
    sleep 2
  done
  printf '\n'
  die "Database did not become ready in time. Check: ${DC[*]} logs $DB_SERVICE"
}

# --- generate APP_KEY only if it is empty ------------------------------------
ensure_app_key() {
  if grep -qE '^APP_KEY=base64:.+' "$PROJECT_ROOT/code/.env"; then
    ok "Laravel APP_KEY already set"
  else
    step "Generating Laravel application key"
    app php artisan key:generate --force
    ok "APP_KEY generated"
  fi
}

print_done() {
  printf '\n%s%s========================================%s\n' "$c_green" "$c_bold" "$c_reset"
  printf '%s%s  QForge is ready%s\n' "$c_green" "$c_bold" "$c_reset"
  printf '%s%s========================================%s\n\n' "$c_green" "$c_bold" "$c_reset"
  local web fe py
  web="$(grep -E '^NGINX_HTTP_PORT=' "$PROJECT_ROOT/.env" | cut -d= -f2-)"
  fe="$(grep -E '^FRONTEND_PORT=' "$PROJECT_ROOT/.env" | cut -d= -f2-)"
  py="$(grep -E '^PYTHON_PORT=' "$PROJECT_ROOT/.env" | cut -d= -f2-)"
  info "Frontend (Vue)   http://localhost:${fe:-5173}"
  info "API (Laravel)    http://localhost:${web:-8040}/api/health"
  info "Python service   http://localhost:${py:-8000}/health"
  printf '\n'
  info "Demo logins (password: 'password'):"
  info "  teacher@qforge.com  ·  role: teacher"
  info "  admin@qforge.com    ·  role: admin"
  printf '\n'
}
