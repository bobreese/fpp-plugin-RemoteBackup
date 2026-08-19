#!/bin/bash
# Clones the entire current backup set - every remote's backup folders
# under the primary destination - to a second, independent USB drive
# mounted at /mnt/BackupsCopy. Manual only (no Scheduler command): this
# is for an occasional off-site/rotating spare copy, started by hand
# from the Status page once a second drive is plugged in, formatted (or
# already formatted), and mounted via the Config page.
#
# An exact mirror (rsync --delete): the clone always matches what's
# currently on the primary destination, so a backup you deleted there on
# purpose doesn't just silently keep accumulating forever on the clone.
#
# Usage: clone_backups.sh
# Writes live progress to data/clone_status.json (polled by ajax.php's
# cloneStatus action) and a full log to data/logs/clone-<runId>.log
# (read via getLog?which=clone). Own PID file at data/clone.pid (NOT
# under data/pids/ - kept out of the generic per-remote 'stop' action's
# glob so stopping a clone needs its own dedicated stopClone action,
# distinct from stopping a backup run).

. "$(dirname "$0")/lib_common.sh"

SECONDARY_MOUNT="/mnt/BackupsCopy"
RUN_ID=$(date '+%Y%m%d-%H%M%S')
CLONE_LOG="${LOG_DIR}/clone-${RUN_ID}.log"
CLONE_STATUS_FILE="${DATA_DIR}/clone_status.json"
CLONE_ACTIVE_FILE="${DATA_DIR}/clone_active.json"
CLONE_PID_FILE="${DATA_DIR}/clone.pid"
CLONE_LOCK_FILE="${DATA_DIR}/clone.lock"

write_clone_status() {
    echo "$1" > "${CLONE_STATUS_FILE}.tmp" && mv "${CLONE_STATUS_FILE}.tmp" "$CLONE_STATUS_FILE"
}

fail() {
    local msg="$1"
    rb_log "CLONE ABORT: $msg"
    write_clone_status "$(jq -n --arg t "$(rb_now_iso)" --arg msg "$msg" \
        '{state:"error", finishedAt:$t, errorDetail:$msg}')"
    echo "$msg" >&2
    exit 1
}

# Same flock-based overlap guard run_backup.sh uses for run.lock - kernel
# enforced, atomic, and releases automatically no matter how this script
# exits (crash, kill, power loss), unlike a hand-rolled JSON flag alone.
exec 9>"$CLONE_LOCK_FILE"
if ! flock -n 9; then
    fail "A backup clone is already in progress (clone.lock is held by another process)."
fi

# Refuse to run alongside a primary backup run or a primary-drive format -
# the clone reads from the same destination a backup run writes to, and a
# format can pull the destination out from under it entirely.
ACTIVE=$(cat "${DATA_DIR}/run_active.json" 2>/dev/null || echo '{}')
if [ "$(echo "$ACTIVE" | jq -r '.active // false' 2>/dev/null)" = "true" ]; then
    fail "A backup run (or drive format) is currently in progress. Wait for it to finish before cloning backups."
fi

DEST_MOUNT=$(rb_setting '.destinationMount')
if [ -z "$DEST_MOUNT" ] || [ ! -d "$DEST_MOUNT" ]; then
    fail "Primary destination storage is not configured or not mounted - nothing to clone."
fi
DEST_ROOT="$(rb_dest_root "$DEST_MOUNT")"
if [ "$DEST_MOUNT" != "/" ] && command -v mountpoint >/dev/null 2>&1 && ! mountpoint -q "$DEST_MOUNT"; then
    fail "Primary destination storage ('$DEST_MOUNT') is not currently mounted."
fi
if [ ! -d "$DEST_ROOT" ]; then
    fail "Primary destination has no backups yet ('$DEST_ROOT' does not exist) - nothing to clone."
fi

if [ ! -d "$SECONDARY_MOUNT" ] || ! mountpoint -q "$SECONDARY_MOUNT" 2>/dev/null; then
    fail "Secondary drive is not mounted at $SECONDARY_MOUNT - mount it first on the Config page."
fi

