# Remote Backup (fpp-plugin-RemoteBackup)

An FPP plugin that turns one Falcon Player system into a **Backup Host** which pulls
`rsync` backups of one or more MultiSync remotes onto local NVMe/SSD, USB, or SD storage.

## Features

- **One Host, many remotes.** Designate a single FPP system as the Host. The install
  script and Config page both warn that only one system should ever have Host Mode enabled.
- **Storage auto-detection.** Probes local block devices via `lsblk`/`df` and prefers
  NVMe/SSD; if none is found it offers a USB flash drive or free space on the SD card.
- **MultiSync-aware remote discovery.** Queries FPP's own `/api/fppd/multiSyncSystems`
  endpoint to list candidate remotes; remotes can also be added manually by hostname/IP.
- **rsync pull over SSH**, with a concurrency-limited queue: the first 2 selected remotes
  (configurable) start immediately, and each completion backfills the next queued remote.
- **Dry run mode.** `--dry-run` against all selected remotes, summed and compared to free
  space on the Host's destination before you commit to a real run.
- **Delete handling.** Optional `rsync --delete` so the host backup mirrors deletions made
  on the remote, or leave it off to only ever accumulate files.
- **Dated, per-remote backups.** Each remote's backup folder is named
  `<Hostname>-<YYYYMMDD>` (e.g. `Pi5-20260803`) and remotes are never mixed together.
  By default this is a single rolling "current" backup (renamed to today's date and
  updated in place each run); an optional snapshot mode keeps full dated history
  space-efficiently via `rsync --link-dest`.
- **Live status window** showing per-remote state, current file, percent, bytes
  transferred, and destination folder, polled every 2 seconds while a run is active.
- **FPP Commands** ("Run Remote Backup" / "Run Remote Backup Dry Run") so backups can be
  triggered from FPP's built-in Scheduler, Playlists, or Events.
- **USB drive management.** Detects an attached-but-unmounted USB drive, and can mount it
  (existing filesystem) or format it (ext4 or exFAT - exFAT recommended if you want the
  drive readable on Windows/Mac/another Pi) and mount it as `/mnt/Backups`, persisted via
  `/etc/fstab`. The same drive can be re-formatted later from the Config page without
  needing to unmount it by hand first.
- **Browse and delete backups.** The Status page's "Backed Up" dropdown lists every backup
  on the destination storage with size/file-count/contents, and can delete an individual
  backup (type-to-confirm) if you want to reclaim space.

## Requirements

- `rsync`, `jq`, and an OpenSSH client on the Host (installed automatically if missing).
- SSH access from the Host to each remote's `fpp` user. The install script generates a
  dedicated keypair (`~fpp/.ssh/id_rsa_remotebackup`); use the "Push SSH Key" button on
  the Config page to install it on each remote (requires `sshpass` on the Host, or copy
  the `.pub` key to the remote manually).
- Remotes are backed up from `/home/fpp/media` (config, sequences, effects, music,
  video, playlists, plugins) - this is the standard location for essentially all
  user-generated FPP content.

## Install

Add via FPP's Plugin Manager using this repository's URL, or `git clone` it into
`/home/fpp/media/plugins/fpp-plugin-RemoteBackup` and run
`scripts/fpp_install.sh`.

## Uninstall

Uninstalling through FPP's Plugin Manager runs `scripts/fpp_uninstall.sh`
before removing the plugin's own directory. That script:

- Stops any backup that's actively running.
- Deletes the dedicated SSH keypair it created (`~fpp/.ssh/id_rsa_remotebackup`).
- Removes the `/etc/fstab` entry it added for a USB backup drive (the drive
  itself stays mounted until you unmount it or reboot - files untouched).

It deliberately leaves alone:

- **Your backed-up files** on the destination storage. Uninstalling a backup
  tool should never be how you lose your backups. If you genuinely want them
  gone too, run the script by hand afterwards with `--purge-backups`.
- Installed packages (`rsync`, `jq`, `openssh-client`, `sshpass`,
  `exfatprogs`) - shared with the rest of the system, not plugin-specific.
- The `/mnt/Backups` mount point directory itself.
- The plugin's SSH public key left in each remote's `~fpp/.ssh/authorized_keys`
  (harmless once the Host side is gone, but you can remove it there too).

If any FPP Playlist/Schedule/Event referenced this plugin's "Run Remote
Backup" or "Run Remote Backup Dry Run" commands, remove those references
yourself - they won't do anything once the plugin is gone.

## Directory layout

```
fpp-plugin-RemoteBackup/
  pluginInfo.json        Plugin metadata for FPP's Plugin Manager
  menu.inc                Adds Status/Config/Help/About menu entries
  config.php               Host mode, storage + remote selection, options
  status.php                Live status table, Dry Run / Start / Stop
  ajax.php                  JSON backend for the two pages above
  about.php, help/help.php
  scripts/
    fpp_install.sh / fpp_uninstall.sh
    lib_common.sh            shared bash helpers (settings, status files)
    probe_storage.sh         NVMe/SSD/USB/SD detection
    probe_remotes.sh         MultiSync remote discovery
    run_backup.sh            the rsync pull engine (concurrency, delete, snapshots)
    ssh_setup.sh              pushes the backup SSH key to a remote
  commands/
    descriptions.json, run_remote_backup.sh, run_remote_backup_dryrun.sh
  data/                      settings.json, per-remote status/*.json, logs/ (created on install)
```

## Notes / assumptions

- This plugin was authored and syntax-checked outside of a live FPP system (no FPP
  install or PHP interpreter was available in the build environment), so treat it as a
  strong first cut: review `scripts/run_backup.sh` and `ajax.php` and smoke-test on a
  non-production Pi before relying on it for your show archive.
- The `/api/fppd/multiSyncSystems` response shape is parsed defensively (a few likely
  key names are tried); if your FPP version returns something different, remotes can
  always be added manually on the Config page as a fallback.
