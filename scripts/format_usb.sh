#!/bin/bash
# Format an unmounted USB device and mount it at /mnt/Backups.
# THIS ERASES THE DEVICE. Multiple safety checks below are defense in
# depth on top of whatever confirmation the UI already required.
#
# Usage: format_usb.sh <device e.g. /dev/sda> <ext4|exfat> <confirm token>
# The confirm token must be exactly I_UNDERSTAND_THIS_ERASES_THE_DRIVE.
# Output JSON: {"ok":true,"fstype":"ext4","mountpoint":"/mnt/Backups", ...}

. "$(dirname "$0")/lib_common.sh"

DEVICE="$1"
FSTYPE="${2:-ext4}"
CONFIRM="$3"

json_err() {
    printf '{"ok":false,"error":%s}\n' "$(printf '%s' "$1" | jq -Rs .)"
}

if [ "$CONFIRM" != "I_UNDERSTAND_THIS_ERASES_THE_DRIVE" ]; then
    json_err "Missing/incorrect confirmation token; refusing to format."
    exit 0
fi
if [ -z "$DEVICE" ] || [ ! -b "$DEVICE" ]; then
    json_err "Not a block device: $DEVICE"
    exit 0
fi
if [ "$FSTYPE" != "ext4" ] && [ "$FSTYPE" != "exfat" ]; then
    json_err "Unsupported filesystem: $FSTYPE (use ext4 or exfat)"
    exit 0
fi

# Never touch the disk FPP itself is running from.
ROOT_SRC=$(findmnt -n -o SOURCE / 2>/dev/null || echo "")
ROOT_DISK_NAME=$(lsblk -no PKNAME "$ROOT_SRC" 2>/dev/null || lsblk -no NAME "$ROOT_SRC" 2>/dev/null || echo "")
DEV_NAME=$(basename "$DEVICE")
DEV_DISK_NAME=$(lsblk -no PKNAME "$DEVICE" 2>/dev/null || echo "")
if [ -n "$ROOT_DISK_NAME" ] && { [ "$DEV_NAME" = "$ROOT_DISK_NAME" ] || [ "$DEV_DISK_NAME" = "$ROOT_DISK_NAME" ]; }; then
    json_err "Refusing to format $DEVICE - it looks like the system/SD card FPP is running from."
    exit 0
fi

# If it's mounted at OUR managed mount point (i.e. this is a
# re-format of the existing Backups drive), unmount it and drop its
# fstab entry first rather than refusing outright. If it's mounted
# somewhere else - some other drive the user has for unrelated
# purposes - refuse; we have no business touching that.
CURRENT_MP=$(lsblk -no MOUNTPOINT "$DEVICE" 2>/dev/null | head -1 | tr -d ' ')
if [ -n "$CURRENT_MP" ]; then
    if [ "$CURRENT_MP" = "/mnt/Backups" ]; then
        rb_log "format_usb: re-formatting already-mounted $DEVICE, unmounting /mnt/Backups first"
        sudo umount /mnt/Backups 2>/tmp/rb_umount_err_$$
        if [ $? -ne 0 ]; then
            ERR=$(cat /tmp/rb_umount_err_$$ 2>/dev/null); rm -f /tmp/rb_umount_err_$$
            json_err "Could not unmount /mnt/Backups to re-format it: ${ERR:-in use?}"
            exit 0
        fi
        rm -f /tmp/rb_umount_err_$$
        if [ -f /etc/fstab ]; then
            sudo sed -i.rb-reformat-bak '\#/mnt/Backups#d' /etc/fstab 2>/dev/null || true
        fi
    else
        json_err "$DEVICE is currently mounted at $CURRENT_MP; unmount it first."
        exit 0
    fi
fi

# Only format devices FPP identified as USB-attached.
TRAN=$(lsblk -no TRAN "$DEVICE" 2>/dev/null | head -1 | tr -d ' ')
if [ "$TRAN" != "usb" ]; then
    json_err "Refusing to format $DEVICE - it is not reported as a USB device (tran=$TRAN)."
    exit 0
fi

rb_log "format_usb: formatting $DEVICE as $FSTYPE (confirmed)"

if [ "$FSTYPE" = "exfat" ]; then
    if ! command -v mkfs.exfat >/dev/null 2>&1; then
        rb_log "format_usb: installing exfatprogs"
        sudo apt-get install -y exfatprogs >/dev/null 2>&1 || sudo apt-get install -y exfat-utils >/dev/null 2>&1
    fi
    if ! command -v mkfs.exfat >/dev/null 2>&1; then
        json_err "mkfs.exfat is not available and could not be installed automatically. Install exfatprogs manually, or use ext4."
        exit 0
    fi
    if ! sudo mkfs.exfat -n Backups "$DEVICE" >/tmp/rb_mkfs_err_$$ 2>&1; then
        ERR=$(cat /tmp/rb_mkfs_err_$$ 2>/dev/null); rm -f /tmp/rb_mkfs_err_$$
        rb_log "format_usb FAILED (exfat): $ERR"
        json_err "mkfs.exfat failed: ${ERR:-unknown error}"
        exit 0
    fi
    rm -f /tmp/rb_mkfs_err_$$
else
    if ! sudo mkfs.ext4 -F -L Backups "$DEVICE" >/tmp/rb_mkfs_err_$$ 2>&1; then
        ERR=$(cat /tmp/rb_mkfs_err_$$ 2>/dev/null); rm -f /tmp/rb_mkfs_err_$$
        rb_log "format_usb FAILED (ext4): $ERR"
        json_err "mkfs.ext4 failed: ${ERR:-unknown error}"
        exit 0
    fi
    rm -f /tmp/rb_mkfs_err_$$
fi

sudo partprobe "$DEVICE" 2>/dev/null || true
sleep 1

# Hand off to mount_usb.sh for the mount + fstab step.
MOUNT_RESULT=$(bash "$(dirname "$0")/mount_usb.sh" "$DEVICE")

# Formatting just wiped every backup that was on this drive. If it's
# also the currently configured destination storage, every per-remote
# data/status/*.json entry now points at a folder that no longer
# exists - clear them all so the Status page's Backup Status table and
# "Backed Up" dropdown stop showing stale/deleted backups instead of
# waiting for the next actual run to overwrite them.
DEST_MOUNT_NOW=$(rb_setting '.destinationMount')
CLEARED=false
if echo "$MOUNT_RESULT" | jq -e '.ok == true' >/dev/null 2>&1 && [ "$DEST_MOUNT_NOW" = "/mnt/Backups" ]; then
    rb_log "format_usb: destination storage was just wiped/reformatted - clearing all backup status entries"
    rm -f "${STATUS_DIR}"/*.json
    CLEARED=true
fi

echo "$MOUNT_RESULT" | jq --argjson cleared "$CLEARED" '. + {clearedAllStatus: $cleared}'
