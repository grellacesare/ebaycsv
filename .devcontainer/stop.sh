#!/usr/bin/env bash
set -euo pipefail
umask 077
source "$(dirname -- "${BASH_SOURCE[0]}")/common.sh"
mw_stop
printf '%s\n' 'CSV Studio arrestato. Per riavviarlo esegui bash .devcontainer/start.sh.'
