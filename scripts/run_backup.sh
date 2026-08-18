#!/bin/bash
# Core Remote Backup engine.
#
# Usage:
#   run_backup.sh [--dry-run] [--remotes host1,host2,...] [--foreground]
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
RUN_ID=$(date '+%Y%m%d-%H%M%S')

while [ $# -gt 0 ]; do
    case "$1" in
        --dry-run) DRYRUN=1 ;;
        --remotes) shift; REMOTE_FILTER="$1" ;;
        --foreground) FOREGROUND=1 ;;
    esac
    shift
done

if [ ! -f "$SETTINGS_FILE" ]; then
    echo "No settings.json found; configure the plugin first." >&2
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

# rb_prune_remote_logs: keeps only the newest KEEP per-remote run log
# files (data/logs/<id>-<runId>.log) and deletes the rest. Called once
# per remote at the end of its run, real or dry-run alike - nothing
# else ever reads an old one (the UI's "view log" always opens the
# newest match for a given remote, see ajax.php's getLog), so older
# copies are pure disk-space dead weight otherwise. Filenames sort
# chronologically as plain strings since runId is YYYYMMDD-HHMMSS, so
# a lexical sort (not mtime) determines newest-first - robust even if
# a file's mtime were ever touched independently of its name.
rb_prune_remote_logs() {
    local rid="$1" keep=15
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
    local id hostname address today target existing prev linkdest_opt=() extra=() logfile target_ok

    id=$(echo "$remote_json" | jq -r '.id')
    hostname=$(echo "$remote_json" | jq -r '.hostname')
    address=$(echo "$remote_json" | jq -r '.address')
    today=$(date '+%Y%m%d')
    logfile="${LOG_DIR}/${id}-${RUN_ID}.log"

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
    local src="${SSH_USER}@${rsync_host}:/home/fpp/media/"

    rb_log "starting rsync for $id ($address) -> $target (dryRun=$DRYRUN delete=$DELETE_EXTRA snapshot=$SNAPSHOT_MODE)"

    # --outbuf=line is the key bit: rsync's stdout is fully buffered (not
    # line-buffered) whenever it's not attached to a terminal, which is
    # always true here since we redirect to a log file. Without it,
    # filenames and --info=progress2 updates sit in rsync's internal
    # buffer and may not hit the log for a long time (sometimes only at
    # exit), which is why Current File/Progress looked empty during a run.
    rsync -a -h -v --stats --info=progress2 --outbuf=line --copy-links \
        "${EXCLUDE_ARGS[@]}" "${extra[@]}" \
        -e "$ssh_cmd" "$src" "${target}/" > "$logfile" 2>&1 &
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
                    rsync -a -h --outbuf=line --copy-links "${extras_opts[@]}" \
                        -e "$ssh_cmd" "${SSH_USER}@${rsync_host}:${remote_log_dir%/}/" "${scratch_root}/system-logs/" >> "$logfile" 2>&1
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
            for p in "${SYSTEM_CONFIG_PATHS[@]}"; do
                rsync -a -h --outbuf=line --copy-links "${extras_opts[@]}" --rsync-path="sudo rsync" \
                    -e "$ssh_cmd" "${SSH_USER}@${rsync_host}:${p}" "${scratch_root}/system-config/" >> "$logfile" 2>&1
            done
            if [ "$DRYRUN" != "1" ] && [ -n "$(ls -A "${scratch_root}/system-config" 2>/dev/null)" ]; then
                tar -czf "${target}/system-config.tar.gz" -C "${scratch_root}" system-config
                echo "system-config: packaged into system-config.tar.gz" >> "$logfile" 2>&1
            fi
        fi

        rm -rf "$scratch_root"
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
