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

# Self-heal settings.json if it exists but is empty/corrupt - observed
# cause: something outside this plugin entirely (an OS/FPP update
# restarting the web server mid-write, in one real incident) truncated it
# to 0 bytes, and every script/request from then on just silently ran on
# empty/default values with nothing ever repairing it. Every script
# sources this file before doing anything else, so this one check covers
# every entry point (run_backup.sh, the FPP Commands, every ajax.php
# action via its own PHP-side equivalent) rather than needing to be
# repeated per-script. Restores from settings.json.bak - kept in sync by
# rb_backup_settings_file() below and by ajax.php's own
# rb_save_settings() - only if that backup itself is valid JSON;
# otherwise this deliberately does nothing; it's not this check's job to
# invent defaults for a file that may simply never have existed yet.
if [ -f "$SETTINGS_FILE" ] && ! jq -e . "$SETTINGS_FILE" >/dev/null 2>&1; then
    if [ -f "${SETTINGS_FILE}.bak" ] && jq -e . "${SETTINGS_FILE}.bak" >/dev/null 2>&1; then
        cp "${SETTINGS_FILE}.bak" "$SETTINGS_FILE" 2>/dev/null
        rb_log "RECOVERED settings.json from settings.json.bak (live file was empty/corrupt)"
    fi
fi

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

# rb_backup_settings_file: best-effort mirror of the just-written
# settings.json to settings.json.bak, called after every successful write
# below - the same backup ajax.php's rb_save_settings()/rb_load_settings()
# maintain and recover from on the PHP side (see the comment on
# rb_settings_backup_path() there for why this exists: settings.json has
# been observed truncated to 0 bytes by something outside this plugin
# entirely, e.g. an OS/FPP update restarting the web server mid-write,
# with no way back short of re-entering every setting by hand). Never
# allowed to fail the caller - it's a safety net, not the primary write.
rb_backup_settings_file() {
    local tmp
    tmp=$(mktemp "${SETTINGS_FILE}.bak.tmp_XXXXXX") || return 0
    if cp "$SETTINGS_FILE" "$tmp" 2>/dev/null; then
        mv "$tmp" "${SETTINGS_FILE}.bak" 2>/dev/null
    else
        rm -f "$tmp"
    fi
}

# rb_set_setting <jq path> <string value>: updates a single key in
# settings.json in place (read, modify, write as one atomic mv). Used
# sparingly, only where a script needs to persist a state change on its own
# authority (e.g. auto-failover switching the destination) rather than
# through the UI's saveSettings - e.g. rb_set_setting '.destinationMount' '/'.
# Value is always written as a JSON string; not meant for numbers/bools/null.
rb_set_setting() {
    local path="$1" value="$2" tmp
    [ -f "$SETTINGS_FILE" ] || return 1
    tmp=$(mktemp "${SETTINGS_FILE}.tmp_XXXXXX")
    if jq --arg v "$value" "${path} = \$v" "$SETTINGS_FILE" > "$tmp" 2>/dev/null; then
        mv "$tmp" "$SETTINGS_FILE"
        rb_backup_settings_file
    else
        rm -f "$tmp"
        return 1
    fi
}

# rb_set_setting_json <jq path> <raw JSON value>: like rb_set_setting above,
# but for a JSON value (object/array/bool/number) instead of a plain
# string - e.g. rb_set_setting_json '.lastScheduledPlayOutcome'
# '{"policy":"skip","refused":false,...}'. The caller is responsible for
# producing valid JSON (e.g. via jq -n); this does not quote or escape it.
rb_set_setting_json() {
    local path="$1" value="$2" tmp
    [ -f "$SETTINGS_FILE" ] || return 1
    tmp=$(mktemp "${SETTINGS_FILE}.tmp_XXXXXX")
    if jq --argjson v "$value" "${path} = \$v" "$SETTINGS_FILE" > "$tmp" 2>/dev/null; then
        mv "$tmp" "$SETTINGS_FILE"
        rb_backup_settings_file
    else
        rm -f "$tmp"
        return 1
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

# rb_prune_remote_logs <remoteId> [keep]: keeps only the newest KEEP
# per-remote run log files (data/logs/<id>-<runId>.log) and deletes the
# rest. Called once per remote at the end of its run (real or dry-run
# alike) by run_backup.sh, and also from prune_logs.sh to apply a changed
# retention count immediately rather than waiting for each remote's next
# run. KEEP defaults to the configured logRetentionCount setting (itself
# defaulting to 15) when not passed explicitly. Nothing else ever reads
# an old run log (the UI's "view log" always opens the newest match for a
# given remote, see ajax.php's getLog), so older copies are pure
# disk-space dead weight otherwise. Filenames sort chronologically as
# plain strings since runId is YYYYMMDD-HHMMSS, so a lexical sort (not
# mtime) determines newest-first - robust even if a file's mtime were
# ever touched independently of its name.
rb_prune_remote_logs() {
    local rid="$1" keep="${2:-}"
    [ -z "$keep" ] && keep=$(rb_setting '.logRetentionCount' '15')
    local files=()
    while IFS= read -r f; do
        [ -n "$f" ] && files+=("$f")
    done < <(cd "$LOG_DIR" 2>/dev/null && ls -1 -- "${rid}-"*.log 2>/dev/null | sort -r)
    local i=0
    for f in "${files[@]}"; do
        i=$((i + 1))
        if [ "$i" -gt "$keep" ]; then
            rm -f "${LOG_DIR}/${f}"
        fi
    done
}

# rb_clear_stale_host_key <address> [port]: drops any existing SSH
# known_hosts entry for a remote before connecting. All of this plugin's
# ssh/rsync calls use StrictHostKeyChecking=accept-new, which only
# auto-trusts a host it has NEVER seen before - a remote that's been
# reimaged/rebuilt (new SSH host keys generated, but the same IP or
# hostname) instead hard-fails every connection attempt with "REMOTE
# HOST IDENTIFICATION HAS CHANGED", a failure that has nothing to do
# with credentials, so no amount of retrying "Push SSH Key" with a
# password ever fixes it. Reimaging a remote and using "Push SSH Key"
# (or a scheduled backup) to re-trust it afterward is exactly the
# situation this plugin exists to support, so clearing the stale entry
# here is the intended, expected outcome rather than a MITM downgrade -
# the real host identity is still learned fresh on this very connection,
# same as it would be for a remote never seen before.
rb_clear_stale_host_key() {
    local addr="$1" port="${2:-22}"
    ssh-keygen -R "$addr" >/dev/null 2>&1
    if [ -n "$port" ] && [ "$port" != "22" ]; then
        ssh-keygen -R "[${addr}]:${port}" >/dev/null 2>&1
    fi
    return 0
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
