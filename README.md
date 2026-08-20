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
  updated in place each run); enabling **"Keep dated snapshot history"** in Config
  instead keeps every run as its own dated folder, so you end up with one
  `<Hostname>-<YYYYMMDD>` folder per day a backup ran for that remote (e.g.
  `Pi5-20260801`, `Pi5-20260802`, `Pi5-20260803`, ...) instead of just the latest.
  Unchanged files between consecutive runs are hard-linked (`rsync --link-dest`)
  rather than copied again, so extra snapshots cost very little disk space - but
  each one is still a real, complete, ordinary folder on disk. There's no delta or
  index to reconstruct: any single dated snapshot folder is fully self-contained and
  restorable entirely on its own, exactly like a rolling backup, whether or not the
  snapshots next to it still exist.
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
  triggered from FPP's built-in Scheduler, Playlists, or Events - see "Scheduling backups"
  below.
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
- **Clone backups to a second drive.** Optional and manual only (no Scheduler command) -
  format/mount a second USB drive on the Config page, then click "Start Clone" on the
  Status page to mirror everything on the primary destination onto it in one pass
  (`rsync --delete`, so it always exactly matches the primary - a backup you deleted there
  is removed from the clone too), for an occasional off-site or rotating spare copy.
  Refuses to run at the same time as a backup run, a primary-drive format, or another
  clone, and refuses outright if the two drives turn out to be the same device or nested
  inside one another.
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
  - **Restoring from a specific snapshot.** With Snapshot mode, a remote has several
    `<Hostname>-<YYYYMMDD>` folders side by side at the destination root - one per day a
    backup ran - and File Copy Restore's device browser lists all of them individually, by
    date, exactly like it lists a rolling backup. Just pick the date you want; there is no
    "latest" special case to worry about, and restoring from an older snapshot doesn't
    require anything from a newer one to exist. This plugin's own Status page "Backed Up"
    dropdown is the fastest way to see which dates are actually available for a remote
    before you go looking in File Copy Restore. One layout note for older backups:
    snapshots made by a plugin version from before this was documented were nested one
    level deeper (`<Hostname>/<Hostname>-<YYYYMMDD>/`) - those still restore fine, you just
    browse one folder deeper to reach them; every snapshot made since is flat, directly at
    the destination root, same as rolling backups.

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

If FPP's Plugin Manager does not list the Remote Backup plugin, you can paste
`https://github.com/bobreese/fpp-plugin-RemoteBackup/blob/master/pluginInfo.json` into
the "Find a Plugin" search bar.

**Be aware this is considered a Beta Test version. Use with care.**

## Setting up a USB backup drive

If you don't have NVMe/SSD storage, a USB flash/hard drive works fine as the destination.
All of this happens on the Config page, under **Backup Destination Storage**.

1. **Plug the drive in, then click "Rescan Storage Devices."** A brand-new or
   previously-used-elsewhere drive shows up under "USB drive(s) detected but not mounted."
2. **Format it** (skip this if it's already formatted the way you want and you just need
   to mount it - see step 3):
   - Click **"Format & Mount as Backups"** next to the drive.
   - In the dialog, choose a filesystem. **exFAT is selected by default and is the one to
     pick if you want the drive readable on Windows, Mac, and Linux** (e.g. to pull it off
     the Pi and browse backups directly from a laptop) - it's labeled "recommended" for
     exactly that reason. The other option, **ext4, is Linux-only**; a Windows or Mac
     machine won't be able to read the drive at all without extra third-party software, so
     only pick it if the drive is never leaving Linux systems.
   - Optionally set a **volume label** - defaults to `Backups`, up to 11 characters (the
     more restrictive limit of the two filesystems above). This is the filesystem's own
     label, e.g. what shows up as the drive's name in a file manager on another computer -
     handy for telling multiple backup drives apart at a glance. Once set, it's shown
     alongside the drive on the Config page's storage list and next to "Host storage" on
     the Status page.
   - Type the device path shown in the dialog (e.g. `/dev/sda`) into the confirm box
     exactly as shown - this is a safety check since formatting **erases everything
     already on the drive**, and the button stays disabled until it matches.
   - Click **Format**. This partitions, formats, and mounts the drive in one step - see
     step 3 below, it's already mounted once this finishes.
