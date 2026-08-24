#!/bin/bash
# fpp-plugin-RemoteBackup uninstall script
#
# FPP deletes this plugin's own directory (scripts/, data/, commands/,
# the PHP pages, etc.) as part of its normal uninstall flow after this
# script runs, so none of that needs handling here. This script's job
# is everything the plugin created OUTSIDE its own directory:
#   - the dedicated SSH keypair under ~fpp/.ssh
#   - the external settings.json backup under /home/fpp/media
#   - the optional restore-visibility bind mount, if currently active
#   - the /etc/fstab entries added when a USB drive was mounted via the
#     Config page's "Mount as Backups" / "Format & Mount" buttons
#     (primary destination and/or secondary/clone drive)
#   - any backup, or clone-to-second-drive, still actively running
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

# clone_backups.sh tracks its own PID at data/clone.pid, deliberately kept
# out of data/pids/ (see its own comment - out of the generic per-remote
# 'stop' action's glob), so it needs its own check here too - otherwise an
# uninstall mid-clone would leave that process orphaned once FPP deletes
# the plugin's script files out from under it moments later.
CLONE_PID_FILE="${PLUGINDIR}/data/clone.pid"
if [ -f "$CLONE_PID_FILE" ]; then
    clone_pid=$(cat "$CLONE_PID_FILE" 2>/dev/null)
    if [ -n "$clone_pid" ] && kill -0 "$clone_pid" 2>/dev/null; then
        echo "Stopping in-progress clone (pid $clone_pid)"
        kill "$clone_pid" 2>/dev/null || true
    fi
fi
pkill -f "${PLUGINDIR}/scripts/clone_backups.sh" 2>/dev/null || true

# --- Remove the dedicated SSH keypair created at install ----------------
KEYFILE="/home/fpp/.ssh/id_rsa_remotebackup"
if [ -f "$KEYFILE" ]; then
    echo "Removing SSH key: $KEYFILE (and .pub)"
    rm -f "$KEYFILE" "${KEYFILE}.pub"
fi
echo "Note: that key's public half may still be listed in each remote's"
echo "~fpp/.ssh/authorized_keys. That's harmless (nothing will use it"
echo "anymore) but remove it there too if you want it fully gone."

# --- Remove the external settings.json backup ----------------------------
# Deliberately kept outside PLUGINDIR (see ajax.php's
# rb_settings_external_backup_path()/lib_common.sh's
# SETTINGS_EXTERNAL_BACKUP for why) specifically so FPP's own delete of
# this plugin's directory can't take it out along with everything else -
# which means it's this uninstall script's job to clean it up, not FPP's.
EXTERNAL_SETTINGS_BACKUP="/home/fpp/media/.fpp-plugin-RemoteBackup-settings.bak"
if [ -f "$EXTERNAL_SETTINGS_BACKUP" ]; then
    echo "Removing settings backup: $EXTERNAL_SETTINGS_BACKUP"
    rm -f "$EXTERNAL_SETTINGS_BACKUP"
fi

# --- Tear down the optional restore-visibility bind mount, if active ----
# The "see current backups without unmounting" toggle (see lib_common.sh's
# rb_bindmount_backups_ensure()) bind-mounts /mnt/Backups onto
# /home/fpp/media/backups while it's on. That's kernel mount-table state
# outside this plugin's own directory, so it's this uninstall script's job
# to undo it - not sourcing lib_common.sh for one function (it has
# unrelated side effects at source time, like recreating data/ dirs FPP is
# about to delete anyway), just the same plain check-and-unmount directly.
if mountpoint -q /home/fpp/media/backups 2>/dev/null; then
    echo "Un-binding /home/fpp/media/backups (restore-visibility bind mount)"
    umount /home/fpp/media/backups 2>/dev/null \
        || echo "WARNING: could not un-bind /home/fpp/media/backups automatically - unmount it yourself if still bound"
fi

