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
DEST_ROOT="${DEST_MOUNT%/}/RemoteBackup"
mkdir -p "$DEST_ROOT"

EXCLUDE_ARGS=()
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
    local id hostname address today target dest_root_for_id existing prev linkdest_opt=() extra=() logfile

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
        dest_root_for_id="${DEST_ROOT}/${id}"
        mkdir -p "$dest_root_for_id"
        target="${dest_root_for_id}/${id}-${today}"
        prev=$(find "$dest_root_for_id" -maxdepth 1 -mindepth 1 -type d -name "${id}-*" ! -name "$(basename "$target")" 2>/dev/null | sort | tail -1)
        mkdir -p "$target"
        if [ -n "$prev" ]; then
            extra+=(--link-dest="$prev")
        fi
    else
        existing=$(find "$DEST_ROOT" -maxdepth 1 -mindepth 1 -type d -name "${id}-*" ! -name "${id}-${today}" 2>/dev/null | sort | tail -1)
        target="${DEST_ROOT}/${id}-${today}"
        if [ -n "$existing" ] && [ ! -d "$target" ]; then
            mv "$existing" "$target"
            rb_log "renamed existing backup for $id: $(basename "$existing") -> $(basename "$target")"
        fi
        mkdir -p "$target"
    fi

    if [ ! -d "$target" ] || [ ! -w "$target" ]; then
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
    rsync -a -h -v --stats --info=progress2 --outbuf=line \
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

    local total_size xfer_size num_files state
    total_size=$(grep -m1 '^Total file size:' "$logfile" | grep -oE '[0-9,]+' | head -1 | tr -d ',')
    xfer_size=$(grep -m1 '^Total transferred file size:' "$logfile" | grep -oE '[0-9,]+' | head -1 | tr -d ',')
    num_files=$(grep -m1 '^Number of files transferred:' "$logfile" | grep -oE '[0-9,]+' | head -1 | tr -d ',')
    [ -z "$total_size" ] && total_size=0
    [ -z "$xfer_size" ] && xfer_size=0
    [ -z "$num_files" ] && num_files=0

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
        --argjson rc "$rc" --argjson totalSize "$total_size" --argjson xferSize "$xfer_size" --argjson numFiles "$num_files" \
        '{id:$id, hostname:$hostname, address:$address, state:$state, dryRun:$dryrun, runId:$run, finishedAt:$t,
          target:$target, logFile:$log, exitCode:$rc, totalBytes:$totalSize, transferredBytes:$xferSize, filesTransferred:$numFiles, errorDetail:$errdetail}')"

    rb_log "finished rsync for $id rc=$rc xferBytes=$xfer_size files=$num_files"
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
