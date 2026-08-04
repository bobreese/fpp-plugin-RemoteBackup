#!/bin/bash
# Unmount the Remote Backup destination drive from /mnt/Backups and drop
# its /etc/fstab entry, so it can be safely detached. This only changes
# the running system's view of the drive - it does NOT touch any data on
# it, and any backups already on the drive are untouched.
#
# Only ever operates on our fixed, plugin-managed mount point - never an
# arbitrary path - so there is no way for this to unmount the OS's own
# root/boot filesystem.
#
# Usage: unmount_usb.sh
# Output JSON: {"ok":true,"mountpoint":"/mnt/Backups","device":"/dev/sda","removedFstab":true|false}

. "$(dirname "$0")/lib_common.sh"

MOUNT_POINT="/mnt/Backups"

json_err() {
    printf '{"ok":false,"error":%s}\n' "$(printf '%s' "$1" | jq -Rs .)"
}

if ! mountpoint -q "$MOUNT_POINT" 2>/dev/null; then
    json_err "$MOUNT_POINT is not currently mounted - nothing to do."
    exit 0
fi

DEVICE=$(findmnt -n -o SOURCE "$MOUNT_POINT" 2>/dev/null || echo "")

if ! sudo umount "$MOUNT_POINT" 2>/tmp/rb_umount_err_$$; then
    ERR=$(cat /tmp/rb_umount_err_$$ 2>/dev/null)
    rm -f /tmp/rb_umount_err_$$
    rb_log "unmount_usb FAILED for $MOUNT_POINT ($DEVICE): $ERR"
    json_err "Could not unmount $MOUNT_POINT: ${ERR:-still in use? stop any running backup first, and close any file browser/terminal with it open}"
    exit 0
fi
rm -f /tmp/rb_umount_err_$$

REMOVED_FSTAB=false
if [ -f /etc/fstab ] && grep -q "$MOUNT_POINT" /etc/fstab 2>/dev/null; then
    sudo sed -i.rb-unmount-bak '\#/mnt/Backups#d' /etc/fstab 2>/dev/null || true
    REMOVED_FSTAB=true
    rb_log "unmount_usb: removed fstab entry for $MOUNT_POINT"
fi

rb_log "unmount_usb: unmounted $DEVICE from $MOUNT_POINT (removedFstab=$REMOVED_FSTAB)"
jq -n --arg mp "$MOUNT_POINT" --arg dev "$DEVICE" --argjson removedFstab "$REMOVED_FSTAB" \
    '{ok:true, mountpoint:$mp, device:$dev, removedFstab:$removedFstab}'
