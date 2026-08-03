#!/bin/bash
# fpp-plugin-RemoteBackup uninstall script
#
# FPP deletes this plugin's own directory (scripts/, data/, commands/,
# the PHP pages, etc.) as part of its normal uninstall flow after this
# script runs, so none of that needs handling here. This script's job
# is everything the plugin created OUTSIDE its own directory:
#   - the dedicated SSH keypair under ~fpp/.ssh
#   - the /etc/fstab entry added when a USB drive was mounted via the
#     Config page's "Mount as Backups" / "Format & Mount" buttons
#   - any backup still actively running
#
# Backed-up files on your destination storage are left in place by
# default - uninstalling a backup tool should never be how you lose
# your backups. Run this manually with --purge-backups if you really
# want them deleted too (FPP's own uninstall flow never passes that).

PLUGINDIR="$(cd "$(dirname "$0")/.." && pwd)"
PURGE_BACKUPS=0
[ "$1" = "--purge-backups" ] && PURGE_BACKUPS=1

echo "=================================================================="
echo " Remote Backup plugin - uninstalling"
echo "=================================================================="

# --- Stop anything currently running ------------------------------------
if [ -d "${PLUGINDIR}/data/pids" ]; then
    for pf in "${PLUGINDIR}/data/pids"/*.pid; do
        [ -f "$pf" ] || continue
        pid=$(cat "$pf" 2>/dev/null)
        if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
            echo "Stopping in-progress backup (pid $pid)"
            kill "$pid" 2>/dev/null || true
        fi
    done
fi
pkill -f "${PLUGINDIR}/scripts/run_backup.sh" 2>/dev/null || true

# --- Remove the dedicated SSH keypair created at install ----------------
KEYFILE="/home/fpp/.ssh/id_rsa_remotebackup"
if [ -f "$KEYFILE" ]; then
    echo "Removing SSH key: $KEYFILE (and .pub)"
    rm -f "$KEYFILE" "${KEYFILE}.pub"
fi
echo "Note: that key's public half may still be listed in each remote's"
echo "~fpp/.ssh/authorized_keys. That's harmless (nothing will use it"
echo "anymore) but remove it there too if you want it fully gone."

# --- Remove the /etc/fstab entry for the USB backup drive, if present ---
if [ -f /etc/fstab ] && grep -q "/mnt/Backups" /etc/fstab 2>/dev/null; then
    echo "Removing /mnt/Backups entry from /etc/fstab"
    echo "(the drive stays mounted until you unmount/reboot; files untouched)"
    sudo sed -i.rb-uninstall-bak '\#/mnt/Backups#d' /etc/fstab 2>/dev/null \
        || echo "WARNING: could not edit /etc/fstab automatically - remove the /mnt/Backups line yourself"
fi

# --- Figure out where backups live before settings.json disappears -----
DEST_ROOT=""
if [ -f "${PLUGINDIR}/data/settings.json" ] && command -v jq >/dev/null 2>&1; then
    DEST_MOUNT=$(jq -r '.destinationMount // empty' "${PLUGINDIR}/data/settings.json" 2>/dev/null)
    [ -n "$DEST_MOUNT" ] && DEST_ROOT="${DEST_MOUNT%/}/RemoteBackup"
fi

if [ "$PURGE_BACKUPS" = "1" ] && [ -n "$DEST_ROOT" ] && [ -d "$DEST_ROOT" ]; then
    echo "!! --purge-backups given: deleting $DEST_ROOT"
    rm -rf "$DEST_ROOT"
elif [ -n "$DEST_ROOT" ] && [ -d "$DEST_ROOT" ]; then
    echo "------------------------------------------------------------------"
    echo " Your backed-up files were left in place and were NOT deleted:"
    echo "   $DEST_ROOT"
    echo " Re-run this script by hand with --purge-backups to delete them."
    echo "------------------------------------------------------------------"
fi

echo ""
echo "Remote Backup plugin removed."
echo "Left alone on purpose (shared with the rest of the system, not"
echo "specific to this plugin): the rsync/jq/openssh-client/sshpass/"
echo "exfatprogs packages, and the /mnt/Backups mount point itself."
echo ""
echo "If any FPP Playlist/Schedule/Event used this plugin's 'Run Remote"
echo "Backup' or 'Run Remote Backup Dry Run' commands, remove those"
echo "references yourself - they'll no longer do anything."