3. **Mount it** (only needed if the drive already has a filesystem you want to keep, so
   you skipped formatting): click **"Mount as Backups"** next to it. Either path (format or
   plain mount) mounts the drive at `/mnt/Backups` and adds it to `/etc/fstab` so it's
   automatically remounted after a reboot. A drive mounted this way (rather than formatted
   here) only shows a volume label if one was already set on it elsewhere.
4. **Activate it as the destination.** Once mounted, the drive appears in the main
   storage list above with a radio button (`<label> - mounted at /mnt/Backups - X free`,
   plus `- volume label "<label>"` when the drive has one). Select it, then click
   **"Save Settings"** at the bottom of the page - like every other Config change, nothing
   takes effect, including which storage is actually used, until you save.

A few related things:

- **Re-formatting** an already-mounted drive uses the same dialog - click **"Re-format..."**
  next to it in the main storage list instead of "Format & Mount as Backups." If it's your
  active destination, this also clears every remote's backup status on the Status page,
  since whatever was there is now gone too.
- **Unmount** before physically unplugging the drive - click **"Unmount"** next to it.
  This detaches it and removes the `/etc/fstab` entry; the backups on it are untouched,
  and you'll need to Mount it again before the next backup run.
- **Using the drive with FPP's own File Copy Backup/Restore** (e.g. to restore from it, see
  Features above): Unmount it here first. FPP's own device pickers never list a drive this
  plugin still has mounted.

## Cloning backups to a second drive

Optional, and entirely separate from the primary destination above - useful for an
occasional off-site copy, or a second drive you rotate in and out. There's no Scheduler
command for it; it only ever runs when you click the button.

1. **Format/mount the second drive**, under Config's **"Clone Backups to a Second
   Drive"** section - the same Rescan/Format/Mount flow as the primary destination
   (exFAT vs. ext4 works the same way; see "Setting up a USB backup drive" above), just
   fixed to a second mountpoint (`/mnt/BackupsCopy`) so it's always a distinct drive from
   your primary destination.
2. **Click "Start Clone"** on the Status page, under the same-named section. This runs
   `rsync --delete` from the entire primary destination to the secondary drive in one
   pass - an exact mirror, not an incremental backup of backups, so anything you deleted
   from the primary is removed from the clone too. Progress (current file, percent) shows
   live, the same way a regular backup run does.
3. **Stop** cancels an in-progress clone the same way Stop cancels a backup run - the
   clone is left partially mirrored (whatever had already copied stays; nothing already
   deleted from the clone side comes back). Just start it again later to finish catching
   up.

A few safety notes:

- A clone refuses to start while a backup run or a primary-drive format is in progress
  (it reads from the same destination those write to), and the reverse is also true - you
  can't start a backup, or format/unmount the primary drive, while a clone is running.
  Formatting or unmounting the *secondary* drive is unaffected by an ongoing backup run,
  but is blocked while a clone to it is in progress.
- It also refuses outright if the primary and secondary turn out to be the same drive, or
  one is nested inside the other's mountpoint - mirroring a directory into itself (or its
  own parent) with `--delete` could otherwise corrupt or wipe every backup on the primary.
- The clone log is available from the Status page's Diagnostic Log dropdown
  (`clone.log (backup clone to second drive)`).

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

Uninstalling through FPP's Plugin Manager first tells fppd to unload this plugin -
which explicitly unregisters its "Run Remote Backup" and "Run Remote Backup Dry Run"
commands (confirmed against FPP's own `www/api/controllers/plugin.php` and
`src/Plugins.cpp`: `UninstallPlugin()` calls `FPPDPluginLifecycle($plugin, 'unload')`
before anything else runs, and fppd's `unloadPlugin()` explicitly calls
`CommandManager::removeCommand()` for each one) - so both disappear from the
Scheduler's "Run Command" dropdown immediately, with no reboot or fppd restart
needed. Only then does it run `scripts/fpp_uninstall.sh` before removing the
plugin's own directory. That script:

- Stops any backup that's actively running.
- Deletes the dedicated SSH keypair it created (`~fpp/.ssh/id_rsa_remotebackup`).
- Removes the `/etc/fstab` entry it added for a USB backup drive (the drive
  itself stays mounted until you unmount it or reboot - files untouched).

