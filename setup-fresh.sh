#!/usr/bin/env bash
#
# setup-fresh.sh — FIRST-TIME setup for QForge.
#
# Use this once, right after cloning the repo. It creates env files from their
# templates, builds the Docker images from scratch, installs dependencies,
# creates the database schema, and loads the demo dataset.
#
# It is DESTRUCTIVE to local Docker state for this project: it tears volumes
# down (-v), so any existing local database is wiped. For day-to-day setup
# after pulling new code, use ./setup.sh instead.
#
# Usage:  ./setup-fresh.sh
set -euo pipefail

source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/scripts/lib.sh"

cd "$PROJECT_ROOT"

printf '%s%s' "$c_bold" "$c_blue"
cat <<'BANNER'
  ___  _____
 / _ \|  ___|__  _ __ __ _  ___
| | | | |_ / _ \| '__/ _` |/ _ \
| |_| |  _| (_) | | | (_| |  __/
 \__\_\_|  \___/|_|  \__, |\___|
                     |___/
  First-time setup (fresh install)
BANNER
printf '%s\n' "$c_reset"

# 1. Tooling -----------------------------------------------------------------
step "Checking prerequisites"
detect_compose
ok "Docker Compose detected: ${DC[*]}"

# 2. Environment files -------------------------------------------------------
step "Preparing environment files"
ensure_env "$PROJECT_ROOT/.env"          "$PROJECT_ROOT/.env.example"
ensure_env "$PROJECT_ROOT/code/.env"     "$PROJECT_ROOT/code/.env.example"

# 3. Clean slate -------------------------------------------------------------
step "Tearing down any previous containers and volumes (fresh start)"
dc down -v --remove-orphans || true
ok "Clean slate"

# 4. Build + start -----------------------------------------------------------
step "Building images (no cache) and starting the stack"
dc build --no-cache
dc up -d
ok "Containers are up"

# 5. PHP dependencies --------------------------------------------------------
step "Installing PHP dependencies (composer install)"
app composer install --no-interaction --prefer-dist
ok "Composer dependencies installed"

# 6. App key -----------------------------------------------------------------
ensure_app_key

# 7. Database ----------------------------------------------------------------
wait_for_db
step "Creating schema and loading the demo dataset (migrate:fresh --seed)"
app php artisan migrate:fresh --seed --force
ok "Database migrated and seeded"

# 8. Optional: deepen the question bank for richer generation demos ----------
step "Seeding a larger question bank (BulkQuestionSeeder)"
if app php artisan db:seed --class=BulkQuestionSeeder --force; then
  ok "Bulk questions seeded"
else
  warn "BulkQuestionSeeder skipped (not fatal)"
fi

# 9. Frontend deps are installed at image build time; confirm the dev server -
step "Caches"
app php artisan optimize:clear >/dev/null 2>&1 || true
ok "Laravel caches cleared"

print_done
info "Tip: from now on, after pulling new code just run ./setup.sh"
