#!/bin/bash
# Mount a detected-but-unmounted USB device at a fixed mountpoint
# (/mnt/Backups by default, the primary backup destination; pass a 3rd
# arg to mount elsewhere instead - e.g. /mnt/BackupsCopy for the
# secondary "clone backups to a second drive" drive) and (unless told
# not to) persist it in /etc/fstab by UUID with nofail, so it comes
# back after a reboot without hanging boot if unplugged.
#
# Usage: mount_usb.sh <device path e.g. /dev/sda1> [--no-fstab] [mountpoint]
# Output JSON: {"ok":true,"mountpoint":"/mnt/Backups","fstype":"...", "addedFstab":true}
#
# Requires root. On stock FPP images the "fpp" user has passwordless
# sudo, which is how FPP's own web UI performs privileged operations
# (network config, reboot, etc.) - we follow the same convention here.

. "$(dirname "$0")/lib_common.sh"

DEVICE="$1"
ADD_FSTAB=1
[ "$2" = "--no-fstab" ] && ADD_FSTAB=0
MOUNT_POINT="${3:-/mnt/Backups}"

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

FPP_UID=$(id -u fpp 2>/dev/null || echo 1000)
FPP_GID=$(id -g fpp 2>/dev/null || echo 1000)

# Already mounted somewhere?
CURRENT_MP=$(lsblk -no MOUNTPOINT "$DEVICE" 2>/dev/null | head -1 | tr -d ' ')
if [ -n "$CURRENT_MP" ]; then
    if [ "$CURRENT_MP" = "$MOUNT_POINT" ]; then
        rb_log "mount_usb: $DEVICE already mounted at $MOUNT_POINT"

        # Don't just report success - a drive mounted before this fix
        # shipped (or mounted by something other than us) can be present
        # but not actually writable by fpp, which is invisible until a
        # backup run tries to write and fails. Check, and for FAT-family
        # filesystems try a self-healing remount before giving up.
        TESTFILE="${MOUNT_POINT}/.rb_write_test_$$"
        if sudo -u fpp touch "$TESTFILE" 2>/dev/null; then
            sudo -u fpp rm -f "$TESTFILE" 2>/dev/null
            jq -n --arg mp "$MOUNT_POINT" --arg fs "$FSTYPE" '{ok:true, mountpoint:$mp, fstype:$fs, alreadyMounted:true, writableByFpp:true}'
            exit 0
        fi

        case "$FSTYPE" in
            vfat|fat|fat32|exfat|ntfs|ntfs3)
                rb_log "mount_usb: $MOUNT_POINT already mounted but not writable by fpp; attempting remount with uid=$FPP_UID,gid=$FPP_GID"
                sudo mount -o "remount,uid=${FPP_UID},gid=${FPP_GID},umask=000" "$MOUNT_POINT" 2>/tmp/rb_mount_err_$$
                ;;
        esac
        if sudo -u fpp touch "$TESTFILE" 2>/dev/null; then
            sudo -u fpp rm -f "$TESTFILE" 2>/dev/null
            rm -f /tmp/rb_mount_err_$$
            rb_log "mount_usb: remount fixed write access at $MOUNT_POINT"
            jq -n --arg mp "$MOUNT_POINT" --arg fs "$FSTYPE" '{ok:true, mountpoint:$mp, fstype:$fs, alreadyMounted:true, writableByFpp:true, remounted:true}'
            exit 0
        fi
        rm -f /tmp/rb_mount_err_$$
        json_err "$MOUNT_POINT is mounted but not writable by the 'fpp' user. Try: sudo umount $MOUNT_POINT && sudo mount -a"
        exit 0
    else
        json_err "$DEVICE is already mounted at $CURRENT_MP"
        exit 0
    fi
fi

sudo mkdir -p "$MOUNT_POINT" 2>&1

# FAT-family filesystems (exFAT/vFAT/NTFS) have no on-disk Unix ownership
# at all - "who owns what" is decided entirely by uid=/gid=/umask= mount
# options, not by chown afterward. A plain `mount` with no options lands
# everything as root with a restrictive umask, so a later `chown fpp:fpp`
# is silently a no-op and every remote's backup fails with "could not
# create/write to target directory" even though the drive IS mounted.
# ext4/other native-Unix filesystems don't understand these options at
# all, so only apply them for the FAT/exFAT/NTFS family.
MOUNT_OPTS=()
case "$FSTYPE" in
    vfat|fat|fat32|exfat|ntfs|ntfs3)
        MOUNT_OPTS=(-o "uid=${FPP_UID},gid=${FPP_GID},umask=000")
        ;;
