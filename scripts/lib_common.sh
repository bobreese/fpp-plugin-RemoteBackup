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

# Bytes -> human readable, used only for logging (UI does its own formatting)
rb_human_bytes() {
    numfmt --to=iec-i --suffix=B "$1" 2>/dev/null || echo "$1 bytes"
}
