#!/bin/bash
PLUGINDIR="$(cd "$(dirname "$0")/.." && pwd)"
. "${PLUGINDIR}/scripts/lib_common.sh"

# Best-effort check so a Scheduler-triggered run that can't actually start
# says so right here, in FPP's own command output, instead of always
# claiming "started" regardless. scripts/run_backup.sh has the
# authoritative, race-free guard (a flock on data/run.lock) that actually
# prevents two runs from overlapping - this is just about giving an
# honest answer in the one place a Scheduler user would look.
if [ -f "${DATA_DIR}/run_active.json" ] && [ "$(jq -r '.active // false' "${DATA_DIR}/run_active.json" 2>/dev/null)" = "true" ]; then
    echo "Remote Backup Dry Run NOT started: a backup run is already in progress. Check the Status page (or data/logs/engine.log) for details."
    exit 1
fi

nohup "${PLUGINDIR}/scripts/run_backup.sh" --dry-run > /dev/null 2>&1 &
echo "Remote Backup dry run started"
