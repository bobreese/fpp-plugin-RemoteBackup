#!/bin/bash
PLUGINDIR="$(cd "$(dirname "$0")/.." && pwd)"
nohup "${PLUGINDIR}/scripts/run_backup.sh" > /dev/null 2>&1 &
echo "Remote Backup started"
