#!/bin/bash
# Removes leftover backup folders under the SD Card/System Storage
# fallback's own dedicated directory (RB_SDCARD_FALLBACK_DIR) - offered
# on the Config page when the destination is switched AWAY from "/",
# since that's the one destination whose leftover backups quietly eat
# into the Host's own limited system storage indefinitely if nobody
# ever cleans them up (a real external drive being swapped out already
# physically leaves with its data either way, so there's nothing to
# offer there).
#
# Reuses the exact same folder-naming-pattern safety check
# fpp_uninstall.sh's own --purge-backups flag already uses: only ever
# removes something that actually looks like one of this plugin's
# backups (<id>-<date>), never a blind rm -rf of the whole directory.
# Deliberately NOT delete_backup.sh - that script only trusts the
# CURRENTLY-configured destinationMount as its safety boundary and
# would refuse to touch this path the moment the destination has
# already been switched away from it, which is exactly the situation
# this script exists for.
#
# Usage: purge_sdcard_backups.sh <confirm token>
# The confirm token must be exactly I_UNDERSTAND_THIS_DELETES_THE_BACKUPS.
# Output JSON: {"ok":true,"purged":N}

. "$(dirname "$0")/lib_common.sh"

CONFIRM="$1"
if [ "$CONFIRM" != "I_UNDERSTAND_THIS_DELETES_THE_BACKUPS" ]; then
    printf '{"ok":false,"error":"Missing/incorrect confirmation token; refusing to delete."}\n'
    exit 0
fi

ROOT="$RB_SDCARD_FALLBACK_DIR"
if [ ! -d "$ROOT" ]; then
    echo '{"ok":true,"purged":0}'
    exit 0
fi

# Same dual-depth scan as fpp_uninstall.sh's --purge-backups: rolling-mode
# backups live directly under $ROOT, but a snapshot-mode backup made
# before this plugin flattened that layout can still be nested one level
# under its own "<id>/" container folder - covering both means nothing
# pre-existing is left behind uncounted.
NAME_RE='^.+-[0-9]{8}$'
BACKUP_DIRS=()
while IFS= read -r d; do
    [ -n "$d" ] && [[ "$(basename "$d")" =~ $NAME_RE ]] && BACKUP_DIRS+=("$d")
done < <(find "$ROOT" -maxdepth 1 -mindepth 1 -type d 2>/dev/null)
while IFS= read -r d; do
    [ -n "$d" ] && [[ "$(basename "$d")" =~ $NAME_RE ]] && BACKUP_DIRS+=("$d")
done < <(find "$ROOT" -maxdepth 2 -mindepth 2 -type d 2>/dev/null)

COUNT=0
for d in "${BACKUP_DIRS[@]}"; do
    rm -rf "$d"
    rb_log "purge_sdcard_backups: removed $d"
    COUNT=$((COUNT + 1))
    # Snapshot mode leaves an empty "<id>/" parent behind once its last
    # dated snapshot is gone - clean that up too if so, but never $ROOT
    # itself.
    p=$(dirname "$d")
    if [ "$p" != "$ROOT" ] && [ -d "$p" ] && [ -z "$(ls -A "$p" 2>/dev/null)" ]; then
        rmdir "$p" 2>/dev/null || true
    fi
done

jq -n --argjson n "$COUNT" '{ok:true, purged:$n}'
