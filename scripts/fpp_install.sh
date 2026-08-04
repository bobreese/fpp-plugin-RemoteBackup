#!/bin/bash
set -e

# fpp-plugin-RemoteBackup install script

. ${FPPDIR}/scripts/common

PLUGINDIR="${FPPDIR}/plugins/fpp-plugin-RemoteBackup"

echo "=================================================================="
echo " Remote Backup plugin - installing"
echo "=================================================================="

# --- Make sure required tools are present -----------------------------
for pkg in rsync jq openssh-client sshpass; do
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
    "remotes": []
}
SETTINGSEOF
fi

chown -R fpp:fpp "${PLUGINDIR}/data" 2>/dev/null || true
# FPP is a single-user appliance; the web server user varies by build
# (fpp, www-data, etc.), so open data/ up rather than guess wrong and
# leave Config Save / status writes silently failing.
chmod -R 0777 "${PLUGINDIR}/data" 2>/dev/null || true

for f in run_backup.sh dry_run.sh probe_storage.sh probe_remotes.sh ssh_setup.sh mount_usb.sh unmount_usb.sh format_usb.sh list_backups.sh get_backup_info.sh delete_backup.sh lib_common.sh; do
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
