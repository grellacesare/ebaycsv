#!/usr/bin/env bash
set -euo pipefail
MW_REPO_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
export UV_CACHE_DIR="${UV_CACHE_DIR:-$MW_REPO_DIR/var/cache/uv}"
export NPM_CONFIG_CACHE="${NPM_CONFIG_CACHE:-$MW_REPO_DIR/var/cache/npm}"
mkdir -p "$UV_CACHE_DIR" "$NPM_CONFIG_CACHE"
backend_pid=''
frontend_pid=''
cleanup() {
  for pid in "$backend_pid" "$frontend_pid"; do
    if [[ -n "$pid" ]]; then kill "$pid" 2>/dev/null || true; fi
  done
  wait 2>/dev/null || true
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
cd "$MW_REPO_DIR/backend"
uv run --frozen uvicorn motorworld_csv.api.app:create_app --factory --host 0.0.0.0 --port 8000 &
backend_pid=$!
cd "$MW_REPO_DIR/frontend"
node node_modules/vite/bin/vite.js --host 0.0.0.0 --port 5173 --strictPort &
frontend_pid=$!
wait -n "$backend_pid" "$frontend_pid"
