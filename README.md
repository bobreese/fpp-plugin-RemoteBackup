# Remote Backup (fpp-plugin-RemoteBackup)

An FPP plugin that turns one Falcon Player system into a **Backup Host** which pulls
`rsync` backups of one or more MultiSync remotes onto local NVMe/SSD, USB, or SD storage.

## Features

- **One Host, many remotes.** Designate a single FPP system as the Host. The install
  script and Config page both warn that only one system should ever have Host Mode enabled.
- **Storage auto-detection.** Probes local block devices via `lsblk`/`df` and prefers
  NVMe/SSD; if none is found it offers a USB flash drive or free space on the SD card. The
  SD-card/system-storage fallback reports the true filesystem root (`/`) for free-space
  purposes, but backups themselves are written into a dedicated `/home/fpp/media/backups`
  folder, never into `/` itself.
- **MultiSync-aware remote discovery.** Queries FPP's own `/api/fppd/multiSyncSystems`
  endpoint to list candidate remotes; remotes can also be added manually by hostname/IP.
  A dual-stack remote (announced with both an IPv4 and an IPv6 address) is recognized as
  one device and its real IPv4 address is preferred; if it's only ever seen over IPv6,
  mDNS (`<hostname>.local`) is tried as a fallback before settling for the IPv6 address.
- **The Host backs itself up locally, not over SSH.** MultiSync's own system list (or a
  manual add) can include the Host running this plugin - selecting it is marked with a
  "Host" badge on the Config page, and it's backed up as a plain local file copy instead
  of an SSH pull to itself (no key to push, no sshd round trip for a same-machine copy).
  If the destination is the SD Card/System Storage fallback (which lives inside
  `/home/fpp/media` itself), that one destination subfolder is excluded from the Host's
  own copy so it doesn't try to back its own backups up into itself - the rest of
  `/home/fpp/media` still backs up normally, and other devices' existing backups there
  are left untouched.
- **rsync pull over SSH**, with a concurrency-limited queue: the first 2 selected remotes
  (configurable) start immediately, and each completion backfills the next queued remote.
- **Dry run mode.** `--dry-run` against all selected remotes, summed and compared to free
  space on the Host's destination before you commit to a real run. A dry run never creates,
  renames, or otherwise touches anything on the destination - only `rsync --dry-run` itself
  runs, so it is safe to run repeatedly with no side effects.
- **Delete handling.** Optional `rsync --delete` so the host backup mirrors deletions made
  on the remote, or leave it off to only ever accumulate files.
- **Won't start while a show is running.** Before a manual or scheduled run touches
  anything, every selected remote's own FPP API is checked; if any of them are currently
  playing a sequence, the whole run is refused (not just that remote) rather than risking
  stutters/dropped frames from reading the same SD card fppd is actively playing off of.
  A remote that can't be reached is treated as unknown, not playing, so it doesn't block
  backing up everything else.
- **Dated, per-remote backups.** Each remote's backup folder is named
  `<Hostname>-<YYYYMMDD>` (e.g. `Pi5-20260803`) and remotes are never mixed together.
  By default this is a single rolling "current" backup (renamed to today's date and
  updated in place each run); an optional snapshot mode keeps full dated history
  space-efficiently via `rsync --link-dest`.
