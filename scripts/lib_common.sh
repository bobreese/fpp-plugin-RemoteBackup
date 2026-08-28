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

# Second backup location, deliberately outside data/ (and outside this
# plugin's directory entirely) - see the matching comment on
# rb_settings_external_backup_path() in ajax.php for why: a real incident
# proved settings.json.bak living in data/ alongside settings.json isn't
# independent protection, since whatever wiped the live file wiped that
# backup right along with it (both live in the same directory). This
# fixed /home/fpp/media path is the same stable FPP media root
# RB_SDCARD_FALLBACK_DIR below already trusts.
SETTINGS_EXTERNAL_BACKUP="/home/fpp/media/.fpp-plugin-RemoteBackup-settings.bak"

# Self-heal settings.json if it exists but is empty/corrupt - observed
# cause: something outside this plugin entirely wipes data/ (or at least
# settings.json and its in-dir backup together) on a recurring basis, not
# a one-time fluke - two occurrences inside about an hour on a live
# system, each preceded by a multi-minute total gap in ajax.log (no
# requests logged at all). Every script sources this file before doing
# anything else, so this one check covers every entry point
# (run_backup.sh, the FPP Commands, every ajax.php action via its own
# PHP-side equivalent) rather than needing to be repeated per-script.
# Tries settings.json.bak first (kept in sync by rb_backup_settings_file()
# below and by ajax.php's own rb_save_settings()), then the external copy
# above, only if a backup is itself valid JSON; otherwise this
# deliberately does nothing further - it's not this check's job to invent
# defaults for a file that may simply never have existed yet.
if [ -f "$SETTINGS_FILE" ] && ! jq -e . "$SETTINGS_FILE" >/dev/null 2>&1; then
    if [ -f "${SETTINGS_FILE}.bak" ] && jq -e . "${SETTINGS_FILE}.bak" >/dev/null 2>&1; then
        cp "${SETTINGS_FILE}.bak" "$SETTINGS_FILE" 2>/dev/null
        rb_log "RECOVERED settings.json from settings.json.bak (live file was empty/corrupt)"
    elif [ -f "$SETTINGS_EXTERNAL_BACKUP" ] && jq -e . "$SETTINGS_EXTERNAL_BACKUP" >/dev/null 2>&1; then
        cp "$SETTINGS_EXTERNAL_BACKUP" "$SETTINGS_FILE" 2>/dev/null
        rb_log "RECOVERED settings.json from $SETTINGS_EXTERNAL_BACKUP (live file and its in-dir backup were both empty/corrupt)"
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
# settings.json to BOTH backup locations (settings.json.bak in data/, and
# the external SETTINGS_EXTERNAL_BACKUP copy above), called after every
# successful write below - the same two backups ajax.php's
# rb_save_settings()/rb_load_settings() maintain and recover from on the
# PHP side. See SETTINGS_EXTERNAL_BACKUP's own comment above for why
# there are two, not just one. Never allowed to fail the caller - it's a
# safety net, not the primary write.
rb_backup_settings_file() {
    local dest tmp
    for dest in "${SETTINGS_FILE}.bak" "$SETTINGS_EXTERNAL_BACKUP"; do
        tmp=$(mktemp "${dest}.tmp_XXXXXX" 2>/dev/null) || continue
        if cp "$SETTINGS_FILE" "$tmp" 2>/dev/null; then
            mv "$tmp" "$dest" 2>/dev/null
        else
            rm -f "$tmp"
        fi
    done
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

# Minimum free space (bytes) run_backup.sh's pre-flight space check always
# reserves when the destination is SD Card/System Storage ("/") - the one
# destination that shares its filesystem with FPP itself (its logs,
# database, and whatever sequence is actively playing), unlike a dedicated
# USB/NVMe/SSD backup drive. The plain "does the estimate fit in what's
# free" check that pre-flight otherwise does has no margin at all - right
# down to the last byte is fair game - which is fine for a dedicated drive
# (worst case, backups fail later) but is a real stability risk for the
# system's own root filesystem (observed in the wild: a run left the SD
# card with only ~10KB free). 500MB is deliberately not configurable -
# small enough to leave real backup capacity on any card FPP is actually
# supported on, without ever being tunable down to "none" by mistake.
RB_SDCARD_MIN_FREE_BYTES=524288000

# --- Optional bind mount: let remotes/File Manager see current backups on
# the primary drive without unmounting it - opt-in via the
# "enableRestoreBindMount" setting (default off). ---
#
# FPP's own restore ("Default FPP Storage", no specific remote device
# picked) pulls over rsync's daemon protocol from the Host's fixed
# media/backups path (RB_SDCARD_FALLBACK_DIR above) - a restricted/jailed
# module that can traverse a bind mount transparently but refuses to follow
# a symlink escaping its root (confirmed against a real restore failure - a
# symlink there looked fine in listings but silently failed every actual
# transfer). A bind mount doesn't have that problem: it makes
# RB_BIND_TARGET literally be the same underlying storage as
# RB_BIND_SOURCE, not a path that resolves elsewhere.
#
# Safety invariant this all depends on: the bind mount must exist if and
# only if (a) the toggle is on, (b) RB_BIND_SOURCE is the currently-SAVED
# destinationMount, (c) RB_BIND_SOURCE is actually mounted right now, AND
# (d) no REAL backup run is currently writing into it. Getting (a)-(c)
# wrong the dangerous way round - leaving the bind mount up after the
# destination was switched away from RB_BIND_SOURCE (e.g. to SD Card
# fallback, which is RB_SDCARD_FALLBACK_DIR too) - would make an SD-card-
# fallback backup silently write into the external drive instead, since
# both would resolve to the exact same path. Getting (d) wrong - leaving it
# up WHILE a run is actively writing - would let FPP's native restore (or a
# remote's own File Copy Backup/Restore) read a torn, mid-write snapshot of
# the backup content: rsync's own per-file temp-then-rename means no single
# file is ever seen half-written, but the directory as a whole can still
# show a mix of this run's and the previous run's files depending on
# exactly when it's read - this is the actual, concrete way a restore could
# read something the backup process didn't intend, i.e. corrupt/incoherent
# data reaching the restore target. rb_bindmount_backups_ensure re-checks
# all four every time rather than trusting any cached state, and every call
# site that changes destinationMount, the toggle, or run-active state
# (ajax.php's saveSettings/useFailover/useDestination, and run_backup.sh's
# own start/end/exit-trap below) calls it again afterward.
RB_BIND_SOURCE="/mnt/Backups"
RB_BIND_TARGET="$RB_SDCARD_FALLBACK_DIR"

# True only if RB_BIND_TARGET itself (not some ancestor directory) is
# currently a mountpoint - i.e. our bind mount (or something else's) is
# actively bound there right now.
rb_bindmount_is_active() {
    local actual_mp
    actual_mp=$(findmnt -n -o TARGET --target "$RB_BIND_TARGET" 2>/dev/null)
    [ -n "$actual_mp" ] && [ "$actual_mp" = "$RB_BIND_TARGET" ]
}

# True only while a REAL (non-dry-run) backup run is actively writing -
# read from run_active.json, the same flag ajax.php's status poll already
# shows on Status/Config as "a run is active". A dry run never writes
# anything to the destination at all, so it's deliberately excluded here -
# there's nothing for a concurrent restore to read incoherently during one.
#
# run_active.json's "active" flag alone has no staleness/liveness check -
# it's just a display flag (see run_backup.sh's own comment on why run.lock,
# not this file, is the authoritative "is a run really happening" signal).
# A run that was killed, crashed, or lost power before this safeguard
# existed (or before some future edge case the exit trap doesn't cover) can
# leave it stuck showing "active" forever - which, once this flag started
# also gating the bind mount, would otherwise leave a real restore
# permanently and silently blocked, not just temporarily paused. Bug
# reported in the wild: enabling the toggle showed nothing in File Manager
# or a remote's restore list, with no backup actually running.
#
# Corroborate against run.lock's actual hold state before trusting the
# flag: try to acquire it ourselves, non-blocking, on a throwaway fd. If we
# succeed, nobody real holds it - the flag is stale; release immediately
# (this is a probe, not a real acquisition) and report "not active"
# regardless of what the JSON says. Safe to call from within run_backup.sh
# itself too: while it holds the lock on fd 9, this probe on fd 8 always
# fails to acquire (flock treats a second open file description on the
# same path, even from the same process, as a separate lock attempt), so
# it correctly reports "active" for its own in-progress run - the flag
# only ever gets trusted as "false" from run_backup.sh's own writes, which
# are never stale to begin with (it's writing about itself, live).
rb_real_run_active() {
    local f="${DATA_DIR}/run_active.json"
    [ -f "$f" ] || return 1
    local active dry
    active=$(jq -r '.active // false' "$f" 2>/dev/null)
    [ "$active" = "true" ] || return 1
    dry=$(jq -r '.dryRun // false' "$f" 2>/dev/null)
    [ "$dry" = "true" ] && return 1

    exec 8>"${DATA_DIR}/run.lock" 2>/dev/null
    if flock -n 8 2>/dev/null; then
        flock -u 8 2>/dev/null
        exec 8>&- 2>/dev/null
        return 1
    fi
    exec 8>&- 2>/dev/null
    return 0
}

# rb_bindmount_backups_ensure: (re)establishes the bind mount if the safety
# invariant above holds, tears it down if it doesn't - this is what makes
# it double as a safeguard against a concurrent restore reading corrupt/
# incoherent in-progress backup data, not just a destination-mismatch
# guard. Idempotent and safe to call unconditionally any time settings,
# mount state, or run-active state might have changed - does nothing if the
# bind mount is already in the correct state.
rb_bindmount_backups_ensure() {
    local enabled dest
    enabled=$(rb_setting '.enableRestoreBindMount' 'false')
    dest=$(rb_setting '.destinationMount' '')
    if [ "$enabled" != "true" ] || [ "$dest" != "$RB_BIND_SOURCE" ] || ! mountpoint -q "$RB_BIND_SOURCE" 2>/dev/null || rb_real_run_active; then
        rb_bindmount_backups_teardown
        return 0
    fi
    if rb_bindmount_is_active; then
        return 0
    fi
    sudo mkdir -p "$RB_BIND_TARGET" 2>/dev/null
    if sudo mount --bind "$RB_BIND_SOURCE" "$RB_BIND_TARGET" 2>/tmp/rb_bindmount_err_$$; then
        rm -f /tmp/rb_bindmount_err_$$
        rb_log "bindmount: bound $RB_BIND_SOURCE -> $RB_BIND_TARGET"
    else
        rb_log "bindmount FAILED: $RB_BIND_SOURCE -> $RB_BIND_TARGET : $(cat /tmp/rb_bindmount_err_$$ 2>/dev/null)"
        rm -f /tmp/rb_bindmount_err_$$
    fi
}

# rb_bindmount_backups_teardown: unbinds RB_BIND_TARGET if (and only if)
# it's currently bound - a no-op otherwise, regardless of the toggle. Called
# unconditionally right before every real unmount/reformat of RB_BIND_SOURCE
# so the bind mount never outlives the device it points at.
rb_bindmount_backups_teardown() {
    if rb_bindmount_is_active; then
        if sudo umount "$RB_BIND_TARGET" 2>/tmp/rb_bindunmount_err_$$; then
            rm -f /tmp/rb_bindunmount_err_$$
            rb_log "bindmount: unbound $RB_BIND_TARGET"
        else
            rb_log "bindmount teardown FAILED for $RB_BIND_TARGET : $(cat /tmp/rb_bindunmount_err_$$ 2>/dev/null)"
            rm -f /tmp/rb_bindunmount_err_$$
        fi
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
#
# Wrapped in its own flock (known_hosts.lock) because run_backup.sh runs
# multiple remotes' backup_one() calls concurrently (bounded by Config's
# max concurrent transfers), and each one reaches this function before
# connecting. ssh-keygen -R edits known_hosts by writing a fresh temp file
# and backing up the original as known_hosts.old, then renaming the temp
# file into place - two invocations racing on the same file step on each
# other's backup/rename, and the loser's temp file (named
# known_hosts.<random>) is left behind rather than cleaned up. Confirmed
# in the wild: dozens of orphaned known_hosts.<random> files accumulating
# in ~/.ssh, clustered at timestamps where several remotes' runs
# overlapped. Blocking (not -n): a caller that lost the race should wait
# its turn, not skip the clear - skipping it reintroduces the exact
# stale-host-key failure this function exists to prevent.
rb_clear_stale_host_key() {
    local addr="$1" port="${2:-22}"
    (
        flock 7
        ssh-keygen -R "$addr" >/dev/null 2>&1
        if [ -n "$port" ] && [ "$port" != "22" ]; then
            ssh-keygen -R "[${addr}]:${port}" >/dev/null 2>&1
        fi
    ) 7>"${DATA_DIR}/known_hosts.lock"
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