# --- Remove the /etc/fstab entries for both backup drives, if present ---
# Primary (/mnt/Backups) and secondary/clone (/mnt/BackupsCopy) each get
# their own fstab line from mount_usb.sh - handled explicitly as two
# separate mountpoints, each matched as a whole whitespace-delimited field
# (anchored on both sides), not a bare substring: "/mnt/Backups" is itself
# a substring of "/mnt/BackupsCopy", so an unanchored match on the primary
# would also silently delete the secondary's line in the same pass (and
# vice versa isn't a risk, but relying on that accident either way is
# fragile - this stays correct even if either path's naming ever changes
# independently of the other).
for MP in /mnt/Backups /mnt/BackupsCopy; do
    if [ -f /etc/fstab ] && grep -qE "(^|[[:space:]])${MP}([[:space:]]|$)" /etc/fstab 2>/dev/null; then
        echo "Removing $MP entry from /etc/fstab"
        echo "(the drive stays mounted until you unmount/reboot; files untouched)"
        # No sudo here - uninstall scripts already run as root (FPP's own
        # lifecycle), so sudo would just be redundant indirection.
        # \#pattern#d (not the usual /pattern/d) - $MP itself contains "/",
        # which would otherwise collide with "/" as the address delimiter
        # and break the command (confirmed by hitting exactly that while
        # testing this).
        sed -i.rb-uninstall-bak -E "\\#(^|[[:space:]])${MP}([[:space:]]|\$)#d" /etc/fstab 2>/dev/null \
            || echo "WARNING: could not edit /etc/fstab automatically - remove the $MP line yourself"
    fi
done

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
#
# Covers BOTH the primary destination AND the secondary/clone drive
# (/mnt/BackupsCopy, always fixed - never a settings.json value) - the
# clone feature keeps its own full copy of every backup, so a
# --purge-backups run that only touched the primary would silently leave
# a complete second copy behind on the clone drive, defeating the point
# of --purge-backups in the first place.
NAME_RE='^.+-[0-9]{8}$'
rb_scan_backup_dirs() {
    local root="$1"
    [ -n "$root" ] && [ -d "$root" ] || return 0
    local d
    while IFS= read -r d; do
        [ -n "$d" ] && [[ "$(basename "$d")" =~ $NAME_RE ]] && BACKUP_DIRS+=("$d")
    done < <(find "$root" -maxdepth 1 -mindepth 1 -type d 2>/dev/null)
    while IFS= read -r d; do
        [ -n "$d" ] && [[ "$(basename "$d")" =~ $NAME_RE ]] && BACKUP_DIRS+=("$d")
    done < <(find "$root" -maxdepth 2 -mindepth 2 -type d 2>/dev/null)
}
BACKUP_DIRS=()
rb_scan_backup_dirs "$DEST_MOUNT"
# Clone-safety-checks elsewhere in this plugin already refuse to let the
# two destinations be the same drive or nested in one another, but skip
# re-scanning here too on the off chance $DEST_MOUNT already IS
# /mnt/BackupsCopy (e.g. an unusual manual settings.json edit) - cheap
# insurance against double-counting/double-deleting the same folder.
if [ "$DEST_MOUNT" != "/mnt/BackupsCopy" ]; then
    rb_scan_backup_dirs "/mnt/BackupsCopy"
fi

if [ "$PURGE_BACKUPS" = "1" ] && [ "${#BACKUP_DIRS[@]}" -gt 0 ]; then
    echo "!! --purge-backups given: deleting ${#BACKUP_DIRS[@]} backup folder(s) (primary destination and, if present, the secondary/clone drive)"
    for d in "${BACKUP_DIRS[@]}"; do
        echo "   rm -rf $d"
        rm -rf "$d"
        # Snapshot mode leaves an empty "<id>/" parent behind once its
        # last dated snapshot is gone - clean that up too if so, but never
        # the destination root itself.
        p=$(dirname "$d")
        if [ "$p" != "$DEST_MOUNT" ] && [ "$p" != "/mnt/BackupsCopy" ] && [ -d "$p" ] && [ -z "$(ls -A "$p" 2>/dev/null)" ]; then
            rmdir "$p" 2>/dev/null || true
        fi
    done
elif [ "${#BACKUP_DIRS[@]}" -gt 0 ]; then
    echo "------------------------------------------------------------------"
    echo " Your backed-up files were left in place and were NOT deleted"
    echo " (primary destination and, if present, the secondary/clone drive):"
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
for pkg in rsync jq openssh-client sshpass curl parted zip exfatprogs; do
    if dpkg -s "$pkg" >/dev/null 2>&1; then
        echo "  $pkg: still installed - untouched by this uninstall"
    else
        echo "  $pkg: not installed on this system (either never installed, or removed by you separately - not by this uninstall)"
    fi
done
echo "The /mnt/Backups and /mnt/BackupsCopy mount points themselves were also left alone -"
echo "only their /etc/fstab persistence entries were removed above, if present."
echo ""
echo "If any FPP Playlist/Schedule/Event used this plugin's 'Run Remote"
echo "Backup' or 'Run Remote Backup Dry Run' commands, remove those"
echo "references yourself - they'll no longer do anything."
