#!/bin/bash
# Enumerate existing backups under the configured destination storage,
# regardless of whether they're rolling (one "<id>-<date>" dir directly
# under the mount) or snapshot-mode ("<id>/<id>-<date>" nested one
# level). Deliberately does NOT compute sizes here (that needs `du`,
# which can be slow on large backups) - just a fast directory scan so
# the dropdown populates instantly. Use
# get_backup_info.sh for size/contents of a specific selection.
#
# Output JSON: {"ok":true,"backups":[{id,date,name,path,mtime}...]}

. "$(dirname "$0")/lib_common.sh"

DEST_MOUNT=$(rb_setting '.destinationMount')
if [ -z "$DEST_MOUNT" ] || [ ! -d "$DEST_MOUNT" ]; then
    echo '{"ok":false,"error":"No destination storage configured/mounted"}'
    exit 0
fi
DEST_ROOT="$(rb_dest_root "$DEST_MOUNT")"
if [ ! -d "$DEST_ROOT" ]; then
    echo '{"ok":true,"backups":[]}'
    exit 0
fi

NAME_RE='^(.+)-([0-9]{8})$'

emit_entry() {
    local dir="$1"
    local base mtime epoch
    base=$(basename "$dir")
    if [[ "$base" =~ $NAME_RE ]]; then
        local id="${BASH_REMATCH[1]}"
        local date="${BASH_REMATCH[2]}"
        epoch=$(stat -c '%Y' "$dir" 2>/dev/null || echo 0)
        mtime=$(date -u -d "@${epoch}" '+%Y-%m-%dT%H:%M:%SZ' 2>/dev/null || echo "")
        jq -n --arg id "$id" --arg date "$date" --arg name "$base" --arg path "$dir" --arg mtime "$mtime" \
            '{id:$id, date:$date, name:$name, path:$path, mtime:$mtime}'
    fi
}

{
    # Rolling mode: <id>-<date>/ directly under the mount
    find "$DEST_ROOT" -maxdepth 1 -mindepth 1 -type d 2>/dev/null | while IFS= read -r d; do
        emit_entry "$d"
    done
    # Snapshot mode: <id>/<id>-<date>/
    find "$DEST_ROOT" -maxdepth 2 -mindepth 2 -type d 2>/dev/null | while IFS= read -r d; do
        emit_entry "$d"
    done
} | jq -s 'unique_by(.path) | sort_by(.id, .date) | {ok:true, backups:.}'