The plugin's own directory removal that follows (confirmed against FPP's own
`scripts/uninstall_plugin`: it runs the plugin's `fpp_uninstall.sh`, then
`rm -rf`'s the whole plugin folder) takes the entire `data/` folder with it -
including `data/logs/` (every log this plugin ever wrote: `ajax.log`,
`engine.log`, `clone.log`, and per-remote rsync logs - see the Help page's
"Log Files" section), `data/settings.json`, and `data/status/`. None of that
is left behind; only your actual backed-up files on the destination storage
survive an uninstall, per the "deliberately leaves alone" list below.

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
  menu.inc                Adds Status/Config/Help menu entries
  config.php               Host mode, storage + remote selection, options
  status.php                Live status table, Dry Run / Start / Stop
  ajax.php                  JSON backend for the two pages above
  help/help.php             Categorized how-to sections, ending in About
  scripts/
    fpp_install.sh / fpp_uninstall.sh
    lib_common.sh            shared bash helpers (settings, status files)
    probe_storage.sh         NVMe/SSD/USB/SD detection
    probe_remotes.sh         MultiSync remote discovery
    check_remotes_playing.sh checks each remote's FPP status for active playback
    host_info.sh             reports this Host's own hostname/IPs for the "Host" badge
    run_backup.sh            the rsync pull engine (concurrency, delete, snapshots)
    prune_logs.sh            applies logRetentionCount to every remote's logs immediately on save
    clone_backups.sh         mirrors the primary destination onto a second drive (manual only)
    ssh_setup.sh              pushes the backup SSH key to a remote
  commands/
    descriptions.json, run_remote_backup.sh, run_remote_backup_dryrun.sh
  data/                      created on install
    settings.json            Config page's saved settings
    status/<id>.json         each remote's live status, polled by the Status page
    run_active.json, clone_active.json, *.lock, pids/    run/clone overlap guards
    logs/
      engine.log             run_backup.sh's own log (start/finish, refusals, errors)
      ajax.log                every backend script ajax.php invokes, plus its stderr
      <id>-<runId>.log        one full rsync run log per remote per run (kept per
                               Config > Backup Options' "Run logs to keep per remote")
      clone-<runId>.log       one per Clone Backups to a Second Drive run
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

- **Fixed:** clicking a Help page "Categories" link scrolled past each section's title,
  landing in the body text with the heading hidden above the viewport - FPP's plugin
  page frame has a sticky top nav bar that was covering the anchor target. Added
  `scroll-margin-top` to each section so the jump now leaves the title visible.
- **Changed** the Help page to open with a "Categories" row of clickable links that jump
  straight to each section below (How Remote Backup Works, Backup Layout, USB Backup
  Drive, Cloning Backups to a Second Drive, Restoring a Backup, Delete Handling,
  Scheduling, Log Files, About), and merged the standalone About page into a new "About"
  section at the very bottom of Help - `about.php` is gone, and `menu.inc` now has a
  single `help`-type entry instead of two.
- **Documented** in the README's Uninstall section that this plugin's own log files
  (`data/logs/` - `ajax.log`, `engine.log`, `clone.log`, per-remote rsync logs) are
  removed on uninstall, along with the rest of `data/`. Verified against FPP's own
  `scripts/uninstall_plugin`, which runs `fpp_uninstall.sh` and then `rm -rf`'s the
  whole plugin directory - no code change was needed, this was already true.
- **Changed** the Delete Backup confirmation on the Status page from a "type the backup
  folder name to confirm" text box to a "Confirm the backup folder being deleted"
  checkbox. The plugin already auto-fills/knows the exact folder being deleted (shown
  right above in the dialog), so re-typing a name it had already picked for you wasn't
  adding a real safety check - just busywork.
- **Added** a "Full documentation on GitHub (README)" button near the top of the in-app
  Help page, opening this README in a new tab for the complete reference.
- **Added** an optional volume label to the Format dialogs for both the primary
  "Backup Destination Storage" drive and the "Clone Backups to a Second Drive"
  drive (defaults to `Backups`, capped at 11 characters - the more restrictive of
  the two supported filesystems' limits). When a drive has one, it's now shown
  next to Host storage and the Secondary drive line on the Status page, and next
  to the mounted device in each Config page storage list.
- **Added** a "Log Files" section to the in-app Help page explaining that this plugin's
  logs live under its own `data/logs/` rather than FPP's own log directory (deliberate -
  a single rsync run can log a fresh line per file/progress update with no TTY to
  overwrite in place, and that volume doesn't belong flooding FPP's own File Manager
  Logs view), and pointing to the Status page's Diagnostic Log dropdown as where to
  actually view each one (`ajax.log`, `engine.log`, per-remote rsync logs, `clone.log`).
- **Fixed:** the Diagnostic Log dropdown's `clone.log` option disappearing within seconds
  of the Status page loading. `updateLogOptions()` (called on every poll) rebuilds that
  dropdown's non-fixed options each time, but its `fixed` allowlist only ever protected
  `ajax`/`engine` from removal - `clone` was never added to it, so the very first poll
  after page load deleted the option that was right there in the initial HTML.
- **Added** to Install: a fallback for when FPP's Plugin Manager doesn't list the plugin
  (paste `pluginInfo.json`'s raw GitHub URL into "Find a Plugin"), and a Beta Test
  warning to use with care.
- **Updated** the Features section's FPP Commands bullet to point to the "Scheduling
  backups" section below it, instead of leaving scheduling details only discoverable by
  scrolling further down on your own.
- **Fixed:** two leftover-status display issues on the Status page, found right after the
  tab-visibility fix above. The "Clone started."/"Backup started."/"Stopped." message next
  to the buttons was only ever set once, right when clicked, and never cleared - so it
  could sit there forever claiming a clone/backup had just started even after the actual
  result (correctly) showed underneath that it had already finished or failed. Both
  messages now clear once a definitive result is showing instead of lingering
  indefinitely. Also, `finishedAt` timestamps (e.g. "Last clone finished ...") are
  recorded in UTC ("Z" suffix) deliberately, since the Host and its remotes can have
  different system timezones - but showing that raw string as-is reads as flat-out wrong
  locally (e.g. "15:03:27Z" looks nothing like 10:03:27 AM Central, even though that IS
  the correct conversion). Now converted to the browser's own local time for display.
- **Fixed:** the Status page could appear to "get stuck" and never show a clone's (or a
  backup's) finished result, even though it had actually completed normally - reloading
  the page always showed the correct result immediately, confirming this was purely a
  live-update problem, not the clone/backup itself stalling. Browsers throttle
  `setTimeout` heavily in a backgrounded/minimized tab, easy to hit since a clone of the
  whole backup set can run for many minutes, well past when someone tabs away. The page
  now re-polls immediately the moment the tab becomes visible again instead of waiting on
  whatever throttled timer was still pending.
- **Updated** the in-app About page's summary to name the actual storage types
  ("local NVMe/SSD, USB, or SD storage") instead of the vaguer "this system's local
  storage", matching the wording already used in the README and `pluginInfo.json`.
- **Updated** the Directory layout section to break out `data/logs/` explicitly - what
  each log file actually is (`engine.log`, `ajax.log`, per-remote `<id>-<runId>.log`,
  `clone-<runId>.log`) - instead of a single terse "logs/" mention, and added the
  previously-undocumented `status/`, `run_active.json`/`clone_active.json`, and lock/pid
  files.
- **Added** a "Restoring a Backup" section to the in-app Help page, covering both ways to
  actually recover a backup through FPP's own File Copy Backup/Restore: over the network
  with the drive still attached to the Host, or by unmounting it and plugging it directly
  into the device being restored's own USB port (useful for a from-scratch rebuild with
  no network yet) - the drive is recognized by any FPP system's device picker either way,
  since this plugin always formats it with a real GPT partition table.
- **Fixed:** the Status page's Clone section (and the primary destination's free-space
  line) could report a drive as mounted with plausible-looking free-space numbers even
  after it had been unmounted, because the check was only `is_dir()` - mounting creates
  the mountpoint directory, and unmounting deliberately leaves that now-empty directory
  behind, so `is_dir()` stayed true regardless and silently reported the *root*
  filesystem's free space as if it belonged to the drive. Both now check `/proc/mounts`
  directly. The Clone section also now shows **"Secondary drive not mounted"** as a
  clearly-styled warning (not just muted gray text) and disables "Start Clone" whenever
  the secondary drive isn't actually mounted, instead of letting you click it into a
  confusing false "Clone started." followed by a delayed failure a few seconds later -
  `startClone` itself now checks the mount status up front and fails that request
  outright with an accurate message.
