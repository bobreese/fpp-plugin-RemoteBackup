#!/bin/bash
# Delete one specific backup folder (as selected from the Status page's
# "Backed Up" dropdown). THIS ERASES THAT BACKUP - the UI is expected
# to have already gotten explicit confirmation; this script adds its
# own independent safety checks so a bad path can't delete anything
# outside the configured destination storage, or anything inside it
# that doesn't actually look like one of our backup folders (backups
# live directly under the mount now, so unlike the old dedicated
# "RemoteBackup" subfolder there's no separate namespace to lean on -
# see the naming-pattern check below).
#
# Usage: delete_backup.sh <path> <confirm token>
# The confirm token must be exactly I_UNDERSTAND_THIS_DELETES_THE_BACKUP.
# Output JSON: {"ok":true,"deleted":"/mnt/Backups/Pi5-20260803"}

. "$(dirname "$0")/lib_common.sh"

REQ_PATH="$1"
CONFIRM="$2"

json_err() {
    printf '{"ok":false,"error":%s}\n' "$(printf '%s' "$1" | jq -Rs .)"
}

if [ "$CONFIRM" != "I_UNDERSTAND_THIS_DELETES_THE_BACKUP" ]; then
    json_err "Missing/incorrect confirmation token; refusing to delete."
    exit 0
fi
if [ -z "$REQ_PATH" ]; then
    json_err "No path given"
    exit 0
fi

DEST_MOUNT=$(rb_setting '.destinationMount')
if [ -z "$DEST_MOUNT" ] || [ ! -d "$DEST_MOUNT" ]; then
    json_err "No destination storage configured/mounted"
    exit 0
fi
DEST_ROOT_REAL=$(realpath "$(rb_dest_root "$DEST_MOUNT")" 2>/dev/null)
TARGET_REAL=$(realpath "$REQ_PATH" 2>/dev/null)

# Must resolve to a real directory strictly inside the destination
# storage, and not be the root itself (so this can only ever remove one
# specific backup, never the whole destination in one call).
if [ -z "$TARGET_REAL" ] || [ -z "$DEST_ROOT_REAL" ] || [ ! -d "$TARGET_REAL" ] || \
   [ "$TARGET_REAL" = "$DEST_ROOT_REAL" ] || [[ "$TARGET_REAL" != "$DEST_ROOT_REAL"/* ]]; then
    json_err "Path is not a valid single backup under the configured destination storage"
    exit 0
fi

# Backups no longer live under a dedicated subfolder we can trust as
# "everything is ours in here" - the mount can now also hold whatever
# else was already on that drive. Require the folder's own name to
# actually look like one of ours (<id>-<date>, e.g. Pi5-20260803, which
# is also true of the leaf folder in snapshot mode) before allowing the
# delete, on top of the path-containment check above.
TARGET_BASENAME=$(basename "$TARGET_REAL")
if [[ ! "$TARGET_BASENAME" =~ ^.+-[0-9]{8}$ ]]; then
    json_err "Path does not look like a Remote Backup folder (expected <name>-YYYYMMDD) - refusing to delete."
    exit 0
fi

rb_log "delete_backup: removing $TARGET_REAL"
if ! rm -rf "$TARGET_REAL" 2>/tmp/rb_del_err_$$; then
    ERR=$(cat /tmp/rb_del_err_$$ 2>/dev/null); rm -f /tmp/rb_del_err_$$
    rb_log "delete_backup FAILED: $ERR"
    json_err "Delete failed: ${ERR:-unknown error}"
    exit 0
fi
rm -f /tmp/rb_del_err_$$

# Snapshot mode leaves an empty "<id>/" parent dir behind once its last
# dated snapshot is deleted - clean that up too if so.
PARENT=$(dirname "$TARGET_REAL")
if [ "$PARENT" != "$DEST_ROOT_REAL" ] && [ -d "$PARENT" ] && [ -z "$(ls -A "$PARENT" 2>/dev/null)" ]; then
    rmdir "$PARENT" 2>/dev/null || true
fi

# The Backup Status table on the Status page reads data/status/<id>.json,
# which still points at whatever folder the last run wrote to. Without
# this, a remote whose backup we just deleted would keep showing "Done"
# with a Backup Folder path that no longer exists. Clear any status
# entries that reference the folder we just removed so that remote goes
# back to "no backup yet" until its next run.
REMOVED_STATUS=""
for sf in "${STATUS_DIR}"/*.json; do
    [ -f "$sf" ] || continue
    sf_target=$(jq -r '.target // empty' "$sf" 2>/dev/null)
    if [ -n "$sf_target" ] && [ "$sf_target" = "$TARGET_REAL" ]; then
        rb_log "delete_backup: clearing stale status file $sf (pointed at deleted $TARGET_REAL)"
        rm -f "$sf"
        REMOVED_STATUS="${REMOVED_STATUS}${REMOVED_STATUS:+,}$(basename "$sf" .json)"
    fi
done

jq -n --arg deleted "$TARGET_REAL" --arg clearedStatus "$REMOVED_STATUS" \
    '{ok:true, deleted:$deleted, clearedStatusFor: (if $clearedStatus == "" then [] else ($clearedStatus | split(",")) end)}'
