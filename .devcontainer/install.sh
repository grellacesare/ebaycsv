#!/usr/bin/env bash
set -euo pipefail
umask 077
MW_REPO_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
export UV_CACHE_DIR="${UV_CACHE_DIR:-$MW_REPO_DIR/var/cache/uv}"
export NPM_CONFIG_CACHE="${NPM_CONFIG_CACHE:-$MW_REPO_DIR/var/cache/npm}"
mkdir -p "$UV_CACHE_DIR" "$NPM_CONFIG_CACHE"
python3 -c 'import sys; assert sys.version_info >= (3, 12), "Serve Python 3.12 o successivo"'
source "$MW_REPO_DIR/.devcontainer/toolchain.sh"
mw_ensure_node
mw_ensure_uv
printf 'Node %s · uv %s\n' "$(node --version)" "$(uv --version)"
cd "$MW_REPO_DIR/backend"
uv sync --frozen
cd "$MW_REPO_DIR/frontend"
npm ci
npm run build