- **Documented** in the Help page's Scheduling section that a scheduled run is refused
  entirely - not just skipped for the busy remote - if any one selected remote is
  currently playing a sequence, why (reading the same storage fppd is actively playing
  off of), that an unreachable remote counts as unknown rather than playing so it doesn't
  block the others, and where to check (`engine.log`/Scheduler command output) when a
  scheduled backup silently didn't run.
- **Fixed:** "Re-format..." (and formatting an unmounted-but-already-partitioned drive)
  failing with parted's "Partitions 1 thru 64 ... have been written but we have been
  unable to inform the kernel of the change." The PR #28 fix resolved this same
  partition-vs-disk mixup for the TRAN safety check, but `wipefs`/`parted`/`partprobe`
  themselves were still being run directly against whatever device was passed in - a
  PARTITION (e.g. `/dev/sda1`) in exactly these two cases, not the whole disk. Writing a
  new partition table onto a partition device node instead of its disk is exactly what
  produces that parted error. Now resolves the parent disk first (reusing the same
  `DEV_DISK_NAME` lookup) and runs every partitioning operation against that instead.
- **Fixed:** Mount/Unmount/Push SSH Key sometimes reporting "timed out" even though the
  operation actually succeeded (confirmed by a Rescan showing the drive mounted right
  after). The browser's own request timeout defaulted to a flat 20s, but the
  corresponding server-side operations are allowed up to 25-30s (`mount_usb.sh`) or 25s
  worst case (`ssh_setup.sh`) - the browser could give up and report failure moments
  before the server would have returned success. Client-side timeouts for these five
  actions (mount/unmount, primary and secondary drives, plus Push SSH Key) now all sit
  comfortably above their server-side counterparts, matching the pattern the Format
  dialog already used correctly.
