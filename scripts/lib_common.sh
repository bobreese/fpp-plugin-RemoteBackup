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

# rb_remote_status_name <address>: queries a remote's own FPP web API for
# its current fppd status_name (e.g. "idle", "playing", "testing",
# "paused") - the same GET /api/system/status endpoint FPP's own UI polls.
# Prints nothing (not even a blank line) if the remote can't be reached,
# which callers treat as "unknown" rather than "playing" - an unrelated
# remote being offline shouldn't block a backup of every OTHER selected
# remote; that remote's own transfer already fails normally on its own.
rb_remote_status_name() {
    local addr="$1" urlhost
    case "$addr" in
        *:*) urlhost="[${addr}]" ;;
        *) urlhost="$addr" ;;
    esac
    curl -s --max-time 5 "http://${urlhost}/api/system/status" 2>/dev/null | jq -r '.status_name // empty' 2>/dev/null
}

# rb_host_addresses: this system's own local IP addresses, space-separated.
rb_host_addresses() {
    hostname -I 2>/dev/null
}

# rb_is_host_address <address>: true if the given address is one of this
# Host's own local IPs (or a loopback/localhost alias) - i.e. a configured
# "remote" at that address is actually this Host itself, not a separate
# system. Used by run_backup.sh to back it up as a local copy instead of
# an SSH pull, and by scripts/host_info.sh to back the same recognition
# in the Config page's remote list ("(Host)" label).
rb_is_host_address() {
    local addr="$1" ip
    case "$addr" in
        127.0.0.1 | ::1 | localhost) return 0 ;;
    esac
    for ip in $(rb_host_addresses); do
        [ "$ip" = "$addr" ] && return 0
    done
    return 1
}

# Bytes -> human readable, used only for logging (UI does its own formatting)
rb_human_bytes() {
    numfmt --to=iec-i --suffix=B "$1" 2>/dev/null || echo "$1 bytes"
}

# rb_parse_rsync_bytes <token>: converts a numeric token as printed by
# `rsync --stats` run with `-h` (human-readable, which run_backup.sh always
# passes so the raw log stays readable) into a plain integer byte count.
# Below ~1000 bytes -h prints comma-grouped plain digits (e.g. "5,242"),
# but past that it switches to a decimal + unit suffix (e.g. "5.24M",
# "610.44K", "1.02G") - a bare `grep -oE '[0-9,]+'` on that only ever
# grabbed the digits before the decimal point ("5" out of "5.24M"),
# silently truncating any multi-KB transfer down to a handful of bytes.
# That's why the dry-run summary's "Estimated total transfer" could read
# "0.00 MB" even when a real multi-megabyte transfer was estimated.
rb_parse_rsync_bytes() {
    local token="${1//,/}"
    case "$token" in
        *K) awk -v n="${token%K}" 'BEGIN { printf "%.0f", n * 1024 }' ;;
        *M) awk -v n="${token%M}" 'BEGIN { printf "%.0f", n * 1024 * 1024 }' ;;
        *G) awk -v n="${token%G}" 'BEGIN { printf "%.0f", n * 1024 * 1024 * 1024 }' ;;
        *T) awk -v n="${token%T}" 'BEGIN { printf "%.0f", n * 1024 * 1024 * 1024 * 1024 }' ;;
        *) echo "$token" ;;
    esac
}
