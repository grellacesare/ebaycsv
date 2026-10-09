#!/usr/bin/env bash
MW_REPO_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
export MW_DATA_DIR="${MW_DATA_DIR:-$MW_REPO_DIR/var/archive}"
export MW_PORT="${MW_PORT:-8000}"
if [[ ! "$MW_PORT" =~ ^[0-9]+$ ]] || (( 10#$MW_PORT < 1024 || 10#$MW_PORT > 65535 )); then
    printf '%s\n' 'MW_PORT deve essere compresa tra 1024 e 65535.' >&2
    exit 1
fi
MW_RUNTIME_DIR="$MW_REPO_DIR/var/runtime"
MW_PID_FILE="$MW_RUNTIME_DIR/codespaces-$MW_PORT.pid"
MW_LOG_FILE="$MW_RUNTIME_DIR/codespaces-$MW_PORT.log"
mkdir -p "$MW_RUNTIME_DIR" "$MW_DATA_DIR"
exec 9>"$MW_RUNTIME_DIR/control-$MW_PORT.lock"
flock 9

mw_owned_process() {
    python3 - "$1" "$MW_REPO_DIR" "$MW_DATA_DIR" "$MW_PORT" <<'PY'
from pathlib import Path
import sys
try:
    process = Path('/proc') / str(int(sys.argv[1]))
    if process.joinpath('stat').read_text().rsplit(')', 1)[1].split()[0] == 'Z':
        raise ValueError('Processo terminato')
    args = process.joinpath('cmdline').read_bytes().split(b'\0')
    env = dict(item.split(b'=', 1) for item in process.joinpath('environ').read_bytes().split(b'\0') if b'=' in item)
    assert process.joinpath('cwd').resolve() == Path(sys.argv[2]) / 'backend'
    assert b'motorworld_csv.api.app:create_app' in args
    assert b'--port' in args and args[args.index(b'--port') + 1] == sys.argv[4].encode()
    assert env.get(b'MW_DATA_DIR') == sys.argv[3].encode()
except (OSError, ValueError, AssertionError, IndexError):
    sys.exit(1)
PY
}

mw_ready() {
    python3 - "$MW_PORT" <<'PY'
import json
import sys
from urllib.request import urlopen
try:
    base = 'http://127.0.0.1:' + sys.argv[1]
    with urlopen(base + '/api/health', timeout=2) as response:
        assert json.load(response)['status'] == 'ok'
    with urlopen(base + '/', timeout=2) as response:
        assert b'CSV Studio' in response.read()
except Exception:
    sys.exit(1)
PY
}

mw_start() {
    if [[ ! -x "$MW_REPO_DIR/backend/.venv/bin/python" || ! -f "$MW_REPO_DIR/frontend/dist/index.html" ]]; then
        printf '%s\n' 'Installazione incompleta. Esegui bash .devcontainer/install.sh e riprova.' >&2
        return 1
    fi
    local mw_pid=''
    if [[ -f "$MW_PID_FILE" ]]; then
        read -r mw_pid < "$MW_PID_FILE" || true
        if mw_owned_process "$mw_pid"; then
            if mw_ready; then
                printf '%s\n' 'CSV Studio è già avviato.'
                return 0
            fi
            printf '%s\n' "CSV Studio è già in avvio. Controlla $MW_LOG_FILE." >&2
            return 1
        fi
    fi
    (
        cd "$MW_REPO_DIR/backend"
        exec nohup "$MW_REPO_DIR/backend/.venv/bin/python" -m uvicorn \
            motorworld_csv.api.app:create_app --factory --host 0.0.0.0 \
            --port "$MW_PORT" --workers 1
    ) 9>&- </dev/null >>"$MW_LOG_FILE" 2>&1 &
    mw_pid=$!
    printf '%s\n' "$mw_pid" > "$MW_PID_FILE"
    for ((mw_attempt=0; mw_attempt<100; mw_attempt++)); do
        if mw_owned_process "$mw_pid" && mw_ready; then
            printf '%s\n' 'CSV Studio avviato. In Codespaces apri la porta 8000 e mantieni la visibilità Private.'
            return 0
        fi
        if ! kill -0 "$mw_pid" 2>/dev/null; then break; fi
        sleep 0.2
    done
    printf '%s\n' "Avvio non riuscito. Controlla $MW_LOG_FILE." >&2
    if mw_owned_process "$mw_pid"; then kill -TERM "$mw_pid"; fi
    wait "$mw_pid" 2>/dev/null || true
    rm -f "$MW_PID_FILE"
    return 1
}

mw_stop() {
    if [[ ! -f "$MW_PID_FILE" ]]; then return 0; fi
    local mw_pid=''
    read -r mw_pid < "$MW_PID_FILE" || true
    if mw_owned_process "$mw_pid"; then
        kill -TERM "$mw_pid"
        for ((mw_attempt=0; mw_attempt<100; mw_attempt++)); do
            if ! mw_owned_process "$mw_pid"; then
                rm -f "$MW_PID_FILE"
                return 0
            fi
            sleep 0.2
        done
        printf '%s\n' 'Il programma non si è ancora arrestato. Backup annullato.' >&2
        return 1
    fi
    rm -f "$MW_PID_FILE"
}
