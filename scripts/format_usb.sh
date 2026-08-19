#!/bin/bash
# Format an unmounted USB device and mount it at a fixed mountpoint
# (/mnt/Backups by default, the primary backup destination; pass a 4th
# arg to format/mount elsewhere instead - e.g. /mnt/BackupsCopy for the
# secondary "clone backups to a second drive" drive).
# THIS ERASES THE DEVICE. Multiple safety checks below are defense in
# depth on top of whatever confirmation the UI already required.
#
# Usage: format_usb.sh <device e.g. /dev/sda> <ext4|exfat> <confirm token> [mountpoint]
# The confirm token must be exactly I_UNDERSTAND_THIS_ERASES_THE_DRIVE.
# Output JSON: {"ok":true,"fstype":"ext4","mountpoint":"/mnt/Backups", ...}

. "$(dirname "$0")/lib_common.sh"

DEVICE="$1"
FSTYPE="${2:-ext4}"
CONFIRM="$3"
MOUNT_POINT="${4:-/mnt/Backups}"

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
# Check the WHOLE device tree (disk row + any partition rows), not
# just the disk row itself - once a drive has been through the
# partition-table fix below, /mnt/Backups is mounted from a child
# partition (e.g. /dev/sda1), not from $DEVICE's own disk row, and
# checking only the first lsblk line would miss that it's in use.
CURRENT_MP=$(lsblk -no MOUNTPOINT "$DEVICE" 2>/dev/null | grep -v '^[[:space:]]*$' | head -1 | tr -d ' ')
if [ -n "$CURRENT_MP" ]; then
    if [ "$CURRENT_MP" = "$MOUNT_POINT" ]; then
        rb_log "format_usb: re-formatting already-mounted $DEVICE, unmounting $MOUNT_POINT first"
        sudo umount "$MOUNT_POINT" 2>/tmp/rb_umount_err_$$
        if [ $? -ne 0 ]; then
            ERR=$(cat /tmp/rb_umount_err_$$ 2>/dev/null); rm -f /tmp/rb_umount_err_$$
            json_err "Could not unmount $MOUNT_POINT to re-format it: ${ERR:-in use?}"
            exit 0
        fi
        rm -f /tmp/rb_umount_err_$$
        if [ -f /etc/fstab ]; then
            sudo sed -i.rb-reformat-bak "\\#${MOUNT_POINT}#d" /etc/fstab 2>/dev/null || true
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

# FPP's own Settings > Storage dropdown and the File Copy Backup/
# Restore "Remote Storage" device picker both come from the same
# upstream function (GetAvailableBackupsDevices() in www/common.php),
# which only recognizes device names matching /^sd[a-z][0-9]/ - i.e. a
# PARTITION (like /dev/sda1), never a raw whole-disk device with no
# partition table. Previously this script ran mkfs directly on the
# whole disk ($DEVICE, e.g. /dev/sda), so FPP's own dropdowns could
# never see backups on it. Fix: create a GPT partition table with a
# single partition spanning the whole disk, and format/mount THAT
# partition instead.
rb_log "format_usb: wiping old signatures and creating GPT partition table on $DEVICE"
sudo wipefs -a "$DEVICE" >/tmp/rb_wipefs_err_$$ 2>&1
rm -f /tmp/rb_wipefs_err_$$
sudo partprobe "$DEVICE" 2>/dev/null || true
sleep 1

if ! command -v parted >/dev/null 2>&1; then
    rb_log "format_usb: installing parted"
    sudo apt-get install -y parted >/dev/null 2>&1
fi
if ! command -v parted >/dev/null 2>&1; then
    json_err "parted is not available and could not be installed automatically. Install parted manually and retry."
    exit 0
fi

if ! sudo parted -s "$DEVICE" mklabel gpt mkpart primary 0% 100% >/tmp/rb_parted_err_$$ 2>&1; then
    ERR=$(cat /tmp/rb_parted_err_$$ 2>/dev/null); rm -f /tmp/rb_parted_err_$$
    rb_log "format_usb FAILED (parted): $ERR"
    json_err "Creating partition table failed: ${ERR:-unknown error}"
    exit 0
