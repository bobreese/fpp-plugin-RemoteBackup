#!/bin/bash
# Prunes every remote's dated snapshot history down to just its newest
# folder. Run by ajax.php's saveSettings action, only when the user just
# turned Snapshot Mode off AND chose "Prune to Latest Only" in the popup
# Config shows for that exact transition (see rbShowSnapshotModePopup /
# rbCheckSnapshotModeLeaveTransition in config.php) - Rolling mode itself
# never revisits or cleans up old dated folders on its own (it just
# renames whichever one is newest to today's date - see run_backup.sh),
# so without this, every remote's frozen snapshot history would otherwise
# sit there taking up space forever until someone deletes it by hand from
# the Status page, one folder at a time.
#
# Reuses list_backups.sh's own enumeration (same {id,date,name,path}
# shape, understands both the current flat layout and the legacy nested
# "<id>/<id>-<date>" one) so "what counts as one of this remote's dated
# folders" here never drifts from what the Status page's "Backed Up"
# dropdown itself shows - and reuses delete_backup.sh for every actual
# removal, so the exact same containment/naming-pattern safety checks and
# stale-status cleanup apply here as to a single manual delete from that
# dropdown.
#
# Output JSON: {"ok":true,"remotesPruned":N,"deleted":["/mnt/Backups/Pi5-20260901",...]}

. "$(dirname "$0")/lib_common.sh"

SELF_DIR="$(dirname "$0")"

LIST=$("$SELF_DIR/list_backups.sh")
LIST_OK=$(echo "$LIST" | jq -r '.ok // false' 2>/dev/null)
if [ "$LIST_OK" != "true" ]; then
    echo "$LIST"
    exit 0
fi

# list_backups.sh already returns entries sorted by (id, date) ascending -
# group_by(.id) is a stable sort on id alone, so each id's own entries
# keep that ascending-date order within their group. Dropping the last
# element of each group (.[0:-1]) leaves exactly the older folders to
# prune, for any id that has more than one.
TO_DELETE=$(echo "$LIST" | jq -r '
    .backups
    | group_by(.id)
    | map(select(length > 1) | .[0:-1][])
    | .[]
    | "\(.id)\t\(.path)"
')

DELETED_PATHS='[]'
IDS_SEEN=""
while IFS=$'\t' read -r rid path; do
    [ -z "$path" ] && continue
    out=$("$SELF_DIR/delete_backup.sh" "$path" "I_UNDERSTAND_THIS_DELETES_THE_BACKUP")
    del_ok=$(echo "$out" | jq -r '.ok // false' 2>/dev/null)
    if [ "$del_ok" = "true" ]; then
        DELETED_PATHS=$(echo "$DELETED_PATHS" | jq --arg p "$path" '. + [$p]')
        IDS_SEEN="${IDS_SEEN}${IDS_SEEN:+$'\n'}${rid}"
    else
        rb_log "prune_snapshots: failed to delete $path: $(echo "$out" | jq -r '.error // "unknown error"' 2>/dev/null)"
    fi
done <<< "$TO_DELETE"

REMOTES_PRUNED=0
[ -n "$IDS_SEEN" ] && REMOTES_PRUNED=$(printf '%s\n' "$IDS_SEEN" | sort -u | grep -c .)

jq -n --argjson deleted "$DELETED_PATHS" --argjson remotesPruned "$REMOTES_PRUNED" \
    '{ok: true, remotesPruned: $remotesPruned, deleted: $deleted}'
