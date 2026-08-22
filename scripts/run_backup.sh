#!/bin/bash
# Core Remote Backup engine.
#
# Usage:
#   run_backup.sh [--dry-run] [--remotes host1,host2,...] [--foreground] [--skip-space-check]
#
# --skip-space-check bypasses the pre-flight free-space estimate a real run
# otherwise always does first (see the "Pre-flight space check" block below) -
# used when the caller has already seen that same warning and explicitly
# chose to proceed anyway.
#
# With no --remotes, every remote marked "selected": true in
# data/settings.json is backed up. Up to `maxConcurrent` (default 2)
# rsync pulls run at once; as each finishes the next queued remote is
# started, per the "start first 2, then backfill" requirement.
#
# Each remote gets a live JSON status file at data/status/<id>.json that
# the UI polls, and a full rsync log at data/logs/<id>-<run>.log.

. "$(dirname "$0")/lib_common.sh"

DRYRUN=0
REMOTE_FILTER=""
FOREGROUND=0
SKIP_SPACE_CHECK=0
RUN_ID=$(date '+%Y%m%d-%H%M%S')

while [ $# -gt 0 ]; do
    case "$1" in
        --dry-run) DRYRUN=1 ;;
        --remotes) shift; REMOTE_FILTER="$1" ;;
        --foreground) FOREGROUND=1 ;;
        --skip-space-check) SKIP_SPACE_CHECK=1 ;;
    esac
    shift
done

# --- Refuse to start a second run while one is already in progress ------
# The UI's ajax.php 'start' action already checks run_active.json before
# launching this script, but that's a plain read-then-launch with a real
# race: two near-simultaneous starts (e.g. a Scheduler-triggered run and a
# manual click, or two Scheduler entries too close together) can both pass
# that check before either process gets far enough to write active:true
# itself. It's also the ONLY guard for anything that invokes this script
# directly - commands/run_remote_backup*.sh (FPP's Scheduler) and any
# manual/cron invocation bypass ajax.php entirely.
#
# flock is kernel-enforced and atomic, and - unlike a hand-rolled JSON
# flag - can never get stuck "held" by a process that crashed, was killed,
# or lost power mid-run: the lock releases the instant this script's file
# descriptor closes, no matter how it exits. This is the authoritative
# guard; run_active.json remains just the UI's "is something running"
# display flag, still written further below exactly as before.
LOCK_FILE="${DATA_DIR}/run.lock"
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    rb_log "ABORT: refusing to start - another Remote Backup run is already in progress (run.lock is held by another process). Check the Status page, or Stop the current run first."
    echo "A Remote Backup run is already in progress. Check the Status page (data/logs/engine.log has the detail), or Stop the current run first." >&2
    exit 1
fi

# One-time sweep of any tmp_extras_<id>_* scratch directory left behind
# by an earlier run - this run hasn't created one yet (that happens
# per-remote, further below), so anything matching here is leftover debris.
# Normally these are removed at the end of each remote's own run; before
# the fix that made that cleanup use sudo, a Host-local run with "Include
# system config" enabled could leave root-owned entries behind that a
# plain rm couldn't remove. sudo here cleans those up too, not just
# ordinary leftovers from some other interruption (a kill -9, a power
# loss mid-run, etc).
find "$DATA_DIR" -maxdepth 1 -type d -name 'tmp_extras_*' -exec sudo rm -rf {} + 2>/dev/null

if [ ! -f "$SETTINGS_FILE" ]; then
    echo "No settings.json found; configure the plugin first." >&2
    exit 1
fi

# --- Refuse to start while backups are halted -----------------------------
# Set by the UI (config.php/status.php) when a previously-active destination
# drive is detected missing and the user picks "Halt backups" from the
# resulting popup, rather than switching to the SD Card/System Storage
# failover. haltedReason is cleared automatically the moment the 'status'
# poll (ajax.php) sees the configured destination mounted again, or the
# instant a new destinationMount is saved/activated - so this only ever
# blocks a run while the situation it was raised for is still unresolved.
HALTED_REASON=$(rb_setting '.haltedReason')
if [ -n "$HALTED_REASON" ]; then
    rb_log "ABORT: refusing to start - backups are halted: $HALTED_REASON"
    echo "A Remote Backup run was refused: backups are halted - $HALTED_REASON. Resolve it on the Config or Status page (pick a destination, or use the failover) before the next run." >&2
    exit 1
fi

DEST_MOUNT=$(rb_setting '.destinationMount')
if [ -z "$DEST_MOUNT" ] || [ ! -d "$DEST_MOUNT" ]; then
    echo "Destination storage is not configured or not mounted: '$DEST_MOUNT'" >&2
    exit 1
fi

# rb_dest_mounted: true if $DEST_MOUNT is a directory AND (when the
# mountpoint(1) tool exists) actually still an active mount, not just an
# empty directory left behind after the drive was unplugged/unmounted.
# Checked once up front and again per-remote, since a format/reformat or
# a physical disconnect can drop the mount mid-run.
rb_dest_mounted() {
    [ -d "$DEST_MOUNT" ] || return 1
    if [ "$DEST_MOUNT" != "/" ] && command -v mountpoint >/dev/null 2>&1; then
        mountpoint -q "$DEST_MOUNT" || return 1
    fi
    return 0
}

if ! rb_dest_mounted; then
    echo "Destination storage '$DEST_MOUNT' is not currently mounted (drive may have been unplugged, unmounted, or is mid-format). Re-check Config > Storage." >&2
    exit 1
fi

