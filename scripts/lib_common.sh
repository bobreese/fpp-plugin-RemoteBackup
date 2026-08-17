#!/bin/bash
# Shared helpers for fpp-plugin-RemoteBackup shell scripts.
# Source this file: . "$(dirname "$0")/lib_common.sh"

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DATA_DIR="${PLUGIN_DIR}/data"
SETTINGS_FILE="${DATA_DIR}/settings.json"
STATUS_DIR="${DATA_DIR}/status"
LOG_DIR="${DATA_DIR}/logs"
QUEUE_FILE="${DATA_DIR}/queue.json"
PIDS_DIR="${DATA_DIR}/pids"

mkdir -p "$STATUS_DIR" "$LOG_DIR" "$PIDS_DIR"
chmod -R 0777 "$DATA_DIR" 2>/dev/null || true

rb_log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "${LOG_DIR}/engine.log"
}

# setting <jq filter> [default]
rb_setting() {
    local filter="$1"
    local default="${2:-}"
    local val
    if [ ! -f "$SETTINGS_FILE" ]; then
        echo "$default"
        return
    fi
    val=$(jq -r "$filter // empty" "$SETTINGS_FILE" 2>/dev/null)
    if [ -z "$val" ]; then
        echo "$default"
    else
        echo "$val"
    fi
}

# Atomically write JSON to a status file for one remote.
# Usage: rb_write_status <remoteId> <json string>
rb_write_status() {
    local id="$1"
    local json="$2"
    local safe
    safe=$(echo "$id" | tr -c 'A-Za-z0-9._-' '_')
    echo "$json" > "${STATUS_DIR}/${safe}.json.tmp" && mv "${STATUS_DIR}/${safe}.json.tmp" "${STATUS_DIR}/${safe}.json"
}

rb_now_iso() {
    date -u '+%Y-%m-%dT%H:%M:%SZ'
}

# rb_dest_root <destinationMount>: resolves the directory backups actually
# live under, given the raw destinationMount setting.
#
# For every other storage choice (NVMe/SSD/USB, mounted at e.g.
# /mnt/Backups) this is just the mountpoint itself, trailing slash
# stripped. The "SD Card / System Storage (fallback)" choice is special:
# probe_storage.sh reports the true filesystem root "/" as that option's
# mountpoint (it's whatever the OS root sits on), and a plain "${mount%/}"
# strip collapses "/" to an empty string - every backup path then resolved
# to "/<id>-<date>", a write straight into the OS root that the fpp user
# has no permission for, surfacing as a confusing "could not create/write
# to target directory" error on every run. "/" itself was never a sane
# backup container anyway (it'd dump show backups in next to /etc, /var,
# etc.), so route that one case into a dedicated, fpp-writable folder that
# still lives on the same filesystem free-space reporting already covers.
RB_SDCARD_FALLBACK_DIR="/home/fpp/media/backups"
rb_dest_root() {
    local mount="$1"
    if [ "$mount" = "/" ]; then
        echo "$RB_SDCARD_FALLBACK_DIR"
    else
        echo "${mount%/}"
    fi
}

# Bytes -> human readable, used only for logging (UI does its own formatting)
rb_human_bytes() {
    numfmt --to=iec-i --suffix=B "$1" 2>/dev/null || echo "$1 bytes"
}
