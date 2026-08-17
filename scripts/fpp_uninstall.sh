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
DEST_MOUNT=""
if [ -f "${PLUGINDIR}/data/settings.json" ] && command -v jq >/dev/null 2>&1; then
    DEST_MOUNT=$(jq -r '.destinationMount // empty' "${PLUGINDIR}/data/settings.json" 2>/dev/null)
    # "/" (the SD-card/system-storage fallback's mountpoint) is special-cased
    # the same way run_backup.sh's rb_dest_root() does: backups under that
    # choice actually live in a dedicated subfolder, never at "/" itself -
    # see lib_common.sh for the full rationale.
    if [ "$DEST_MOUNT" = "/" ]; then
        DEST_MOUNT="/home/fpp/media/backups"
    else
        DEST_MOUNT="${DEST_MOUNT%/}"
    fi
fi

# Backups live directly under the destination mount (no dedicated
# "RemoteBackup" subfolder to treat as "everything here is ours" and
# rm -rf as a whole - the mount can also hold whatever else was already
# on that drive). So find and only ever touch directories that actually
# look like one of our backups: same <id>-<date> naming pattern used by
# list_backups.sh/delete_backup.sh, covering both rolling (direct child
# of the mount) and snapshot mode (nested one level under <id>/).
BACKUP_DIRS=()
if [ -n "$DEST_MOUNT" ] && [ -d "$DEST_MOUNT" ]; then
    NAME_RE='^.+-[0-9]{8}$'
    while IFS= read -r d; do
        [ -n "$d" ] && [[ "$(basename "$d")" =~ $NAME_RE ]] && BACKUP_DIRS+=("$d")
    done < <(find "$DEST_MOUNT" -maxdepth 1 -mindepth 1 -type d 2>/dev/null)
    while IFS= read -r d; do
        [ -n "$d" ] && [[ "$(basename "$d")" =~ $NAME_RE ]] && BACKUP_DIRS+=("$d")
    done < <(find "$DEST_MOUNT" -maxdepth 2 -mindepth 2 -type d 2>/dev/null)
fi

if [ "$PURGE_BACKUPS" = "1" ] && [ "${#BACKUP_DIRS[@]}" -gt 0 ]; then
    echo "!! --purge-backups given: deleting ${#BACKUP_DIRS[@]} backup folder(s) under $DEST_MOUNT"
    for d in "${BACKUP_DIRS[@]}"; do
        echo "   rm -rf $d"
        rm -rf "$d"
        # Snapshot mode leaves an empty "<id>/" parent behind once its
        # last dated snapshot is gone - clean that up too if so.
        p=$(dirname "$d")
        if [ "$p" != "$DEST_MOUNT" ] && [ -d "$p" ] && [ -z "$(ls -A "$p" 2>/dev/null)" ]; then
            rmdir "$p" 2>/dev/null || true
        fi
    done
elif [ "${#BACKUP_DIRS[@]}" -gt 0 ]; then
    echo "------------------------------------------------------------------"
    echo " Your backed-up files were left in place and were NOT deleted:"
    for d in "${BACKUP_DIRS[@]}"; do
        echo "   $d"
    done
    echo " Re-run this script by hand with --purge-backups to delete them."
    echo "------------------------------------------------------------------"
fi

echo ""
echo "Remote Backup plugin removed."
echo ""
# Don't just assert these were left alone - prove it, right here in the
# uninstall log, by actually checking dpkg. This plugin's pluginInfo.json
# deliberately declares zero "dependencies.packages" (see fpp_install.sh
# for why), so FPP's own package ref-counting step - which runs BEFORE
# this script and would otherwise apt-get remove a package once no
# plugin/user still claims it - had nothing to act on. exfatprogs was
# never declared anywhere and is included here for the same reason.
echo "System packages (checked just now, not just asserted):"
for pkg in rsync jq openssh-client sshpass curl exfatprogs; do
    if dpkg -s "$pkg" >/dev/null 2>&1; then
        echo "  $pkg: still installed - untouched by this uninstall"
    else
        echo "  $pkg: not installed on this system (either never installed, or removed by you separately - not by this uninstall)"
    fi
done
echo "The /mnt/Backups mount point itself was also left alone."
echo ""
echo "If any FPP Playlist/Schedule/Event used this plugin's 'Run Remote"
echo "Backup' or 'Run Remote Backup Dry Run' commands, remove those"
echo "references yourself - they'll no longer do anything."
