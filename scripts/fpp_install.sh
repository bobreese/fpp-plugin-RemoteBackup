#!/bin/bash
set -e

# fpp-plugin-RemoteBackup install script

# PLUGINDIR is always derived from this script's own on-disk location, NOT
# from "$FPPDIR/plugins/<plugin>" - a real, confirmed bug (found via the
# rb_settings_checkpoint diagnostic below printing an unexpectedly stale
# file): "$FPPDIR/plugins/<plugin>" is only where FPP's OWN bundled plugins
# live. A user-installed, git-managed plugin like this one lives under FPP's
# media tree instead (/home/fpp/media/plugins/<plugin> on a stock layout) -
# a completely different, unrelated parent directory, not something
# reachable by any relative-path formula from $FPPDIR. Every previous
# install/upgrade on a real system was silently creating and touching a
# phantom "$FPPDIR/plugins/fpp-plugin-RemoteBackup/data/" (confirmed on a
# live box: no ajax.php, no scripts/, nothing but that one orphaned data/
# directory) while the actual, git-pulled, web-served copy of this plugin -
# confirmed against ajax.log's own command lines and a live git rev-parse
# HEAD comparison - sat elsewhere, never touched by this script's chown/
# chmod/settings-seeding at all. Self-deriving from $0 instead means this
# script always operates on wherever it is ACTUALLY physically running
# from, which is the one thing guaranteed to be correct regardless of how
# a given FPP layout relates $FPPDIR to installed plugins.
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGINDIR="$(cd "$SCRIPT_DIR/.." && pwd)"

# $FPPDIR itself is still needed separately (scripts/common, setSetting).
# The plugin manager always provides it; this fallback only covers a
# manual run (e.g. over SSH while debugging) where it wasn't set - /opt/fpp
# is FPP's fixed install root on every real system, so it's a safe default
# rather than another guess relative to a plugin location we now know
# isn't reliably related to it.
if [ -z "${FPPDIR:-}" ]; then
    FPPDIR="/opt/fpp"
fi

# Source FPP-wide helpers if available; if not, continue with a warning
# so the installer can still run in minimal environments.
if [ -f "${FPPDIR}/scripts/common" ]; then
    . "${FPPDIR}/scripts/common"
else
    echo "WARNING: ${FPPDIR}/scripts/common not found; continuing without it"
fi

# --- Diagnostic: settings.json fingerprint checkpoints -------------------
# Added after three real incidents (2026-08-26, 08-27, 08-29) where
# data/settings.json AND both of its independent backups (see ajax.php's
# rb_settings_backup_path()/rb_settings_external_backup_path()) were found
# empty/corrupt, ajax.php's own reactive detection resetting everything to
# defaults. This diagnostic's very first real run turned out to still be
# watching the WRONG file - because PLUGINDIR itself was wrong (see the
# big comment above) - which is exactly what exposed that bug: the file
# it checkpointed hadn't changed in a week, while the real one (confirmed
# via ajax.log) was being saved to every few minutes. Now that PLUGINDIR
# points at the real, git-managed plugin directory, this is watching the
# file that actually matters, so the original mystery - three occasions
# where it AND both backups went empty at once - is still genuinely open
# and this is what will finally catch the next one. Read-only (existence/
# size/mtime/md5, never touches the files themselves), logged to stdout so
# upgrade_plugin's startPluginLog captures it into fpp_plugin_manager.log
# alongside everything else this script prints - no new log file to go
# looking for. Bracketing every step from git pull finishing (script
# start) through immediately before FPP restarts fppd (script end) means
# the next occurrence pins the exact step instead of only being noticed
# hours later by ajax.php.
#
# Only prints when the fingerprint set actually changed since the previous
# checkpoint (always printed for the first one, "script-start", since
# there's no previous to compare against). On a normal run nothing ever
# changes between these four points, so this stays silent after the initial
# baseline instead of repeating the same three identical lines four times -
# a real divergence still gets logged in full, pinned to whichever
# checkpoint first shows it, which is the entire point of this diagnostic.
RB_PREV_CHECKPOINT_FINGERPRINT=""
rb_settings_checkpoint() {
    local label="$1"
    local f fingerprint=""
    for f in "${PLUGINDIR}/data/settings.json" "${PLUGINDIR}/data/settings.json.bak" "/home/fpp/media/.fpp-plugin-RemoteBackup-settings.bak"; do
        if [ -f "$f" ]; then
            local size mtime md5
            size=$(stat -c %s "$f" 2>/dev/null || echo '?')
            mtime=$(stat -c %y "$f" 2>/dev/null || echo '?')
            md5=$(md5sum "$f" 2>/dev/null | cut -c1-12 || echo '?')
            fingerprint="${fingerprint}${f}: size=${size} mtime=${mtime} md5=${md5}
"
        else
            fingerprint="${fingerprint}${f}: MISSING
"
        fi
    done

    if [ "$label" = "script-start" ] || [ "$fingerprint" != "$RB_PREV_CHECKPOINT_FINGERPRINT" ]; then
        echo "SETTINGS_CHECK [$label]"
        echo "$fingerprint" | sed "s/^/SETTINGS_CHECK [$label]   /" | grep -v '^SETTINGS_CHECK \[.*\]   $'
    fi
    RB_PREV_CHECKPOINT_FINGERPRINT="$fingerprint"
}

echo "=================================================================="
echo " Remote Backup plugin - installing"
echo "=================================================================="

rb_settings_checkpoint "script-start"

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

rb_settings_checkpoint "pre-chmod"

chown -R fpp:fpp "${PLUGINDIR}/data" 2>/dev/null || true
# FPP is a single-user appliance; the web server user varies by build
# (fpp, www-data, etc.), so open data/ up rather than guess wrong and
# leave Config Save / status writes silently failing.
chmod -R 0777 "${PLUGINDIR}/data" 2>/dev/null || true

rb_settings_checkpoint "post-chmod"

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
rb_settings_checkpoint "script-end"

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