# Guard against the primary and secondary being the same drive, or one
# nested inside the other - rsync --delete mirroring a directory into
# itself (or into its own parent) can corrupt or wipe every backup on
# the primary destination instead of just copying it.
DEST_REAL=$(realpath -m "$DEST_ROOT" 2>/dev/null)
SECONDARY_REAL=$(realpath -m "$SECONDARY_MOUNT" 2>/dev/null)
case "$SECONDARY_REAL" in
    "$DEST_REAL" | "$DEST_REAL"/*)
        fail "Secondary drive ($SECONDARY_MOUNT) is the same as, or nested inside, the primary destination ($DEST_ROOT) - refusing to clone into itself."
        ;;
esac
case "$DEST_REAL" in
    "$SECONDARY_REAL" | "$SECONDARY_REAL"/*)
        fail "Primary destination ($DEST_ROOT) is nested inside the secondary drive ($SECONDARY_MOUNT) - refusing to clone, this would mirror the drive into its own subfolder."
        ;;
esac

echo '{"active": true}' > "$CLONE_ACTIVE_FILE"
echo $$ > "$CLONE_PID_FILE"
rb_log "CLONE starting: $DEST_ROOT -> $SECONDARY_MOUNT (runId=$RUN_ID)"
write_clone_status "$(jq -n --arg t "$(rb_now_iso)" --arg run "$RUN_ID" --arg src "$DEST_ROOT" --arg dst "$SECONDARY_MOUNT" \
    '{state:"running", startedAt:$t, runId:$run, source:$src, dest:$dst, currentFile:"", percent:0}')"

# --outbuf=line: rsync's stdout is fully buffered (not line-buffered)
# once it's not attached to a terminal - without it, progress updates
# and filenames sit in rsync's internal buffer and may not hit the log
# for a long time, same reason run_backup.sh's own rsync calls use it.
rsync -a -h -v --stats --info=progress2 --outbuf=line --delete \
    "${DEST_ROOT}/" "${SECONDARY_MOUNT}/" > "$CLONE_LOG" 2>&1 &
RSYNC_PID=$!

while kill -0 "$RSYNC_PID" 2>/dev/null; do
    lastfile=$(grep -vE '%|to-chk=|sending incremental|^$' "$CLONE_LOG" 2>/dev/null | tail -1)
    lastpct=$(grep -oE '[0-9]{1,3}%' "$CLONE_LOG" 2>/dev/null | tail -1 | tr -d '%')
    [ -z "$lastpct" ] && lastpct=0
    write_clone_status "$(jq -n --arg t "$(rb_now_iso)" --arg run "$RUN_ID" --arg src "$DEST_ROOT" --arg dst "$SECONDARY_MOUNT" \
        --arg cur "$lastfile" --argjson pct "$lastpct" \
        '{state:"running", startedAt:$t, runId:$run, source:$src, dest:$dst, currentFile:$cur, percent:$pct}')"
    sleep 2
done
wait "$RSYNC_PID"
RC=$?
rm -f "$CLONE_PID_FILE"

# Same extraction run_backup.sh uses for its own --stats summary: -h
# (human-readable, always passed above) makes rsync print sizes past
# ~1000 bytes as a decimal + K/M/G/T suffix, so the token has to go
# through rb_parse_rsync_bytes rather than just stripping commas.
TRANSFERRED_BYTES=$(rb_parse_rsync_bytes "$(grep -m1 '^Total transferred file size:' "$CLONE_LOG" | grep -oE '[0-9][0-9,]*(\.[0-9]+)?[KMGT]?' | head -1)")
[ -z "$TRANSFERRED_BYTES" ] && TRANSFERRED_BYTES=0

if [ "$RC" -eq 0 ]; then
    rb_log "CLONE done: $DEST_ROOT -> $SECONDARY_MOUNT (runId=$RUN_ID)"
    write_clone_status "$(jq -n --arg t "$(rb_now_iso)" --arg run "$RUN_ID" --arg src "$DEST_ROOT" --arg dst "$SECONDARY_MOUNT" \
        --argjson bytes "$TRANSFERRED_BYTES" \
        '{state:"done", finishedAt:$t, runId:$run, source:$src, dest:$dst, currentFile:"", percent:100, transferredBytes:$bytes}')"
else
    rb_log "CLONE FAILED (rc=$RC): $DEST_ROOT -> $SECONDARY_MOUNT (runId=$RUN_ID) - see $CLONE_LOG"
    write_clone_status "$(jq -n --arg t "$(rb_now_iso)" --arg run "$RUN_ID" --arg src "$DEST_ROOT" --arg dst "$SECONDARY_MOUNT" --arg log "$CLONE_LOG" \
        '{state:"error", finishedAt:$t, runId:$run, source:$src, dest:$dst, errorDetail:("rsync exited with an error - see " + $log)}')"
fi

echo '{"active": false}' > "$CLONE_ACTIVE_FILE"
