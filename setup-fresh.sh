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
# "Fresh" means a clean DATABASE, not a wiped machine. We tear the stack down
# WITHOUT -v (which would delete every named volume) and then remove only the
# volumes setup-fresh is meant to reset: the database, and the Qdrant RAG index
# (rebuildable via `artisan qforge:rag:reindex`). The multi-GB Ollama model
# cache (qforge_ollama) is deliberately kept, so a fresh install does NOT
# re-download qwen2.5 / nomic-embed-text every time.
step "Tearing down previous containers (keeping the Ollama model cache)"
dc down --remove-orphans || true
prefix="$(name_prefix)"
docker_cli volume rm -f "${prefix}db" "${prefix}qdrant" >/dev/null 2>&1 || true
ok "Clean slate — DB + RAG index reset, models preserved"

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

# 9. RAG index -------------------------------------------------------------
# setup-fresh wiped the Qdrant volume (step 3), so its collections are gone.
# Build them from MySQL now: this creates the `questions` + `chunks`
# collections and embeds the seeded bank in batched round-trips. Without it the
# index is empty until the first manual reindex (sync jobs would rebuild
# `questions` incrementally, but never `chunks`). Non-fatal — the app runs
# without RAG, and the index is always rebuildable later.
step "Building the RAG vector index (qforge:rag:reindex)"
if app php artisan qforge:rag:reindex; then
  ok "RAG index built"
else
  warn "RAG reindex skipped (not fatal — run 'php artisan qforge:rag:reindex' later)"
fi

# 10. Frontend deps are installed at image build time; confirm the dev server
step "Caches"
app php artisan optimize:clear >/dev/null 2>&1 || true
ok "Laravel caches cleared"

print_done
info "Tip: from now on, after pulling new code just run ./setup.sh"