MAX_CONCURRENT=$(rb_setting '.maxConcurrent' '2')
DELETE_EXTRA=$(rb_setting '.deleteExtraneous' 'false')
SNAPSHOT_MODE=$(rb_setting '.snapshotMode' 'false')
SSH_USER=$(rb_setting '.sshUser' 'fpp')
SSH_PORT=$(rb_setting '.sshPort' '22')
SSH_KEY=$(rb_setting '.sshKeyPath' '/home/fpp/.ssh/id_rsa_remotebackup')
# Backups go directly under the destination mount (previously nested
# one level deeper under a "RemoteBackup" subfolder - flattened per
# request, since this mount is meant to be dedicated to this plugin
# anyway). mkdir -p is a no-op here since the mount already exists,
# kept only for the case DEST_MOUNT ends up freshly created.
DEST_ROOT="$(rb_dest_root "$DEST_MOUNT")"
mkdir -p "$DEST_ROOT"

# --- Extras: FPP logs (wherever they really live) + optional system/  ---
# --- network config. Best-effort - never flips a remote's overall     ---
# --- state to error, only the main /home/fpp/media pull below does.   ---
INCLUDE_SYSTEM_CONFIG=$(rb_setting '.includeSystemConfig' 'true')
# FPP itself has no "full system backup" to match against - its only
# native backup is a small JSON settings export (no media, no logs).
# These are the well-known system-level locations that live entirely
# outside /home/fpp/media and so can never be reached by the main pull
# regardless of excludes: hardware/cape config, and whichever network
# stack this remote's platform actually uses (Raspbian dhcpcd, Debian
# ifupdown, Ubuntu netplan, or wpa_supplicant directly). Paths that
# don't exist on a given remote are silently skipped, not an error.
SYSTEM_CONFIG_PATHS=(/etc/fpp /etc/hostname /etc/hosts /etc/timezone /etc/network /etc/wpa_supplicant /etc/dhcpcd.conf /etc/netplan)

# rb_prune_remote_logs (per-remote run log retention) now lives in
# lib_common.sh, shared with prune_logs.sh - see there for the full
# comment.

# rb_resolve_remote_setting: reads a live setting value directly from a
# remote's own FPP web API (the same http://<host>/api/settings/<name>
# call FPP's own backup page uses internally to read a remote's
# settings). Used to find where a remote is *actually* writing its logs,
# since logDirectory defaults to <mediaDirectory>/logs but is commonly
# overridden to a tmpfs/RAM location to spare SD card wear - when that
# happens the real log files live somewhere the main media pull never
# looks. Prints nothing (not even a blank line) if the remote can't be
# reached or the setting is unknown, which callers treat as "skip".
rb_resolve_remote_setting() {
    local addr="$1" name="$2" urlhost
    case "$addr" in
        *:*) urlhost="[${addr}]" ;;
        *) urlhost="$addr" ;;
    esac
    curl -s --max-time 5 "http://${urlhost}/api/settings/${name}" 2>/dev/null | jq -r '.value // empty' 2>/dev/null
}

# system-config.tar.gz and system-logs.tar.gz are files this plugin
# creates itself (the extras step, further down) - they are never part
# of the remote's /home/fpp/media tree, so the main pull below must
# never consider them for deletion. Without this, --delete (mirror
# deletes) sees them as "extraneous" content not present in the source
# and wipes them out on every run, right before the extras step tries
# to repopulate them - always one run behind, and confusing to read in
# the transfer log ("deleting system-config.tar.gz").
#
# These are packaged as single .tar.gz FILES rather than left as plain
# directories on purpose: FPP's own "Restore from USB" / File Copy
# Restore device browser (GetAvailableBackupsFromDir() in FPP's
# www/api/controllers/backups.php) naively lists ANY subdirectory one
# level inside a backup folder as its own separately-selectable
# "backup" unless the name happens to match something already in the
# local mediaDirectory - it has no concept of a plugin's own metadata
# folders. Real media content (Sequences/, Playlists/, etc.) is
# excluded automatically because those names already exist locally;
# system-config/ and system-logs/ don't, so they leaked through as
# bogus, confusing, non-restorable entries (e.g.
# "FPPbackup-20260804/system-config") in FPP's own restore dropdown.
# FPP's scanner only pushes entries where is_dir() is true, so shipping
# these as archive files instead makes them invisible to it entirely -
# no naming coincidence required, and it is not something we can fix in
# FPP's code from here.
EXCLUDE_ARGS=(--exclude=system-config.tar.gz --exclude=system-logs.tar.gz)
while IFS= read -r pat; do
    [ -n "$pat" ] && EXCLUDE_ARGS+=(--exclude="$pat")
done < <(jq -r '.excludes[]? // empty' "$SETTINGS_FILE" 2>/dev/null)

# Build the list of remotes to process (as compact JSON, one per line)
if [ -n "$REMOTE_FILTER" ]; then
    IFS=',' read -ra WANT <<< "$REMOTE_FILTER"
    WANT_JSON=$(printf '%s\n' "${WANT[@]}" | jq -R . | jq -s .)
    REMOTES_JSON=$(jq -c --argjson want "$WANT_JSON" '[.remotes[] | select(.id as $i | $want | index($i))]' "$SETTINGS_FILE")
else
    REMOTES_JSON=$(jq -c '[.remotes[] | select(.selected == true)]' "$SETTINGS_FILE")
fi

COUNT=$(echo "$REMOTES_JSON" | jq 'length')
if [ "$COUNT" -eq 0 ]; then
    echo "No remotes selected." >&2
    exit 1
fi

