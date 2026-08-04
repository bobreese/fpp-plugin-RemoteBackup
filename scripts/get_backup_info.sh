#!/bin/bash
# Details for one specific backup folder chosen from the "Backed Up"
# dropdown: total size, file count, and a top-level directory listing.
# Usage: get_backup_info.sh <path>
# Output JSON: {"ok":true,"path":...,"sizeBytes":N,"fileCount":N,"entries":[{name,isDir,sizeBytes}...]}

. "$(dirname "$0")/lib_common.sh"

REQ_PATH="$1"

json_err() {
    printf '{"ok":false,"error":%s}\n' "$(printf '%s' "$1" | jq -Rs .)"
}

# Coerce arbitrary/possibly-empty shell output into a safe non-negative
# integer string, never anything jq's --argjson could choke on.
safe_int() {
    local v="$1"
    if [[ "$v" =~ ^[0-9]+$ ]]; then
        echo "$v"
    else
        echo 0
    fi
}

if [ -z "$REQ_PATH" ]; then
    json_err "No path given"
    exit 0
fi

DEST_MOUNT=$(rb_setting '.destinationMount')
if [ -z "$DEST_MOUNT" ] || [ ! -d "$DEST_MOUNT" ]; then
    json_err "No destination storage configured/mounted"
    exit 0
fi
# Backups live directly under the mount now (no "RemoteBackup" subfolder).
DEST_ROOT_REAL=$(realpath "${DEST_MOUNT%/}" 2>/dev/null)
TARGET_REAL=$(realpath "$REQ_PATH" 2>/dev/null)

# Refuse anything that doesn't resolve to strictly inside the backup
# root - the dropdown only ever sends us paths we generated ourselves,
# but this is cheap insurance against a tampered request.
if [ -z "$TARGET_REAL" ] || [ -z "$DEST_ROOT_REAL" ] || [ ! -d "$TARGET_REAL" ] || \
   [[ "$TARGET_REAL" != "$DEST_ROOT_REAL"/* ]]; then
    json_err "Path is not a valid backup under the configured destination storage"
    exit 0
fi

SIZE_BYTES=$(safe_int "$(du -sb "$TARGET_REAL" 2>/dev/null | awk '{print $1}')")
FILE_COUNT=$(safe_int "$(find "$TARGET_REAL" -type f 2>/dev/null | wc -l)")

# Build the top-level listing as tab-separated "isDir<TAB>sizeBytes<TAB>name"
# lines and hand the WHOLE thing to a single jq invocation to parse -
# rather than shelling out to jq once per entry with a bash-computed
# number spliced into --argjson, which is exactly what broke on
# whatever edge-case file/dir tripped up `du`/`stat` here (a du/stat
# hiccup on one entry doesn't need to take down the whole listing).
ENTRIES=$(find "$TARGET_REAL" -maxdepth 1 -mindepth 1 2>/dev/null | while IFS= read -r e; do
    name=$(basename "$e")
    if [ -d "$e" ]; then
        sz=$(safe_int "$(du -sb "$e" 2>/dev/null | awk '{print $1}')")
        printf '1\t%s\t%s\n' "$sz" "$name"
    else
        sz=$(safe_int "$(stat -c '%s' "$e" 2>/dev/null)")
        printf '0\t%s\t%s\n' "$sz" "$name"
    fi
done | jq -R -s '
    (split("\n") | map(select(length > 0)))
    | map(split("\t"))
    | map(select(length >= 3))
    | map({
        isDir: (.[0] == "1"),
        sizeBytes: (.[1] | tonumber? // 0),
        name: (.[2:] | join("\t"))
      })
    | sort_by((.isDir | not), .name)
')
[ -z "$ENTRIES" ] && ENTRIES="[]"
echo "$ENTRIES" | jq -e . >/dev/null 2>&1 || ENTRIES="[]"

jq -n --arg path "$TARGET_REAL" --argjson size "$SIZE_BYTES" --argjson files "$FILE_COUNT" --argjson entries "$ENTRIES" \
    '{ok:true, path:$path, sizeBytes:$size, fileCount:$files, entries:$entries}'
