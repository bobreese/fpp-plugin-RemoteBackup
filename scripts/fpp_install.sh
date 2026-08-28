#!/bin/bash
set -e

# fpp-plugin-RemoteBackup install script

# If FPPDIR isn't already set (plugin manager sets it), try to infer a
# sensible default so the installer can be run manually from the plugin
# tree (useful when debugging or running over SSH). FPP's layout places
# plugins under "$FPPDIR/plugins/<plugin>", so walk up from this
# script's location to find $FPPDIR.
if [ -z "${FPPDIR:-}" ]; then
    SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
    PLUGINDIR="$(cd "$SCRIPT_DIR/.." && pwd)"
    FPPDIR="$(cd "$PLUGINDIR/.." && pwd)"
fi

# Source FPP-wide helpers if available; if not, continue with a warning
# so the installer can still run in minimal environments.
if [ -f "${FPPDIR}/scripts/common" ]; then
    . "${FPPDIR}/scripts/common"
else
    echo "WARNING: ${FPPDIR}/scripts/common not found; continuing without it"
fi

PLUGINDIR="${FPPDIR}/plugins/fpp-plugin-RemoteBackup"

echo "=================================================================="
echo " Remote Backup plugin - installing"
echo "=================================================================="

# --- Make sure required tools are present -----------------------------
# Deliberately a plain `apt-get install`, NOT declared in pluginInfo.json's
# dependencies.packages. FPP ref-counts packages declared there per-plugin
# and, on uninstall, apt-get REMOVES a package once no other plugin/user
# claims it (www/common/packages.inc.php: RemoveSystemPackageRequester).
# rsync, jq, openssh-client, and curl are foundational tools other things
# on the system can genuinely depend on - on a real Pi5 running this
# plugin, declaring them that way caused uninstalling this plugin to
# `apt-get remove rsync`, which cascaded (via a real apt Depends:) into
# removing raspi-firmware, and `apt-get remove openssh-client` cascaded
# into removing the entire ssh/openssh-server stack, locking out SSH
# access to the Host. Installing them here instead (install-if-missing,
# never auto-removed by FPP) avoids that entirely - do not move this list
# into pluginInfo.json's dependencies.packages.
for pkg in rsync jq openssh-client sshpass curl parted zip; do
    if ! dpkg -s "$pkg" >/dev/null 2>&1; then
        echo "Installing dependency: $pkg"
        apt-get install -y "$pkg" || echo "WARNING: could not auto-install $pkg, please install manually"
    fi
done

# --- Data directories ---------------------------------------------------
mkdir -p "${PLUGINDIR}/data/status"
mkdir -p "${PLUGINDIR}/data/logs"

if [ ! -f "${PLUGINDIR}/data/settings.json" ]; then
    cat > "${PLUGINDIR}/data/settings.json" << 'SETTINGSEOF'
{
    "hostModeEnabled": false,
    "destinationMount": "",
    "destinationLabel": "",
    "maxConcurrent": 2,
    "deleteExtraneous": false,
    "snapshotMode": false,
    "sshUser": "fpp",
    "sshPort": 22,
    "sshKeyPath": "/home/fpp/.ssh/id_rsa_remotebackup",
    "excludes": ["Logs/*", "logs/*", "tmp/*", "upload/*", "cache/*", "*.tmp"],
    "remotes": [],
    "onboardingSeen": false,
    "onboardingTourEnabled": true
}
SETTINGSEOF
fi

chown -R fpp:fpp "${PLUGINDIR}/data" 2>/dev/null || true
# FPP is a single-user appliance; the web server user varies by build
# (fpp, www-data, etc.), so open data/ up rather than guess wrong and
# leave Config Save / status writes silently failing.
chmod -R 0777 "${PLUGINDIR}/data" 2>/dev/null || true

for f in run_backup.sh dry_run.sh probe_storage.sh probe_remotes.sh ssh_setup.sh mount_usb.sh unmount_usb.sh format_usb.sh list_backups.sh get_backup_info.sh delete_backup.sh purge_sdcard_backups.sh lib_common.sh clone_backups.sh; do
    chmod +x "${PLUGINDIR}/scripts/${f}" 2>/dev/null || true
done
chmod +x "${PLUGINDIR}/commands/"*.sh 2>/dev/null || true

# --- Dedicated SSH key for pulling from remotes -------------------------
KEYFILE="/home/fpp/.ssh/id_rsa_remotebackup"
if [ ! -f "$KEYFILE" ]; then
    mkdir -p /home/fpp/.ssh
    ssh-keygen -t ed25519 -f "$KEYFILE" -N "" -C "fpp-plugin-RemoteBackup" >/dev/null 2>&1 \
        || ssh-keygen -t rsa -b 4096 -f "$KEYFILE" -N "" -C "fpp-plugin-RemoteBackup" >/dev/null 2>&1 \
        || echo "WARNING: could not generate SSH key automatically, use the Config page's 'Push SSH Key' tool per-remote"
    chown fpp:fpp "$KEYFILE" "$KEYFILE.pub" 2>/dev/null || true
    chmod 600 "$KEYFILE" 2>/dev/null || true
fi

# --- One-time cleanup: orphaned known_hosts.<random>/known_hosts.old files
# left behind by ssh-keygen -R racing itself across concurrent remotes,
# before rb_clear_stale_host_key() (lib_common.sh) started serializing
# access with its own flock. Safe to remove unconditionally - each one is
# just a stale point-in-time copy of a file that gets rewritten on every
# backup connection anyway, never read back by anything. The live
# known_hosts file itself is untouched (name matched exactly, not a prefix).
find /home/fpp/.ssh -maxdepth 1 -type f -name 'known_hosts.*' -delete 2>/dev/null || true

# --- Ask FPP to restart so the "Run Remote Backup"/"Run Remote Backup Dry
# Run" commands (commands/descriptions.json) actually become selectable in
# the Scheduler/Playlist/Event command pickers. This plugin has no
# callbacks script (nothing hooks show start/stop), and per PLUGIN_GUIDELINES.md
# install/uninstall only reload commands live for a plugin that HAS one -
# without it, the live-reload path skips this plugin entirely, so the flag
# is what makes a fresh install actually usable without a separate manual
# restart nobody would otherwise know to do.
if command -v setSetting >/dev/null 2>&1; then
    setSetting restartFlag 1
else
    echo "WARNING: setSetting not available (${FPPDIR}/scripts/common missing?) - restart FPP manually so the Run Remote Backup commands appear in the Scheduler."
fi

echo ""
echo "=================================================================="
echo " IMPORTANT - Remote Backup plugin"
echo "------------------------------------------------------------------"
echo " Only ONE FPP system on your show network should be enabled as the"
echo " Backup Host. Enabling 'Host Mode' on more than one system will"
echo " cause duplicate/competing backups and is not supported."
echo ""
echo " Go to Content Setup -> Remote Backup - Config on the ONE system"
echo " you want to act as the backup destination, then enable Host Mode"
echo " and choose a storage device."
echo "=================================================================="