# --- Refuse to start if any selected remote is actively playing a show --
# Pulling media off a device's SD card while its own fppd is actively
# reading those same files for playback risks stutters/dropped frames
# during a live show - so before touching anything, ask every selected
# remote's own FPP API whether it's currently playing, and abort the
# WHOLE run (not just the busy remote) if any of them are. This is the
# authoritative guard - ajax.php's 'start' action does the same check
# itself first purely for immediate UI feedback, but every other way to
# reach this script (a Scheduler entry, a manual/cron run) only ever goes
# through here. Queried in parallel, same reasoning as
# check_remotes_playing.sh: bounded by one curl timeout, not N of them.
# A remote that can't be reached is NOT treated as "playing" - that's the
# same "can't reach it" case backup_one() already handles per-remote
# further down, and refusing the whole run over an unrelated remote being
# offline would be worse than just letting its own transfer fail normally.
PLAYCHECK_DIR=$(mktemp -d "${DATA_DIR}/tmp_playcheck_XXXXXX")
PLAYCHECK_LIST=$(mktemp "${DATA_DIR}/tmp_playcheck_list_XXXXXX")
echo "$REMOTES_JSON" | jq -c '.[]' > "$PLAYCHECK_LIST"
# Reads from a file, NOT a pipe: `cmd | while read; do ... & done` runs the
# loop in a subshell (every stage of a pipeline does, without `shopt -s
# lastpipe`), so a `wait` afterwards - in the calling shell - would not
# actually be waiting for jobs that subshell backgrounded; some could
# still be mid-request when PLAYING_REMOTES gets read below. `< file`
# keeps the loop (and everything it backgrounds) in this same shell.
while IFS= read -r r; do
    (
        pc_id=$(echo "$r" | jq -r '.id')
        pc_hostname=$(echo "$r" | jq -r '.hostname')
        pc_address=$(echo "$r" | jq -r '.address')
        pc_status=$(rb_remote_status_name "$pc_address")
        if [ "$pc_status" = "playing" ]; then
            echo "$pc_hostname ($pc_address)" > "${PLAYCHECK_DIR}/${pc_id}"
        fi
    ) &