- **Fixed:** "Re-format..." (and, less obviously, formatting an unmounted drive that
  already had a filesystem/partition on it) always failing with "Refusing to format
  ... it is not reported as a USB device (tran=)", even on a genuine USB drive - both
  pass `format_usb.sh` the drive's actual PARTITION path (e.g. `/dev/sda1`), but `lsblk`
  only ever populates the `TRAN` column on the whole-disk row, never a partition row, so
  the check always read back empty. It now resolves the parent disk first (already
  computed a few lines earlier for the root-disk-protection check) and checks TRAN on
  that. Affects both the primary destination and the new secondary clone drive below,
  since both share this script.
- **Added** an option to clone the entire current backup set to a second USB drive
  (`rsync --delete` exact mirror, manual only via a new "Start Clone" button on Status -
  no Scheduler command). Format/mount the second drive on Config's new "Clone Backups to
  a Second Drive" section (fixed at `/mnt/BackupsCopy`, always distinct from the primary
  destination); mutual-exclusion checks refuse a clone while a backup run or primary-drive
  format is in progress and vice versa, plus a same-drive/nested-mountpoint safety check
  so mirroring can never run into itself. `mount_usb.sh`/`format_usb.sh`/`unmount_usb.sh`
  now take an optional mountpoint argument (still defaulting to `/mnt/Backups`) so the
  same scripts serve both drives; fixed a latent bug found along the way where
  `unmount_usb.sh`'s `/etc/fstab` cleanup used a hardcoded path instead of the actual
  mountpoint.
- **Added** a step-by-step "Setting up a USB backup drive" section to the README, and a
  matching "USB Backup Drive" section to the in-app Help page: formatting with exFAT for
  cross-platform (Windows/Mac/Linux) readability vs. Linux-only ext4, mounting, and
  activating a drive as the actual backup destination (select its radio button, then Save
  Settings - it isn't the destination until you do).
- **Verified and documented** that uninstalling removes "Run Remote Backup" and "Run
  Remote Backup Dry Run" from the Scheduler's "Run Command" dropdown immediately - traced
  through FPP's own uninstall flow (`www/api/controllers/plugin.php` unloads the plugin
  from fppd, which unregisters its commands via `CommandManager::removeCommand()`, before
  `fpp_uninstall.sh` even runs) and added the explanation to the Uninstall section.
- **Documented** what a Snapshot mode "snapshot" actually is (a complete, independently
  restorable dated folder, hard-linked to its neighbors only to save space - not a
  diff/delta needing anything reconstructed) and how to restore from a specific one via
  File Copy Restore, including the flat-vs-legacy-nested folder layout for older backups.
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