fi
rm -f /tmp/rb_parted_err_$$

sudo partprobe "$DEVICE" 2>/dev/null || true
command -v udevadm >/dev/null 2>&1 && udevadm settle 2>/dev/null
sleep 1

# Resolve the actual partition device path rather than assuming a
# naming convention (usually /dev/sda1, but be robust to other bus
# naming like /dev/mmcblk0p1 or /dev/nvme0n1p1).
PARTITION=$(lsblk -no PATH -l "$DEVICE" 2>/dev/null | sed -n '2p' | tr -d ' ')
if [ -z "$PARTITION" ] || [ ! -b "$PARTITION" ]; then
    json_err "Partition table was created but the resulting partition device could not be found under $DEVICE. Try unplugging and reconnecting the drive, then retry."
    exit 0
fi
rb_log "format_usb: created partition $PARTITION on $DEVICE"

if [ "$FSTYPE" = "exfat" ]; then
    if ! command -v mkfs.exfat >/dev/null 2>&1; then
        rb_log "format_usb: installing exfatprogs"
        sudo apt-get install -y exfatprogs >/dev/null 2>&1 || sudo apt-get install -y exfat-utils >/dev/null 2>&1
    fi
    if ! command -v mkfs.exfat >/dev/null 2>&1; then
        json_err "mkfs.exfat is not available and could not be installed automatically. Install exfatprogs manually, or use ext4."
        exit 0
    fi
    if ! sudo mkfs.exfat -n Backups "$PARTITION" >/tmp/rb_mkfs_err_$$ 2>&1; then
        ERR=$(cat /tmp/rb_mkfs_err_$$ 2>/dev/null); rm -f /tmp/rb_mkfs_err_$$
        rb_log "format_usb FAILED (exfat): $ERR"
        json_err "mkfs.exfat failed: ${ERR:-unknown error}"
        exit 0
    fi
    rm -f /tmp/rb_mkfs_err_$$
else
    if ! sudo mkfs.ext4 -F -L Backups "$PARTITION" >/tmp/rb_mkfs_err_$$ 2>&1; then
        ERR=$(cat /tmp/rb_mkfs_err_$$ 2>/dev/null); rm -f /tmp/rb_mkfs_err_$$
        rb_log "format_usb FAILED (ext4): $ERR"
        json_err "mkfs.ext4 failed: ${ERR:-unknown error}"
        exit 0
    fi
    rm -f /tmp/rb_mkfs_err_$$
fi

sudo partprobe "$DEVICE" 2>/dev/null || true
sleep 1

# Hand off to mount_usb.sh for the mount + fstab step - using the
# PARTITION path, not the whole disk, so it matches FPP's own naming
# convention everywhere downstream (mount, fstab, and what FPP's
# native dropdowns will show). "" preserves the default add-to-fstab
# behavior (2nd arg is only ever the literal --no-fstab), and
# $MOUNT_POINT carries through whichever mountpoint THIS format run
# was for, primary or secondary.
MOUNT_RESULT=$(bash "$(dirname "$0")/mount_usb.sh" "$PARTITION" "" "$MOUNT_POINT")

# Formatting just wiped every backup that was on this drive. If it's
# also the currently configured destination storage, every per-remote
# data/status/*.json entry now points at a folder that no longer
# exists - clear them all so the Status page's Backup Status table and
# "Backed Up" dropdown stop showing stale/deleted backups instead of
# waiting for the next actual run to overwrite them. Compares against
# $MOUNT_POINT (not a hardcoded path) so re-formatting the secondary
# clone drive never touches the primary destination's status entries.
DEST_MOUNT_NOW=$(rb_setting '.destinationMount')
CLEARED=false
if echo "$MOUNT_RESULT" | jq -e '.ok == true' >/dev/null 2>&1 && [ "$DEST_MOUNT_NOW" = "$MOUNT_POINT" ]; then
    rb_log "format_usb: destination storage was just wiped/reformatted - clearing all backup status entries"
    rm -f "${STATUS_DIR}"/*.json
    CLEARED=true
fi

echo "$MOUNT_RESULT" | jq --argjson cleared "$CLEARED" '. + {clearedAllStatus: $cleared}'
