#!/bin/bash
# Mount a detected-but-unmounted USB device at /mnt/Backups and (unless
# told not to) persist it in /etc/fstab by UUID with nofail, so it comes
# back after a reboot without hanging boot if unplugged.
#
# Usage: mount_usb.sh <device path e.g. /dev/sda1> [--no-fstab]
# Output JSON: {"ok":true,"mountpoint":"/mnt/Backups","fstype":"...", "addedFstab":true}
#
# Requires root. On stock FPP images the "fpp" user has passwordless
# sudo, which is how FPP's own web UI performs privileged operations
# (network config, reboot, etc.) - we follow the same convention here.

. "$(dirname "$0")/lib_common.sh"

DEVICE="$1"
MOUNT_POINT="/mnt/Backups"
ADD_FSTAB=1
[ "$2" = "--no-fstab" ] && ADD_FSTAB=0

json_err() {
    printf '{"ok":false,"error":%s}\n' "$(printf '%s' "$1" | jq -Rs .)"
}

if [ -z "$DEVICE" ]; then
    json_err "No device given"
    exit 0
fi
if [ ! -b "$DEVICE" ]; then
    json_err "Not a block device: $DEVICE"
    exit 0
fi

FSTYPE=$(lsblk -no FSTYPE "$DEVICE" 2>/dev/null | head -1 | tr -d ' ')
UUID=$(lsblk -no UUID "$DEVICE" 2>/dev/null | head -1 | tr -d ' ')

if [ -z "$FSTYPE" ]; then
    json_err "No filesystem found on $DEVICE. Format it first, e.g.: sudo mkfs.exfat -n Backups $DEVICE  (this erases the drive - back up anything on it first), then rescan."
    exit 0
fi

# Already mounted somewhere?
CURRENT_MP=$(lsblk -no MOUNTPOINT "$DEVICE" 2>/dev/null | head -1 | tr -d ' ')
if [ -n "$CURRENT_MP" ]; then
    if [ "$CURRENT_MP" = "$MOUNT_POINT" ]; then
        rb_log "mount_usb: $DEVICE already mounted at $MOUNT_POINT"
        jq -n --arg mp "$MOUNT_POINT" --arg fs "$FSTYPE" '{ok:true, mountpoint:$mp, fstype:$fs, alreadyMounted:true}'
        exit 0
    else
        json_err "$DEVICE is already mounted at $CURRENT_MP"
        exit 0
    fi
fi

sudo mkdir -p "$MOUNT_POINT" 2>&1
if ! sudo mount "$DEVICE" "$MOUNT_POINT" 2>/tmp/rb_mount_err_$$; then
    ERR=$(cat /tmp/rb_mount_err_$$ 2>/dev/null)
    rm -f /tmp/rb_mount_err_$$
    rb_log "mount_usb FAILED: $DEVICE -> $MOUNT_POINT : $ERR"
    json_err "mount failed: ${ERR:-unknown error}. If this is exFAT or NTFS, make sure exfat-fuse/exfatprogs or ntfs-3g is installed."
    exit 0
fi
rm -f /tmp/rb_mount_err_$$

sudo chown fpp:fpp "$MOUNT_POINT" 2>/dev/null || true
sudo chmod 0775 "$MOUNT_POINT" 2>/dev/null || true

ADDED_FSTAB=false
if [ "$ADD_FSTAB" = "1" ] && [ -n "$UUID" ]; then
    if ! grep -q "UUID=${UUID}" /etc/fstab 2>/dev/null; then
        echo "UUID=${UUID} ${MOUNT_POINT} auto nofail,x-systemd.device-timeout=10,uid=fpp,gid=fpp 0 0" | sudo tee -a /etc/fstab >/dev/null
        ADDED_FSTAB=true
        rb_log "mount_usb: added fstab entry for UUID=$UUID -> $MOUNT_POINT"
    fi
fi

rb_log "mount_usb: mounted $DEVICE ($FSTYPE) at $MOUNT_POINT (fstab=$ADDED_FSTAB)"
jq -n --arg mp "$MOUNT_POINT" --arg fs "$FSTYPE" --argjson fstab "$ADDED_FSTAB" \
    '{ok:true, mountpoint:$mp, fstype:$fs, addedFstab:$fstab}'