- **Live status window** showing per-remote state, current file, percent, bytes
  transferred, and destination folder, polled every 2 seconds while a run is active.
  Each remote's own run log (`data/logs/<id>-<timestamp>.log`, viewable from the Status
  page, up to 5000 lines - it says plainly if a log is long enough that it's still had to
  truncate) is kept for its most recent runs (15 by default, configurable in Config >
  Backup Options); older ones are pruned automatically at the end of each backup run, and
  immediately - across every remote, not just ones that happen to run again - whenever you
  change that number. The Diagnostic Log's Auto-tail checkbox is off by default and
  remembers your last choice (per browser) instead of always polling. The Status and
  Config pages link to each other, and the Dry Run/Start Backup/Config buttons each have
  a "?" help popover (matching FPP's own System Stats page style) explaining what they do.
- **FPP Commands** ("Run Remote Backup" / "Run Remote Backup Dry Run") so backups can be
  triggered from FPP's built-in Scheduler, Playlists, or Events.
- **USB drive management.** Detects an attached-but-unmounted USB drive, and can mount it
  (existing filesystem) or format it (ext4 or exFAT - exFAT recommended if you want the
  drive readable on Windows/Mac/another Pi) and mount it as `/mnt/Backups`, persisted via
  `/etc/fstab`. The same drive can be re-formatted or unmounted later from the Config page -
  Unmount detaches it (fstab entry removed, data untouched) so it is safe to unplug without
  needing an SSH session. Formatting creates a GPT partition table with a single partition
  (rather than a filesystem directly on the raw disk) so the resulting device name matches
  what FPP itself expects - this is what lets the drive also show up in FPP's own
  Settings > Storage dropdown and in File Copy Backup/Restore's "Remote Storage" device
  picker, not just in this plugin. Note that while this plugin has the drive mounted,
  FPP's own pickers still won't list it (FPP excludes anything already mounted) - Unmount
  it first if you want to use it from FPP's native File Copy Restore. Drives formatted by
  an older version of this plugin (filesystem directly on the raw disk, no partition table)
  need to be re-formatted to pick this up; existing backups on them are unaffected until
  you do.
- **Logs and system config.** Each remote's own `logDirectory` setting is queried live
  (over its FPP API) and pulled if it has been moved off the media tree (a common tweak
  to spare SD card wear) - so logs are captured even when they are not sitting under
  `/home/fpp/media`. Optionally (on by default, toggle in Config > Backup Options) also
  pulls `/etc/fpp` and network config (hostname, WiFi, static IP) into a
  `system-config.tar.gz` archive via sudo on the remote - useful for a from-scratch
  rebuild, not just restoring show content. All of these system paths are fetched in a
  single SSH+sudo session per remote (not one connection per path), so a remote whose
  sshd prints a login banner doesn't flood the run log with repeated copies of it. Logs
  and system config are packaged as
  `.tar.gz` files rather than left as plain folders so FPP's own "Restore from USB" /
  File Copy Restore device browser (which naively lists any subfolder under a backup as
  a separately-selectable "backup") doesn't show them as confusing, non-restorable
  entries. This includes credentials (e.g. WiFi passwords) in plain text inside the
  archive on the destination drive, so turn it off if that is not something you want
  mirrored there.
- **Browse and delete backups.** The Status page's "Backed Up" dropdown lists every backup
  on the destination storage with size/file-count/contents, and can delete an individual
  backup (type-to-confirm) if you want to reclaim space.
- **Restoring a backup.** This plugin only handles the pull; it has no restore button of
  its own, by design - use FPP's own built-in **File Copy Backup/Restore** page (Content
  Setup) on whichever system you're restoring to, so recovery goes through FPP's own,
  well-tested restore path instead of a second one this plugin would have to maintain.
  Point its "Remote Storage" source at the destination drive, browse into that remote's
  `<Hostname>-<YYYYMMDD>` folder (or `<Hostname>/<YYYYMMDD>` if Snapshot mode was enabled),
  and restore the sequences/media/playlists/effects you need. A few things that make this
  smoother:
  - If the destination is a portable USB drive, click **Unmount** on the Config page
    first - FPP's own device pickers (including File Copy Restore's) never list a drive
    this plugin still has mounted, and this is the single most common "I don't see my
    backup drive" surprise.
  - You don't have to restore to the same physical remote a backup came from - point File
    Copy Restore at any `<Hostname>-<YYYYMMDD>` folder from a system you're rebuilding or
    cloning, e.g. after replacing a dead SD card.
  - The `logs/` and `system-config.tar.gz` archives inside a backup (if "Include system
    config" is enabled) are deliberately packaged as `.tar.gz` rather than plain folders
    so File Copy Restore's device browser doesn't mistake them for restorable show-content
    backups - they aren't part of its file-level restore. Pull those out yourself (`tar
    xzf`) over SSH/SCP if you need the remote's original `/etc/fpp`, network config, or
    relocated log directory back.
  - Rolling mode (the default) only ever keeps each remote's most recent backup - restoring
    an older point in time requires Snapshot mode to have been enabled before that backup
    was made.

## Requirements

- `rsync`, `jq`, an OpenSSH client, and `curl` on the Host (installed automatically if
  missing by fpp_install.sh via a plain `apt-get install`). These are deliberately NOT
  declared in pluginInfo.json's `dependencies.packages` - FPP ref-counts packages listed
  there per-plugin and apt-removes one once no plugin/user still claims it, which is
  unsafe for foundational tools other things on the system can genuinely depend on
  (removing this plugin previously cascaded, via real apt dependencies, into removing
  `raspi-firmware` and the entire `openssh-server`/`ssh` stack on a real Pi5 - see commit
  history). Uninstalling this plugin now leaves them alone, full stop.
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

## Scheduling backups

The plugin registers two FPP Commands (`commands/descriptions.json`) that show up in
FPP's own Scheduler automatically - no extra scripting needed:

1. **Select which remotes to back up.** On the Remote Backup Config page, check the remotes you want
   included and hit Save Settings. A scheduled run always backs up whatever is currently
   checked there - the command itself takes no per-run arguments.
2. **Open FPP's Scheduler** and add a new scheduled entry.
3. For that entry's action, choose **Run Command**, then pick one of:
   - **Run Remote Backup** - starts a real backup (rsync pull from every selected remote).
   - **Run Remote Backup Dry Run** - simulates it and logs the estimated size vs.
     available space, without copying anything (useful as an earlier "will this fit"
     check before a real backup night - it updates the same "Estimated total transfer"
     summary on the Status page).
4. Set the day/time/recurrence you want and save the schedule entry.

A few things worth knowing before scheduling it:

- Both commands launch `scripts/run_backup.sh` in the background (`nohup ... &`) and
  return immediately - the Scheduler entry itself finishes right away, while the actual
  backup keeps running and reports progress on the plugin's Status page, not in the
  Scheduler's own log.
- A second run can't actually start while one is already in progress - `run_backup.sh`
  holds an exclusive lock (`data/run.lock`) for its whole duration, so a Scheduler entry
  that fires mid-run is refused outright rather than competing for the same destination.
  It's still worth not scheduling entries close enough together that this becomes the
  normal outcome - a refused run explains why in `data/logs/engine.log` and in FPP's own
  command output, but it still means that scheduled backup didn't happen.
- A scheduled run is also refused outright (same "explains why, but didn't happen"
  outcome) if any selected remote is actively playing a sequence at the moment it fires -
  worth keeping in mind if you schedule backups during hours a show might still be
  running rather than only overnight.
- Host Mode must be enabled and destination storage configured/mounted before the
  schedule fires, same as running it manually - otherwise the scheduled run fails
  immediately (visible in its log, but nothing gets backed up).

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

**Known minor gaps**, both harmless and neither reachable through normal use
of the plugin today:

- `fpp_uninstall.sh`, `format_usb.sh`, and `unmount_usb.sh` each edit
  `/etc/fstab` with `sed -i.rb-*-bak`, which leaves a small backup copy of
  `/etc/fstab` behind (e.g. `/etc/fstab.rb-uninstall-bak`). Nothing ever
  cleans these up - cosmetic filesystem litter, not a functional issue.
- `fpp_uninstall.sh` removes the SSH keypair from a hardcoded path
  (`~fpp/.ssh/id_rsa_remotebackup`) rather than reading the `sshKeyPath`
  setting. `sshKeyPath` is accepted by the `saveSettings` API but has no
  Config page field to change it, so in practice it's always the default
  and this never diverges - it would only matter if that path were ever
  changed via a direct API call.

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
    check_remotes_playing.sh checks each remote's FPP status for active playback
    host_info.sh             reports this Host's own hostname/IPs for the "Host" badge
    run_backup.sh            the rsync pull engine (concurrency, delete, snapshots)
    prune_logs.sh            applies logRetentionCount to every remote's logs immediately on save
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

## Changelog

Notable fixes and changes, newest first (this plugin tracks `master` directly rather
than tagging releases, so this is a running list rather than versioned entries):

- **Fixed:** "Push SSH Key" (and scheduled backups) failing with no working password
  after a remote was reimaged/rebuilt - `ssh -o StrictHostKeyChecking=accept-new` only
  auto-trusts a host it has never seen before, so a remote whose IP/hostname stayed the
  same but whose SSH host keys regenerated on rebuild made every connection attempt fail
  outright with "REMOTE HOST IDENTIFICATION HAS CHANGED", which no amount of retrying
  with the correct password could fix. Both `ssh_setup.sh` and `run_backup.sh` now clear
  any stale `known_hosts` entry for a remote before connecting - exactly the recovery
  "Push SSH Key" exists to perform.
- **Added** a Features section explanation of how to actually restore a backup: this
  plugin has no restore button of its own, by design - use FPP's own built-in File Copy
  Backup/Restore page, unmounting a portable destination drive first so FPP's device
  pickers can see it, plus notes on cross-restoring to a different remote and why the
  `logs`/`system-config.tar.gz` archives need manual extraction instead.
- **Added** a step to the Help page's walkthrough calling out that Config page changes
  (Host Mode, destination device, selected remotes, any option) only take effect after
  clicking "Save Settings" at the bottom of the page - easy to miss since the page has
  no inline "unsaved changes" indicator.
- **Documented** two known-harmless, currently-unreachable gaps in `fpp_uninstall.sh`
  in the Uninstall section: a stray `/etc/fstab` `sed -i` backup file is never cleaned
  up, and the SSH keypair is removed from a hardcoded path rather than the
  (UI-unreachable) `sshKeyPath` setting.
- **Fixed:** `commands/descriptions.json` used the wrong schema (an object keyed by
  command name, with `file`/`description` keys) so FPP's Scheduler never actually
  parsed it - "Run Remote Backup" and "Run Remote Backup Dry Run" never appeared in
  the "Run Command" dropdown. Switched to the array-of-`{name, script, args}` schema
  FPP actually expects (confirmed against FPP's own template plugin and another
  working community plugin), so both commands now show up correctly.
- **Fixed:** the Diagnostic Log on the Status page silently truncating to only its last
  200 lines with no indication anything was cut off - easy to hit since rsync's
  `-v --info=progress2` output can produce many lines per second with no TTY to
  overwrite in place. The cap is now 5000 lines, and if a log is still longer than that
  the viewer says so plainly (e.g. "showing last 5000 of 7000 lines") instead of just
  quietly showing a partial log.
- **Added** a "Run logs to keep per remote" option in Config > Backup Options
  (`logRetentionCount`, default 15). Lowering it prunes every remote's existing logs
  down to the new count immediately when you save, rather than only taking effect
  gradually as remotes happen to run again - including logs left behind by a remote
  that's since been removed from Config.
- **Fixed:** MultiSync remote scanning showing a dual-stack remote's IPv6 address
  instead of its IPv4. FPP reports each address a system has been seen at as its own
  separate entry rather than one entry per device, so a dual-stack remote appeared
  twice (once per address); the old parsing treated those as independent candidates
  and let whichever one it saw last silently win, which was always the IPv6 entry.
  Remotes are now grouped by hostname first, with a real IPv4 address always preferred
  over IPv6 within that group.
- **Added** local (non-SSH) backup handling for the Host itself, when it's selected as
  one of the "remotes" to back up (MultiSync's own system list can include it, or it can
  be added manually). Backed up as a plain local `rsync` copy instead of an SSH pull to
  itself, labeled with a "Host" badge on the Config page (which also skips the
  now-irrelevant SSH-key push UI for that row). If the destination is the SD Card/System
  Storage fallback - which lives inside `/home/fpp/media` itself - that one destination
  subfolder is excluded from the Host's own copy rather than either recursing into it or
  refusing the whole Host backup outright; everything else in `/home/fpp/media` still
  backs up normally, and other devices' existing backups there are left untouched.
- **Added** a guard that refuses to start a backup (manual or scheduled) if any selected
  remote is currently playing a sequence, to avoid reading a device's SD card for backup
  while its own fppd is actively reading those same files for playback. Checks every
  selected remote's FPP API in parallel and refuses the whole run, not just the busy
  remote; a remote that can't be reached is treated as unknown rather than playing, so
  it never blocks backing up everything else.
- **Added** a hard guard against two backup runs overlapping. `run_backup.sh` now takes
  an exclusive `flock` on `data/run.lock` for its whole duration; anything that tries to
  start a second run while one is active - a Scheduler entry, a manual click, a second
  Scheduler entry firing too close together - is refused outright, with the reason
  logged to `data/logs/engine.log` and echoed in FPP's own command output for the
  Scheduler case, instead of silently starting a competing run against the same
  destination.
- **Fixed:** backups (and dry runs) silently failing with "could not create/write to
  target directory" whenever the destination was the SD Card/System Storage fallback
  option. Its mountpoint is the filesystem root (`/`), which collapsed to an invalid
  empty path; backups for that option now go to a dedicated `/home/fpp/media/backups`
  folder instead.
- **Fixed:** a dry run creating a real (empty) backup folder on disk, and in rolling
  mode even renaming an existing backup to today's date - dry runs now never touch the
  destination at all; only `rsync --dry-run` runs.
- **Fixed:** the dry-run summary's "Estimated total transfer" always reading `0.00 MB`
  for any transfer over roughly 1KB. `rsync`'s human-readable `--stats` output (e.g.
  `5.24M bytes`) wasn't being parsed correctly and silently truncated to a handful of
  bytes; it's now parsed properly.
- **Reduced** log noise from a remote's SSH login banner (MOTD) being printed once per
  system-config path (up to 8 times per remote) when "Also back up system/network
  config" is enabled - those paths are now fetched in a single SSH+sudo session.
- **Changed** the Config/Status pages to use FPP's own dialog, toast, and color idioms
  (matching the native File Copy and System Stats pages) instead of plain browser
  `alert()`/`confirm()`/`prompt()` popups and hardcoded colors that didn't adapt to
  FPP's dark theme.
- **Added** cross-navigation buttons between the Status and Config pages, and "?" help
  popovers on the Dry Run/Start Backup/Config buttons.
- **Changed** the Diagnostic Log's Auto-tail checkbox to default off and remember your
  last choice, instead of always polling every 3 seconds regardless of whether anyone's
  watching.
