#!/usr/bin/env bash
set -euo pipefail
umask 077
source "$(dirname -- "${BASH_SOURCE[0]}")/common.sh"
if [[ -f "$MW_PID_FILE" ]] && mw_owned_process "$(cat "$MW_PID_FILE")"; then
    python3 - "$MW_PORT" <<'PY'
import json
import sys
from urllib.request import urlopen
with urlopen('http://127.0.0.1:' + sys.argv[1] + '/api/jobs', timeout=5) as response:
    jobs = json.load(response)
if any(job['state'] in ('queued', 'running') for job in jobs):
    sys.exit('Attendi la fine della lavorazione prima di creare il backup.')
PY
fi
mw_stop
trap mw_start EXIT
python3 - "$MW_DATA_DIR" "$MW_REPO_DIR/var/backups" <<'PY'
from datetime import datetime
from pathlib import Path
from zoneinfo import ZoneInfo
import sys
import zipfile
data, backups = map(Path, sys.argv[1:])
backups.mkdir(parents=True, exist_ok=True)
name = 'csv-studio-' + datetime.now(ZoneInfo('Europe/Rome')).strftime('%Y-%m-%d_%H-%M-%S-%f') + '.zip'
target = backups / name
with zipfile.ZipFile(target, 'w', zipfile.ZIP_DEFLATED) as archive:
    for path in sorted(data.rglob('*')):
        if path.is_file():
            archive.write(path, 'archive/' + str(path.relative_to(data)))
with zipfile.ZipFile(target) as archive:
    assert archive.testzip() is None
print('Backup creato: ' + str(target))
print('Scarica lo ZIP dal pannello file di Codespaces e conservalo sul tuo dispositivo.')
PY
