#!/usr/bin/env bash
#
# setup.sh — routine setup AFTER pulling new code.
#
# Use this whenever you have already done the first-time install (./setup-fresh.sh)
# and have just pulled changes. It is NON-destructive: it keeps your database and
# volumes, brings the stack up, syncs dependencies, and applies any new migrations.
#
# Usage:  ./setup.sh
set -euo pipefail

source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/scripts/lib.sh"

cd "$PROJECT_ROOT"

printf '%s%s==> QForge — sync after pull%s\n' "$c_bold" "$c_blue" "$c_reset"

# 1. Tooling -----------------------------------------------------------------
step "Checking prerequisites"
detect_compose
ok "Docker Compose detected: ${DC[*]}"

# 2. Guard: this is not the first-time path ----------------------------------
if [[ ! -f "$PROJECT_ROOT/.env" || ! -f "$PROJECT_ROOT/code/.env" ]]; then
  die "Env files are missing — this looks like a first install. Run ./setup-fresh.sh instead."
fi
ok "Environment files present"

# 3. Start (build picks up any Dockerfile changes; cached layers reused).
#    If a rebuild fails (e.g. registry/credential hiccup), fall back to a plain
#    start so a transient build error never tears down a working stack.
step "Starting the stack (building changed images)"
if dc up -d --build; then
  ok "Containers are up (rebuilt where needed)"
else
  warn "Rebuild failed — starting with existing images instead."
  warn "If you changed a Dockerfile, run: ${DC[*]} build && ${DC[*]} up -d"
  dc up -d
  ok "Containers are up (existing images)"
fi

# 4. Dependencies (in case composer.json / package.json changed) -------------
step "Syncing PHP dependencies"
app composer install --no-interaction --prefer-dist
ok "Composer in sync"

step "Syncing frontend dependencies"
if dc exec -T "$FRONTEND_SERVICE" npm install; then
  ok "npm in sync"
else
  warn "npm install skipped/failed (frontend container may still be starting)"
fi

# 5. App key (only if somehow unset) -----------------------------------------
ensure_app_key

# 6. Apply new migrations (NON-destructive — existing data preserved) --------
wait_for_db
step "Applying new migrations"
app php artisan migrate --force
ok "Migrations up to date"

# 7. Refresh caches to match the new code ------------------------------------
step "Refreshing Laravel caches"
app php artisan optimize:clear >/dev/null 2>&1 || true
ok "Caches cleared"

print_done
info "Data preserved. To rebuild from scratch instead, run ./setup-fresh.sh"
