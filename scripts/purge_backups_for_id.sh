#!/bin/bash
# Delete EVERY backup folder for one hostname/id - the bulk counterpart
# to delete_backup.sh's single-folder delete, for cleaning up every
# dated snapshot a renamed/retired remote left behind in one action
# instead of one "Backed Up" dropdown selection at a time (e.g. after
# a remote gets reimaged and renamed - this plugin's own remote "id" is
# derived from hostname text, so a rename starts a brand-new series and
# leaves every one of the old name's dated folders as orphans nothing
# else manages).
#
# Covers both layouts list_backups.sh already enumerates: rolling-mode
# flat "<id>-<date>" directly under the destination mount, and legacy
# snapshot-mode nested "<id>/<id>-<date>" (backups made before this
# plugin switched snapshot mode to the flat layout too - see
# run_backup.sh's own comment on that).
#
# Deliberately primary-destination-only: the secondary clone drive (if
# configured) is a plain `rsync --delete` mirror of the primary, run
# manually - whatever's removed here is picked up there automatically
# on the next "Start Clone", same as any single-folder delete already is.
#
# Usage: purge_backups_for_id.sh <id> <confirm token>
# The confirm token must be exactly
# I_UNDERSTAND_THIS_DELETES_ALL_BACKUPS_FOR_THIS_NAME.
# Output JSON: {"ok":true,"id":"Pi3Test","deleted":["/mnt/Backups/Pi3Test-20261105", ...]}

. "$(dirname "$0")/lib_common.sh"

ID="$1"
CONFIRM="$2"

json_err() {
    printf '{"ok":false,"error":%s}\n' "$(printf '%s' "$1" | jq -Rs .)"
}

if [ "$CONFIRM" != "I_UNDERSTAND_THIS_DELETES_ALL_BACKUPS_FOR_THIS_NAME" ]; then
    json_err "Missing/incorrect confirmation token; refusing to delete."
    exit 0
fi
if [ -z "$ID" ]; then
    json_err "No id given"
    exit 0
fi

DEST_MOUNT=$(rb_setting '.destinationMount')
if [ -z "$DEST_MOUNT" ] || [ ! -d "$DEST_MOUNT" ]; then
    json_err "No destination storage configured/mounted"
    exit 0
fi
DEST_ROOT_REAL=$(realpath "$(rb_dest_root "$DEST_MOUNT")" 2>/dev/null)
if [ -z "$DEST_ROOT_REAL" ] || [ ! -d "$DEST_ROOT_REAL" ]; then
    json_err "Destination storage path could not be resolved"
    exit 0
fi

# Glob-matched the same safe way run_backup.sh's own --link-dest
# discovery already relies on - a literal "${ID}-" prefix can't
# accidentally match an unrelated id that merely shares a substring
# (e.g. id "Pi3" against "Pi3Test-...", which doesn't start with "Pi3-").
mapfile -t CANDIDATES < <(
    { find "$DEST_ROOT_REAL" -maxdepth 1 -mindepth 1 -type d -name "${ID}-*" 2>/dev/null
      find "$DEST_ROOT_REAL" -maxdepth 2 -mindepth 2 -type d -path "${DEST_ROOT_REAL}/${ID}/${ID}-*" 2>/dev/null
    } | sort -u
)

if [ "${#CANDIDATES[@]}" -eq 0 ]; then
    json_err "No backups found for '$ID' under the configured destination."
    exit 0
fi

DELETED=()
for d in "${CANDIDATES[@]}"; do
    TARGET_REAL=$(realpath "$d" 2>/dev/null)
    [ -z "$TARGET_REAL" ] && continue
    # Same containment + naming-pattern checks delete_backup.sh applies
    # to a single folder, re-run per folder here too - defense in depth
    # even though every candidate already came from the scoped find above.
    if [ "$TARGET_REAL" = "$DEST_ROOT_REAL" ] || [[ "$TARGET_REAL" != "$DEST_ROOT_REAL"/* ]]; then
        continue
    fi
    TARGET_BASENAME=$(basename "$TARGET_REAL")
    if [[ ! "$TARGET_BASENAME" =~ ^.+-[0-9]{8}$ ]]; then
        continue
    fi

    rb_log "purge_backups_for_id: removing $TARGET_REAL (id=$ID)"
    # sudo, not plain rm - see delete_backup.sh's own comment: a backed-up
    # remote's media tree can contain root-owned content (FPP's settings
    # file in particular), and a plain rm as the unprivileged web server
    # user fails on exactly that.
    if sudo rm -rf "$TARGET_REAL" 2>"/tmp/rb_purge_err_$$"; then
        DELETED+=("$TARGET_REAL")
    else
        ERR=$(cat "/tmp/rb_purge_err_$$" 2>/dev/null)
        rb_log "purge_backups_for_id FAILED on $TARGET_REAL: $ERR"
    fi
    rm -f "/tmp/rb_purge_err_$$"

    # Snapshot mode's "<id>/" parent dir, same cleanup delete_backup.sh
    # already does for a single folder.
    PARENT=$(dirname "$TARGET_REAL")
    if [ "$PARENT" != "$DEST_ROOT_REAL" ] && [ -d "$PARENT" ] && [ -z "$(ls -A "$PARENT" 2>/dev/null)" ]; then
        rmdir "$PARENT" 2>/dev/null || true
    fi
done

if [ "${#DELETED[@]}" -eq 0 ]; then
    json_err "Found backups for '$ID' but none could be deleted - see engine.log."
    exit 0
fi

# This id's own status file points at whatever folder the last run
# wrote to - gone either way now, so clear it, the same way
# delete_backup.sh does for a single delete (matched by content, not by
# reconstructing rb_write_status()'s own sanitized filename - this
# plugin's status files are always read back by globbing *.json and
# matching the .id field inside, never by assuming a specific filename
# encoding, so this does the same rather than risk a mismatch).
for sf in "${STATUS_DIR}"/*.json; do
    [ -f "$sf" ] || continue
    sf_id=$(jq -r '.id // empty' "$sf" 2>/dev/null)
    [ "$sf_id" = "$ID" ] && rm -f "$sf"
done

printf '%s\n' "${DELETED[@]}" | jq -R . | jq -s --arg id "$ID" '{ok:true, id:$id, deleted:.}'
