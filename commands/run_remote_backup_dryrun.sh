#!/bin/bash
PLUGINDIR="$(cd "$(dirname "$0")/.." && pwd)"
nohup "${PLUGINDIR}/scripts/run_backup.sh" --dry-run > /dev/null 2>&1 &
echo "Remote Backup dry run started"
