#!/usr/bin/env bash

mw_node_available() {
    command -v node >/dev/null 2>&1 && command -v npm >/dev/null 2>&1 &&
        node -e 'process.exit(Number(process.versions.node.split(".")[0]) >= 24 ? 0 : 1)' >/dev/null 2>&1
}

mw_ensure_node() {
    if mw_node_available; then return 0; fi

    local mw_node_dist mw_node_sha mw_node_dir mw_node_bin
    case "$(uname -s):$(uname -m)" in
        Linux:x86_64)
            mw_node_dist='node-v24.21.0-linux-x64'
            mw_node_sha='fd8e59d5a511510f6a298afb548f18c7d2b1be404d8b4a27d94fbe49f56cb2d6'
            ;;
        Linux:aarch64|Linux:arm64)
            mw_node_dist='node-v24.21.0-linux-arm64'
            mw_node_sha='6ad1325edbdb5649c379b75a237147a666c95d4f9ae8d340fef2d1575d289ad2'
            ;;
        *)
            printf '%s\n' 'Serve Node 24 o successivo. Il recupero automatico supporta Linux x64 e ARM64.' >&2
            return 1
            ;;
    esac
    mw_node_dir="$MW_REPO_DIR/var/tools/$mw_node_dist"

    # The devcontainer feature installs Node under NVM; restored shells may omit its PATH.
    for mw_node_bin in "$mw_node_dir/bin" \
        "${NVM_DIR:-/usr/local/share/nvm}/current/bin" \
        "${NVM_DIR:-/usr/local/share/nvm}"/versions/node/v24.*/bin; do
        if [[ -x "$mw_node_bin/node" && -x "$mw_node_bin/npm" ]]; then
            export PATH="$mw_node_bin:$PATH"
            if mw_node_available; then return 0; fi
        fi
    done

    printf '%s\n' 'Preparazione di Node 24 dal sito ufficiale…'
    mkdir -p "$MW_REPO_DIR/var/tools"
    (
        set -e
        local mw_node_temp
        mw_node_temp="$(mktemp -d "$MW_REPO_DIR/var/tools/.node-download-XXXXXX")"
        trap 'rm -rf -- "$mw_node_temp"' EXIT
        curl --fail --location --silent --show-error --retry 2 --connect-timeout 20 --max-time 180 \
            "https://nodejs.org/dist/v24.21.0/$mw_node_dist.tar.xz" \
            --output "$mw_node_temp/node.tar.xz"
        if ! printf '%s  %s\n' "$mw_node_sha" "$mw_node_temp/node.tar.xz" | sha256sum --check --status; then
            printf '%s\n' 'Verifica del download Node non riuscita. Installazione interrotta.' >&2
            exit 1
        fi
        tar -xJf "$mw_node_temp/node.tar.xz" -C "$mw_node_temp"
        if [[ -e "$mw_node_dir" ]]; then
            printf '%s\n' "Installazione Node locale incompleta: controlla $mw_node_dir." >&2
            exit 1
        fi
        mv -- "$mw_node_temp/$mw_node_dist" "$mw_node_dir"
    )
    export PATH="$mw_node_dir/bin:$PATH"
    mw_node_available
}

mw_ensure_uv() {
    if command -v uv >/dev/null 2>&1; then return 0; fi
    local mw_uv_dir="$MW_REPO_DIR/var/tools/uv"
    if [[ ! -x "$mw_uv_dir/bin/uv" ]]; then
        printf '%s\n' 'Preparazione del gestore delle dipendenze Python…'
        python3 -m venv "$mw_uv_dir"
        "$mw_uv_dir/bin/python" -m pip install --disable-pip-version-check 'uv==0.12.19'
    fi
    export PATH="$mw_uv_dir/bin:$PATH"
}