esac

if ! sudo mount "${MOUNT_OPTS[@]}" "$DEVICE" "$MOUNT_POINT" 2>/tmp/rb_mount_err_$$; then
    ERR=$(cat /tmp/rb_mount_err_$$ 2>/dev/null)
    rm -f /tmp/rb_mount_err_$$
    rb_log "mount_usb FAILED: $DEVICE -> $MOUNT_POINT : $ERR"
    json_err "mount failed: ${ERR:-unknown error}. If this is exFAT or NTFS, make sure exfat-fuse/exfatprogs or ntfs-3g is installed."
    exit 0
fi
rm -f /tmp/rb_mount_err_$$

# Native-Unix filesystems (ext4 etc.) still need the explicit chown, since
# they weren't covered by MOUNT_OPTS above.
sudo chown fpp:fpp "$MOUNT_POINT" 2>/dev/null || true
sudo chmod 0775 "$MOUNT_POINT" 2>/dev/null || true

# Prove it, don't assume it: try an actual write as the fpp user before
# reporting success, so a permissions problem is caught here with a clear
# message instead of surfacing later as a confusing per-remote rsync
# failure during a backup run.
WRITABLE=true
TESTFILE="${MOUNT_POINT}/.rb_write_test_$$"
if ! sudo -u fpp touch "$TESTFILE" 2>/dev/null; then
    WRITABLE=false
else
    sudo -u fpp rm -f "$TESTFILE" 2>/dev/null
fi

ADDED_FSTAB=false
if [ "$ADD_FSTAB" = "1" ] && [ -n "$UUID" ]; then
    if ! grep -q "UUID=${UUID}" /etc/fstab 2>/dev/null; then
        # uid=/gid=/umask= are only valid mount options for the FAT family
        # (no on-disk Unix ownership - see the MOUNT_OPTS comment above,
        # which gates the live mount the exact same way). ext4 and other
        # native-Unix filesystems don't understand them at all - `mount -o
        # uid=...,gid=...` on an ext4 filesystem fails outright ("wrong fs
        # type, bad option, bad superblock"), confirmed against a real
        # ext4 loopback mount. This fstab line used to include them
        # unconditionally, which the live mount path above never did: a
        # freshly-mounted ext4 drive worked fine (via the plain `mount` +
        # `chown fpp:fpp` below), but that same drive's fstab entry would
        # fail on the next boot's `mount -a` - nofail keeps that from
        # hanging boot, but the drive would just silently stay unmounted
        # until someone opened Config and clicked Mount again.
        FSTAB_OPTS="nofail,x-systemd.device-timeout=10"
        case "$FSTYPE" in
            vfat|fat|fat32|exfat|ntfs|ntfs3) FSTAB_OPTS="${FSTAB_OPTS},uid=fpp,gid=fpp,umask=000" ;;
        esac
        echo "UUID=${UUID} ${MOUNT_POINT} auto ${FSTAB_OPTS} 0 0" | sudo tee -a /etc/fstab >/dev/null
        ADDED_FSTAB=true
        rb_log "mount_usb: added fstab entry for UUID=$UUID -> $MOUNT_POINT"
    fi
fi

if [ "$WRITABLE" != "true" ]; then
    rb_log "mount_usb: mounted $DEVICE ($FSTYPE) at $MOUNT_POINT but it is NOT writable by fpp"
    json_err "Mounted $DEVICE at $MOUNT_POINT, but the 'fpp' user cannot write to it. Try: sudo umount $MOUNT_POINT && sudo mount -a  (this re-mounts using the fstab entry above), then rescan."
    exit 0
fi

rb_log "mount_usb: mounted $DEVICE ($FSTYPE) at $MOUNT_POINT (fstab=$ADDED_FSTAB, writable=true)"
jq -n --arg mp "$MOUNT_POINT" --arg fs "$FSTYPE" --argjson fstab "$ADDED_FSTAB" \
    '{ok:true, mountpoint:$mp, fstype:$fs, addedFstab:$fstab, writableByFpp:true}'