done < "$PLAYCHECK_LIST"
wait
PLAYING_REMOTES=$(cat "${PLAYCHECK_DIR}"/* 2>/dev/null)
rm -rf "$PLAYCHECK_DIR"
rm -f "$PLAYCHECK_LIST"
if [ -n "$PLAYING_REMOTES" ]; then
    reason="refusing to start - currently playing a sequence: $(echo "$PLAYING_REMOTES" | tr '\n' ',' | sed 's/,$//' | sed 's/,/, /g')"
    rb_log "ABORT: $reason"
    echo "A Remote Backup run was refused: $reason" >&2
    exit 1
fi

# estimate_one <remote_json>: prints one remote's estimated transfer size in
# bytes to stdout. Mirrors backup_one() below's own target/snapshot/
# existing-backup resolution (kept as a deliberate small duplication rather
# than a shared helper, so this read-only estimate pass can never risk
# touching backup_one()'s real-run behavior) so the estimate correctly
# credits already-existing files via --link-dest instead of a cruder guess -
# the same rsync --dry-run + --stats pass a real Dry Run does. Writes no
# status.json entry and no data/logs/ file of its own; its rsync output goes
# to a throwaway scratch file, since it exists purely to produce one number,
# not to be inspected like a real per-remote run.
estimate_one() {
    local remote_json="$1"
    local id address today target existing prev is_host src rsync_host
    local extra=() scratch_out bytes ssh_cmd

    id=$(echo "$remote_json" | jq -r '.id')
    address=$(echo "$remote_json" | jq -r '.address')
    today=$(date '+%Y%m%d')
    ssh_cmd="ssh -i ${SSH_KEY} -p ${SSH_PORT} -o StrictHostKeyChecking=accept-new -o ConnectTimeout=10 -o BatchMode=yes"

    is_host=0
    rb_is_host_address "$address" && is_host=1
    if [ "$is_host" = "1" ]; then
        src="/home/fpp/media/"
    else
        rsync_host="$address"
        case "$address" in *:*) rsync_host="[${address}]" ;; esac
        src="${SSH_USER}@${rsync_host}:/home/fpp/media/"
    fi

    if [ "$SNAPSHOT_MODE" = "true" ]; then
        target="${DEST_ROOT}/${id}-${today}"
        prev=$(find "$DEST_ROOT" -maxdepth 1 -mindepth 1 -type d -name "${id}-*" ! -name "$(basename "$target")" 2>/dev/null | sort | tail -1)
        [ -n "$prev" ] && extra+=(--link-dest="$prev")
    else
        existing=$(find "$DEST_ROOT" -maxdepth 1 -mindepth 1 -type d -name "${id}-*" ! -name "${id}-${today}" 2>/dev/null | sort | tail -1)
        target="${existing:-${DEST_ROOT}/${id}-${today}}"
    fi

    scratch_out=$(mktemp "${DATA_DIR}/tmp_estimate_XXXXXX")
    if [ "$is_host" = "1" ]; then
        rsync -a -n --stats --copy-links "${EXCLUDE_ARGS[@]}" "${extra[@]}" "$src" "${target}/" > "$scratch_out" 2>&1
    else
        rsync -a -n --stats --copy-links -e "$ssh_cmd" "${EXCLUDE_ARGS[@]}" "${extra[@]}" "$src" "${target}/" > "$scratch_out" 2>&1
    fi
    bytes=$(rb_parse_rsync_bytes "$(grep -m1 '^Total transferred file size:' "$scratch_out" | grep -oE '[0-9][0-9,]*(\.[0-9]+)?[KMGT]?' | head -1)")
    rm -f "$scratch_out"
    echo "${bytes:-0}"
}

# --- Pre-flight space check (real runs only) ------------------------------
# Estimates total transfer size across every selected remote and compares it
# to the destination's current free space, before committing to a real run.
# This is the one place BOTH a manual Start Backup click and a
# Scheduler-triggered run always go through (same reasoning as the "remotes
# playing" guard above), so it's the only guard that can actually cover the
# unattended case - which has nobody to ask, so it refuses outright by
# default. autoFailoverOnLowSpace (off by default) switches the destination
# to SD Card/System Storage automatically instead of refusing, for those who
# want a scheduled run to always complete somewhere rather than being
# skipped. Skipped entirely for an actual dry run (nothing to protect - it
# never writes anything) and for --skip-space-check (the UI's "Start Anyway"
# override, once a human has already seen this same warning). Placed here,
# before run_active.json is ever set true (same reasoning as the "remotes
# playing" guard above it) - a refusal below exits before that write, so it
# can never leave the UI showing a stuck "active" run that already exited.
if [ "$DRYRUN" != "1" ] && [ "$SKIP_SPACE_CHECK" != "1" ]; then
    ESTIMATE_TOTAL=0
    ESTIMATE_LIST=$(mktemp "${DATA_DIR}/tmp_estimate_list_XXXXXX")
    echo "$REMOTES_JSON" | jq -c '.[]' > "$ESTIMATE_LIST"
    while IFS= read -r remote_json; do
        one_bytes=$(estimate_one "$remote_json")
        ESTIMATE_TOTAL=$((ESTIMATE_TOTAL + one_bytes))
    done < "$ESTIMATE_LIST"
    rm -f "$ESTIMATE_LIST"

    AVAILABLE_NOW=$(df -B1 --output=avail "$DEST_MOUNT" 2>/dev/null | tail -1 | tr -d ' ')
    [ -z "$AVAILABLE_NOW" ] && AVAILABLE_NOW=0

    if [ "$ESTIMATE_TOTAL" -gt "$AVAILABLE_NOW" ]; then
        AUTO_FAILOVER=$(rb_setting '.autoFailoverOnLowSpace' 'false')
        if [ "$AUTO_FAILOVER" = "true" ] && [ "$DEST_MOUNT" != "/" ]; then
            rb_log "LOW SPACE: estimated $(rb_human_bytes "$ESTIMATE_TOTAL") needed, $(rb_human_bytes "$AVAILABLE_NOW") available on '$DEST_MOUNT' - autoFailoverOnLowSpace is on, switching destination to SD Card / System Storage ('/') and continuing"
            rb_set_setting '.destinationMount' '/'
            DEST_MOUNT="/"
            DEST_ROOT="$(rb_dest_root "$DEST_MOUNT")"
            mkdir -p "$DEST_ROOT"
        else
            reason="estimated transfer (~$(rb_human_bytes "$ESTIMATE_TOTAL")) exceeds free space on '$DEST_MOUNT' (~$(rb_human_bytes "$AVAILABLE_NOW") available)"
            rb_log "ABORT: refusing to start - $reason"
            rb_set_setting '.lowSpaceReason' "$reason"
            jq --argjson e "$ESTIMATE_TOTAL" --argjson a "$AVAILABLE_NOW" '.lowSpaceEstimatedBytes = $e | .lowSpaceAvailableBytes = $a' \
                "$SETTINGS_FILE" > "${SETTINGS_FILE}.tmp_lowspace" 2>/dev/null && mv "${SETTINGS_FILE}.tmp_lowspace" "$SETTINGS_FILE"
            echo "A Remote Backup run was refused: $reason" >&2
            exit 1
        fi
    elif [ -n "$(rb_setting '.lowSpaceReason')" ]; then
        # This attempt has enough room - clear a stale refusal from an
        # earlier attempt so the UI's popup doesn't keep reappearing for a
        # situation that's already resolved.
        rb_set_setting '.lowSpaceReason' ""
    fi
fi

rb_log "=== run start (dryRun=$DRYRUN runId=$RUN_ID remotes=$COUNT) ==="

# Pre-write "queued" status for every remote so the UI shows the full
# list immediately, even for ones waiting on the concurrency limit.
echo "$REMOTES_JSON" | jq -c '.[]' | while read -r r; do
    id=$(echo "$r" | jq -r '.id')
    hostname=$(echo "$r" | jq -r '.hostname')
    address=$(echo "$r" | jq -r '.address')
    rb_write_status "$id" "$(jq -n --arg id "$id" --arg hostname "$hostname" --arg address "$address" --arg run "$RUN_ID" --arg dryrun "$DRYRUN" --arg t "$(rb_now_iso)" \
        '{id:$id, hostname:$hostname, address:$address, state:"queued", dryRun:($dryrun=="1"), runId:$run, queuedAt:$t}')"
done
echo '{"active": true}' > "${DATA_DIR}/run_active.json"

backup_one() {
    local remote_json="$1"
    local id hostname address today target existing prev linkdest_opt=() extra=() logfile target_ok is_host host_exclude=()

    id=$(echo "$remote_json" | jq -r '.id')
    hostname=$(echo "$remote_json" | jq -r '.hostname')
    address=$(echo "$remote_json" | jq -r '.address')
    today=$(date '+%Y%m%d')
    logfile="${LOG_DIR}/${id}-${RUN_ID}.log"

    # A selected "remote" can actually be this Host itself - MultiSync's
    # own system list can include it, or someone adds it manually - see
    # rb_is_host_address() in lib_common.sh. Backed up as a local copy
    # further down instead of an SSH pull to itself.
    is_host=0
    rb_is_host_address "$address" && is_host=1

    if [ "$is_host" = "1" ]; then
        local media_real dest_real
        media_real=$(realpath -m /home/fpp/media 2>/dev/null)
        dest_real=$(realpath -m "$DEST_ROOT" 2>/dev/null)
        if [ -n "$media_real" ] && [ "$dest_real" = "$media_real" ]; then
            # Destination IS /home/fpp/media itself, not some subfolder of
            # it - source and destination would be the exact same
            # directory. Nothing to exclude our way out of; refuse.
            rb_log "ERROR $id: destination '$DEST_ROOT' is /home/fpp/media itself - source and destination would be the same directory."
            rb_write_status "$id" "$(jq -n --arg id "$id" --arg hostname "$hostname" --arg address "$address" \
                --arg run "$RUN_ID" --arg t "$(rb_now_iso)" --argjson dryrun "$([ "$DRYRUN" = "1" ] && echo true || echo false)" \
                '{id:$id, hostname:$hostname, address:$address, state:"error", dryRun:$dryrun, runId:$run, finishedAt:$t, errorDetail:"Destination is /home/fpp/media itself, which is where the Host stores its own source data - source and destination would be the same directory. Pick NVMe/SSD/USB storage for a Host backup instead."}')"
            return
        fi
        case "$dest_real" in
            "$media_real"/*)
                # The SD Card/System Storage fallback destination lives at
                # /home/fpp/media/backups (see rb_dest_root() in
                # lib_common.sh) - a subdirectory of the very tree a
                # Host-local backup copies FROM. Rather than refusing the
                # whole Host backup over this, exclude just that one
                # subdirectory from the copy (rsync --exclude, anchored at
                # the source root) and back up everything else in
                # /home/fpp/media normally - the other selected remotes'
                # backups living there are plugin-managed destination
                # data, not part of what "back up the Host" should mean
                # anyway.
                local dest_rel="${dest_real#"$media_real"/}"
                host_exclude=(--exclude="/${dest_rel}")
                rb_log "NOTE $id: destination '$DEST_ROOT' is inside /home/fpp/media (SD Card/System Storage fallback) - excluding '/${dest_rel}' from the Host's own backup so it doesn't copy its own destination folder into itself."
                ;;
        esac
    fi

    if ! rb_dest_mounted; then
        rb_log "ABORT $id: destination '$DEST_MOUNT' is not mounted (checked just before starting this remote)"
        rb_write_status "$id" "$(jq -n --arg id "$id" --arg hostname "$hostname" --arg address "$address" \
            --arg run "$RUN_ID" --arg t "$(rb_now_iso)" --argjson dryrun "$([ "$DRYRUN" = "1" ] && echo true || echo false)" \
            '{id:$id, hostname:$hostname, address:$address, state:"error", dryRun:$dryrun, runId:$run, finishedAt:$t, errorDetail:"Destination drive is not mounted. It may have been disconnected, unmounted, or reformatted. Check Config > Storage and try again."}')"
        return
    fi

    rb_write_status "$id" "$(jq -n --arg id "$id" --arg hostname "$hostname" --arg address "$address" \
        --arg run "$RUN_ID" --arg t "$(rb_now_iso)" --argjson dryrun "$([ "$DRYRUN" = "1" ] && echo true || echo false)" \
        '{id:$id, hostname:$hostname, address:$address, state:"running", dryRun:$dryrun, runId:$run, startedAt:$t, currentFile:"", percent:0}')"

    if [ "$SNAPSHOT_MODE" = "true" ]; then
        # Snapshots live flat at the destination root, exactly like
        # rolling-mode backups (<id>-<date>, e.g. Pi3_953-20260804) -
        # NOT nested under a per-device "<id>/" container folder like
        # earlier versions of this plugin used. That nesting was
        # structural bait for FPP's own "Restore from USB" / File Copy
        # Restore device browser: its scanner (GetAvailableBackupsFromDir()
        # in FPP's own www/api/controllers/backups.php) lists ANY
        # subdirectory it finds one level down, so the empty container
        # folder itself (e.g. "Pi3_953") got listed as a selectable
        # "backup" right alongside the real dated snapshot inside it
        # ("Pi3_953/Pi3_953-20260804") - and since the container never
        # holds anything but other folders, restoring FROM it silently
        # "succeeds" while copying nothing at all. Flat naming gives
        # every listed entry the same guarantee rolling mode already
        # had: if FPP's dropdown shows it, it's real and restorable.
        # (Backups made before this fix keep their old nested layout on
        # disk untouched - this plugin's own browse/list/delete
        # features still understand both layouts; only new snapshots
        # going forward use the flat one.)
        target="${DEST_ROOT}/${id}-${today}"
        prev=$(find "$DEST_ROOT" -maxdepth 1 -mindepth 1 -type d -name "${id}-*" ! -name "$(basename "$target")" 2>/dev/null | sort | tail -1)
        # A dry run only reports what WOULD happen - it must never create,
        # rename, or otherwise touch anything on the destination itself.
        # rsync's own --dry-run (added to $extra below) already handles
        # not writing file contents; this mkdir was unconditional and ran
        # regardless, so a "dry run" was silently leaving a real empty
        # backup folder behind on disk. --link-dest discovery above is
        # read-only (find) so it stays as-is either way.
        [ "$DRYRUN" = "1" ] || mkdir -p "$target"
        if [ -n "$prev" ]; then
            extra+=(--link-dest="$prev")
        fi
    else
        existing=$(find "$DEST_ROOT" -maxdepth 1 -mindepth 1 -type d -name "${id}-*" ! -name "${id}-${today}" 2>/dev/null | sort | tail -1)
        target="${DEST_ROOT}/${id}-${today}"
        if [ "$DRYRUN" = "1" ]; then
            : # see snapshot-mode comment above - no mkdir/mv for a dry run
        else
            if [ -n "$existing" ] && [ ! -d "$target" ]; then
                mv "$existing" "$target"
                rb_log "renamed existing backup for $id: $(basename "$existing") -> $(basename "$target")"
            fi
            mkdir -p "$target"
        fi
    fi

    # A dry run deliberately never creates $target (see above), so checking
    # it here would always fail - the real precondition for a dry run is
    # just that the destination itself is still writable. A real run still
    # checks $target specifically, since that's what's about to be written to.
    if [ "$DRYRUN" = "1" ]; then
        target_ok=1; [ -w "$DEST_ROOT" ] || target_ok=0
    else
        target_ok=1; { [ -d "$target" ] && [ -w "$target" ]; } || target_ok=0
    fi
    if [ "$target_ok" = "0" ]; then
        rb_log "ERROR $id: could not create/write to target directory '$target' (destination likely unmounted or disconnected mid-run)"
        rb_write_status "$id" "$(jq -n --arg id "$id" --arg hostname "$hostname" --arg address "$address" \
            --arg run "$RUN_ID" --arg t "$(rb_now_iso)" --argjson dryrun "$([ "$DRYRUN" = "1" ] && echo true || echo false)" --arg target "$target" \
            '{id:$id, hostname:$hostname, address:$address, state:"error", dryRun:$dryrun, runId:$run, finishedAt:$t, target:$target, errorDetail:"Could not create or write to the backup folder on the destination drive. It may have been unmounted, disconnected, or reformatted during this run."}')"
        return
    fi

    [ "$DRYRUN" = "1" ] && extra+=(--dry-run)
    [ "$DELETE_EXTRA" = "true" ] && extra+=(--delete)

    local ssh_cmd="ssh -i ${SSH_KEY} -p ${SSH_PORT} -o StrictHostKeyChecking=accept-new -o ConnectTimeout=10 -o BatchMode=yes"
    # IPv6 addresses (e.g. 2600:1700:1ef0:5a90::33) must be bracketed in
    # rsync's user@host:path syntax, otherwise rsync's own host:path
    # parser gets confused by all the extra colons and the transfer
    # never even connects. IPv4 addresses/hostnames never contain a
    # colon so this check is unambiguous.
    local rsync_host="$address"
    case "$address" in
        *:*) rsync_host="[${address}]" ;;
    esac
    local src rsync_xport=()
    if [ "$is_host" = "1" ]; then
        # The Host backing itself up: a plain local-to-local copy, not an
        # SSH round trip to itself - no key to push, no dependency on its
        # own sshd, and none of the "did I push my own key to myself"
        # setup an SSH pull would otherwise need.
        src="/home/fpp/media/"
    else
        # A remote reimaged/restored since its key was last pushed (or
        # since the last successful backup) keeps its known_hosts-recorded
        # identity stale on this Host, which StrictHostKeyChecking=accept-new
        # (above) does NOT auto-forgive - it only trusts a host it has never
        # seen before. Without this, a rebuilt remote fails every scheduled
        # backup with a host-key-verification error until someone happens to
        # notice and re-runs "Push SSH Key" by hand. See
        # rb_clear_stale_host_key in lib_common.sh for the full rationale.
        rb_clear_stale_host_key "$address" "$SSH_PORT"
        src="${SSH_USER}@${rsync_host}:/home/fpp/media/"
        rsync_xport=(-e "$ssh_cmd")
    fi

    rb_log "starting rsync for $id ($address) -> $target (dryRun=$DRYRUN delete=$DELETE_EXTRA snapshot=$SNAPSHOT_MODE local=$is_host)"

    # --outbuf=line is the key bit: rsync's stdout is fully buffered (not
    # line-buffered) whenever it's not attached to a terminal, which is
    # always true here since we redirect to a log file. Without it,
    # filenames and --info=progress2 updates sit in rsync's internal
    # buffer and may not hit the log for a long time (sometimes only at
    # exit), which is why Current File/Progress looked empty during a run.
    rsync -a -h -v --stats --info=progress2 --outbuf=line --copy-links \
        "${EXCLUDE_ARGS[@]}" "${host_exclude[@]}" "${extra[@]}" \
        "${rsync_xport[@]}" "$src" "${target}/" > "$logfile" 2>&1 &
    local rsync_pid=$!
    echo "$rsync_pid" > "${PIDS_DIR}/${id}.pid"

    while kill -0 "$rsync_pid" 2>/dev/null; do
        local lastfile lastpct
        lastfile=$(grep -vE '%|to-chk=|sending incremental|^$' "$logfile" 2>/dev/null | tail -1)
        lastpct=$(grep -oE '[0-9]{1,3}%' "$logfile" 2>/dev/null | tail -1 | tr -d '%')
        [ -z "$lastpct" ] && lastpct=0
        rb_write_status "$id" "$(jq -n --arg id "$id" --arg hostname "$hostname" --arg address "$address" \
            --arg run "$RUN_ID" --argjson dryrun "$([ "$DRYRUN" = "1" ] && echo true || echo false)" \
            --arg cur "$lastfile" --argjson pct "$lastpct" \
            '{id:$id, hostname:$hostname, address:$address, state:"running", dryRun:$dryrun, runId:$run, currentFile:$cur, percent:$pct}')"
        sleep 2
    done
    wait "$rsync_pid"
    local rc=$?
    rm -f "${PIDS_DIR}/${id}.pid"

    # Extras only run after a successful main pull - if we couldn't even
    # get the show content, there's no point spending more time probing
    # this remote for logs/config too. Appended to the same logfile;
    # since they run strictly after the main transfer, the --stats
    # parsing below (which greps for the *first* match) still reads the
    # main transfer's numbers, not these extras.
    if [ "$rc" -eq 0 ]; then
        local extras_opts=()
        [ "$DRYRUN" = "1" ] && extras_opts+=(--dry-run)

        {
            echo ""
            echo "--- extras (logs / system config) ---"
        } >> "$logfile" 2>&1

        # One-time cleanup for backups made by an older version of this
        # plugin, which wrote system-config/ and system-logs/ as plain
        # directories directly under the backup folder (the layout that
        # caused the FPP "Restore from USB" pollution described above).
        # Superseded by the .tar.gz files below - safe to remove
        # unconditionally since these are plugin-managed artifacts, not
        # user content, and get fully repopulated (as archives) by this
        # same run.
        if [ "$DRYRUN" != "1" ]; then
            [ -d "${target}/system-config" ] && rm -rf "${target}/system-config"
            [ -d "${target}/system-logs" ] && rm -rf "${target}/system-logs"
        fi

        # Both extras below are pulled into a scratch directory first,
        # then packaged into a single .tar.gz FILE at ${target} and the
        # scratch directory removed - see the EXCLUDE_ARGS comment above
        # for why these must be files, not directories. Scratch dirs
        # live under the plugin's own data dir (not on the destination
        # drive) so a slow/large pull never leaves a half-written
        # directory sitting inside the backup folder itself.
        local scratch_root
        scratch_root=$(mktemp -d "${DATA_DIR}/tmp_extras_${id}_XXXXXX")

        local remote_log_dir
        remote_log_dir=$(rb_resolve_remote_setting "$address" "logDirectory")
        if [ -n "$remote_log_dir" ]; then
            case "$remote_log_dir" in
                /home/fpp/media | /home/fpp/media/*)
                    echo "logs: logDirectory=$remote_log_dir is under /home/fpp/media - already covered above" >> "$logfile" 2>&1
                    ;;
                *)
                    echo "logs: logDirectory=$remote_log_dir is NOT under /home/fpp/media - pulling separately" >> "$logfile" 2>&1
                    mkdir -p "${scratch_root}/system-logs"
                    if [ "$is_host" = "1" ]; then
                        rsync -a -h --outbuf=line --copy-links "${extras_opts[@]}" \
                            "${remote_log_dir%/}/" "${scratch_root}/system-logs/" >> "$logfile" 2>&1
                    else
                        rsync -a -h --outbuf=line --copy-links "${extras_opts[@]}" \
                            -e "$ssh_cmd" "${SSH_USER}@${rsync_host}:${remote_log_dir%/}/" "${scratch_root}/system-logs/" >> "$logfile" 2>&1
                    fi
                    if [ "$DRYRUN" != "1" ] && [ -n "$(ls -A "${scratch_root}/system-logs" 2>/dev/null)" ]; then
                        tar -czf "${target}/system-logs.tar.gz" -C "${scratch_root}" system-logs
                        echo "logs: packaged into system-logs.tar.gz" >> "$logfile" 2>&1
                    fi
                    ;;
            esac
        else
            echo "logs: could not read logDirectory from http://${address}/api/settings/logDirectory (remote unreachable over HTTP, or its API is disabled) - skipped" >> "$logfile" 2>&1
        fi

        if [ "$INCLUDE_SYSTEM_CONFIG" = "true" ]; then
            echo "system-config: pulling known system paths via sudo on the remote (best effort - missing paths are skipped)" >> "$logfile" 2>&1
            mkdir -p "${scratch_root}/system-config"
            # One rsync call with all SYSTEM_CONFIG_PATHS as separate source
            # args, instead of one call per path in a loop. rsync opens a
            # single remote-shell (ssh+sudo) session for multiple sources
            # against the same host and places each under the destination
            # by its own basename - identical layout to the old per-path
            # calls (dirs land as subdirs, files as files, confirmed against
            # a real rsync run). A missing path still only skips that one
            # source and reports its own "No such file or directory", same
            # as before; every other path still transfers in the same run.
            # Previously each of the 8 paths opened its own SSH+sudo
            # connection, and the remote's login banner (MOTD) got printed
            # once per connection - 8 near-identical banner blocks cluttering
            # every remote's log for what's fundamentally one fetch.
            if [ "$is_host" = "1" ]; then
                # Local paths, elevated with a plain local sudo instead of
                # --rsync-path="sudo rsync" over SSH (that flag only makes
                # sense when there's a remote shell involved) - passwordless
                # local sudo for the fpp user is already relied on elsewhere
                # in this plugin (format_usb.sh, mount_usb.sh).
                sudo rsync -a -h --outbuf=line --copy-links "${extras_opts[@]}" \
                    "${SYSTEM_CONFIG_PATHS[@]}" "${scratch_root}/system-config/" >> "$logfile" 2>&1
            else
                local system_config_srcs=()
                for p in "${SYSTEM_CONFIG_PATHS[@]}"; do
                    system_config_srcs+=("${SSH_USER}@${rsync_host}:${p}")
                done
                rsync -a -h --outbuf=line --copy-links "${extras_opts[@]}" --rsync-path="sudo rsync" \
                    -e "$ssh_cmd" "${system_config_srcs[@]}" "${scratch_root}/system-config/" >> "$logfile" 2>&1
            fi
            if [ "$DRYRUN" != "1" ] && [ -n "$(ls -A "${scratch_root}/system-config" 2>/dev/null)" ]; then
                tar -czf "${target}/system-config.tar.gz" -C "${scratch_root}" system-config
                echo "system-config: packaged into system-config.tar.gz" >> "$logfile" 2>&1
            fi
        fi

        # sudo, not a plain rm: when this remote is the Host itself
        # (is_host=1, above), SYSTEM_CONFIG_PATHS is pulled via a *local*
        # `sudo rsync` - rsync -a run as root preserves the source files'
        # real ownership (root:root for /etc/network, /etc/wpa_supplicant,
        # etc.), so scratch_root ends up containing root-owned entries.
        # This script itself runs as the plain fpp user, so a plain rm -rf
        # here failed with "Permission denied" on every one of them,
        # leaving tmp_extras_<id>_* directories behind under data/ after
        # every Host-local run with "Include system config" enabled. Only
        # matters for the local is_host path - the SSH+sudo path
        # (system_config_srcs above) has its own local (non-root) rsync
        # receiving the transfer, so it was never root-owned locally to
        # begin with - but sudo rm here is harmless either way (passwordless
        # local sudo for fpp is already relied on elsewhere in this script
        # and plugin).
        sudo rm -rf "$scratch_root"
    fi

    local total_size xfer_size num_files num_files_total total_files_line state
    # rsync's -h (human-readable, always passed - see the rsync invocation
    # above) makes --stats print sizes past ~1000 bytes as a decimal +
    # K/M/G/T suffix (e.g. "5.24M bytes") instead of plain digits, so the
    # extracted token has to go through rb_parse_rsync_bytes rather than
    # just stripping commas - see its comment for what broke without this.
    total_size=$(rb_parse_rsync_bytes "$(grep -m1 '^Total file size:' "$logfile" | grep -oE '[0-9][0-9,]*(\.[0-9]+)?[KMGT]?' | head -1)")
    xfer_size=$(rb_parse_rsync_bytes "$(grep -m1 '^Total transferred file size:' "$logfile" | grep -oE '[0-9][0-9,]*(\.[0-9]+)?[KMGT]?' | head -1)")
    # rsync 3.1+ renamed this line to "Number of regular files transferred:"
    # (older versions used "Number of files transferred:") - match either so
    # this keeps working across the rsync versions FPP images actually ship.
    num_files=$(grep -m1 -E '^Number of (regular files transferred|files transferred):' "$logfile" | grep -oE '[0-9,]+' | head -1 | tr -d ',')
    # "Number of files: 60 (reg: 38, dir: 22)" - the total file count in the
    # source tree. Prefer the "reg:" (regular files only, excludes
    # directories) breakdown when present; older rsync / trees with no
    # subdirectories may omit it, so fall back to the bare total.
    total_files_line=$(grep -m1 '^Number of files:' "$logfile")
    num_files_total=$(echo "$total_files_line" | grep -oE 'reg: [0-9,]+' | grep -oE '[0-9,]+' | tr -d ',')
    [ -z "$num_files_total" ] && num_files_total=$(echo "$total_files_line" | grep -oE '[0-9,]+' | head -1 | tr -d ',')
    [ -z "$total_size" ] && total_size=0
    [ -z "$xfer_size" ] && xfer_size=0
    [ -z "$num_files" ] && num_files=0
    [ -z "$num_files_total" ] && num_files_total=0

    if [ "$rc" -eq 0 ]; then
        state="done"
        [ "$DRYRUN" = "1" ] && state="dry-run-complete"
    else
        state="error"
    fi

    local error_detail=""
    if [ "$state" = "error" ]; then
        error_detail=$(grep -iE 'rsync error|rsync:|@ERROR|ssh:|Connection refused|No route to host|Could not resolve|Permission denied|Host key verification failed' "$logfile" 2>/dev/null | tail -3 | tr '\n' ' | ')
        [ -z "$error_detail" ] && error_detail=$(tail -3 "$logfile" 2>/dev/null | tr '\n' ' | ')
    fi

    rb_write_status "$id" "$(jq -n --arg id "$id" --arg hostname "$hostname" --arg address "$address" \
        --arg run "$RUN_ID" --argjson dryrun "$([ "$DRYRUN" = "1" ] && echo true || echo false)" \
        --arg state "$state" --arg t "$(rb_now_iso)" --arg target "$target" --arg log "$logfile" --arg errdetail "$error_detail" \
        --argjson rc "$rc" --argjson totalSize "$total_size" --argjson xferSize "$xfer_size" --argjson numFiles "$num_files" --argjson numFilesTotal "$num_files_total" \
        '{id:$id, hostname:$hostname, address:$address, state:$state, dryRun:$dryrun, runId:$run, finishedAt:$t,
          target:$target, logFile:$log, exitCode:$rc, totalBytes:$totalSize, transferredBytes:$xferSize, filesTransferred:$numFiles, totalFiles:$numFilesTotal, errorDetail:$errdetail}')"

    rb_log "finished rsync for $id rc=$rc xferBytes=$xfer_size files=$num_files"
    rb_prune_remote_logs "$id"
}

# --- Concurrency-limited launcher: first N start immediately, each ---
# --- completion backfills the next queued remote (per spec).       ---
running=0
echo "$REMOTES_JSON" | jq -c '.[]' > "${DATA_DIR}/.remotes_${RUN_ID}.jsonl"
while IFS= read -r remote_json; do
    backup_one "$remote_json" &
    running=$((running + 1))
    if [ "$running" -ge "$MAX_CONCURRENT" ]; then
        wait -n
        running=$((running - 1))
    fi
done < "${DATA_DIR}/.remotes_${RUN_ID}.jsonl"
wait
rm -f "${DATA_DIR}/.remotes_${RUN_ID}.jsonl"

echo '{"active": false}' > "${DATA_DIR}/run_active.json"
rb_log "=== run complete (runId=$RUN_ID) ==="
