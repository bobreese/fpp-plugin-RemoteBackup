#!/bin/bash
# Applies the configured snapshotRetentionDays to every remote's existing
# dated snapshot folders immediately, rather than waiting for each
# remote's next backup run to prune its own. Run automatically by
# ajax.php right after saveSettings, mirroring prune_logs.sh's rationale
# for logRetentionCount - lowering the value should reclaim disk space
# right away, not only gradually as remotes happen to run again.
#
# Not to be confused with prune_snapshots.sh, which is a different,
# older feature: a one-time collapse-to-newest-only, run only when the
# user turns Snapshot Mode OFF and chooses "Prune to Latest Only" in
# Config's popup for that transition. This script instead applies an
# ongoing day-based retention window while Snapshot Mode stays ON.
#
# Discovers remote ids from the snapshot folder names themselves
# (<destRoot>/<id>-YYYYMMDD), not from settings.json's remotes list -
# this also cleans up snapshots for a remote that was since removed from
# Config, rather than leaving them behind forever as orphaned dead
# weight.
#
# A no-op (0 remotes pruned) when Snapshot Mode isn't even in use for any
# remote, or when snapshotRetentionDays is 0/unset - rb_prune_snapshot_
# history itself no-ops in that case, but the folder scan below is
# skipped outright too so an unconfigured/never-used destination is never
# even touched.
#
# Output JSON: {"ok":true,"remotesPruned":N,"days":N}

. "$(dirname "$0")/lib_common.sh"

DAYS=$(rb_setting '.snapshotRetentionDays' '0')
DEST_ROOT="$(rb_dest_root "$(rb_setting '.destinationMount' '/')")"

COUNT=0
if [ -d "$DEST_ROOT" ] && [ "$DAYS" -gt 0 ] 2>/dev/null; then
    # Strip the trailing "-YYYYMMDD" to recover just the id - however
    # many hyphens the id itself contains (mirrors prune_logs.sh's
    # runId-stripping sed, one field narrower since a snapshot folder
    # name has no time-of-day component).
    IDS=$(
        find "$DEST_ROOT" -maxdepth 1 -mindepth 1 -type d -name "*-[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]" 2>/dev/null \
            | xargs -n1 basename 2>/dev/null \
            | sed -E 's/-[0-9]{8}$//' \
            | sort -u
    )

    while IFS= read -r rid; do
        [ -z "$rid" ] && continue
        rb_prune_snapshot_history "$rid" "$DAYS" "$DEST_ROOT"
        COUNT=$((COUNT + 1))
    done <<< "$IDS"
fi

jq -n --argjson count "$COUNT" --argjson days "$DAYS" '{ok: true, remotesPruned: $count, days: $days}'
