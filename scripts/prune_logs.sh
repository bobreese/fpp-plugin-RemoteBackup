#!/bin/bash
# Applies the configured logRetentionCount to every remote's run logs
# immediately, rather than waiting for each remote's next backup run to
# prune its own. Run automatically by ajax.php right after saveSettings,
# so lowering the count actually reclaims disk space right away instead
# of only taking effect gradually as remotes happen to run again.
#
# Discovers remote ids from the log filenames themselves
# (data/logs/<id>-<runId>.log), not from settings.json's remotes list -
# this also cleans up logs for a remote that was since removed from
# Config, rather than leaving them behind forever as orphaned dead weight.
#
# Output JSON: {"ok":true,"remotesPruned":N,"keep":N}

. "$(dirname "$0")/lib_common.sh"

KEEP=$(rb_setting '.logRetentionCount' '15')

# Recover each unique remote id from its run logs' filenames: strip the
# trailing "-YYYYMMDD-HHMMSS.log" runId/extension, which is always that
# exact shape (see run_backup.sh's RUN_ID), leaving just the id - however
# many hyphens the id itself contains.
IDS=$(
    cd "$LOG_DIR" 2>/dev/null && ls -1 -- *-[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]-[0-9][0-9][0-9][0-9][0-9][0-9].log 2>/dev/null \
        | sed -E 's/-[0-9]{8}-[0-9]{6}\.log$//' \
        | sort -u
)

COUNT=0
while IFS= read -r rid; do
    [ -z "$rid" ] && continue
    rb_prune_remote_logs "$rid" "$KEEP"
    COUNT=$((COUNT + 1))
done <<< "$IDS"

jq -n --argjson count "$COUNT" --argjson keep "$KEEP" '{ok: true, remotesPruned: $count, keep: $keep}'
