# Features

[← Back to README](../README.md)

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
  Renaming a remote's System Name in FPP (same device, same address, new name) is
  recognized as a rename on the next Rescan, not a new remote - the existing entry is
  updated in place (selected state and Push SSH Key status kept), instead of leaving a
  stale duplicate under the old name alongside a fresh one under the new name. A jGrowl
  notice calls out any rename detected this way. Every remote row also has a **Remove**
  button (not just the select checkbox) to drop it from the list entirely - useful for
  clearing out a remote that's gone for good, or any duplicate left over from before this
  fix. Like everything else on the Config page, removal doesn't take effect until you
  click "Save Settings." A MultiSync-discovered remote absent from a scan for over 24
  hours gets a "Not seen in N days" badge - flagged, never auto-removed, so a remote
  that's just temporarily offline (or simply hasn't had a rescan happen recently, since
  scans only run when the Config page is open) never silently drops out of your backup
  selection. The badge clears itself the next time that remote shows up in a scan; manually
  added remotes are never flagged, since they're expected not to appear in a MultiSync scan.
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
  change that number. The Diagnostic Log's Tail Follow checkbox is off by default and
  remembers your last choice (per browser) instead of always polling. The Status and
  Config pages link to each other, and the Dry Run/Start Backup/Config buttons each have
  a "?" help popover (matching FPP's own System Stats page style) explaining what they do.
- **Download diagnostic logs.** Next to "Refresh Log," **Download** saves the currently
  selected log (`ajax.log`, `engine.log`, `clone.log`, or a remote's own rsync log) to your
  browser as a plain text file. **Download All Logs** zips everything currently under
  `data/logs/` into one archive server-side and downloads that instead - useful for sharing
  a full diagnostic snapshot (e.g. when reporting an issue) without opening each log one at
  a time. Both show live status text while the file/archive is being prepared and
  downloaded, and report a clear error (e.g. `zip` not installed) rather than a broken
  download if something goes wrong. This is separate from FPP's own File Manager download
  button, which can't reach these logs - they deliberately live outside FPP's own log
  directory (see [Log Files](log-files.md) for why).
- **FPP Commands** ("Run Remote Backup" / "Run Remote Backup Dry Run") so backups can be
  triggered from FPP's built-in Scheduler, Playlists, or Events - see
  [Scheduling backups](scheduling.md).
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
- **Missing-destination detection and failover.** If a configured USB/NVMe/SSD
  destination stops being found mounted (unplugged, powered off, failed) while the Status
  or Config page happens to be open, a popup offers two ways to respond: **Halt Backups**
  refuses any manual or scheduled run with a clear reason until the drive reappears or a
  new destination is saved, and **Use Failover** immediately switches the destination to
  SD Card / System Storage (always available, no drive required) so scheduled backups keep
  running. A halt clears itself automatically the moment the missing drive is seen mounted
  again, or a different destination is saved. See
  [Troubleshooting](troubleshooting.md#backup-destination-missing) for the full walkthrough.
- **Safety checks.** Both the primary and secondary/clone drive Format flows refuse to
  touch the disk FPP itself is currently running from - resolved by asking the system for
  the actual device backing the root filesystem (whatever it is: SD card, NVMe, or USB),
  not by guessing from a device name pattern, so it works the same way no matter which
  media FPP booted from. This also blocks any other partition on that same physical disk
  (e.g. a boot partition sitting alongside the root partition), not just the exact root
  partition itself. It's on top of, not a replacement for, the existing "type the device
  path to confirm" step already required before either Format dialog's button unlocks.
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
  inside one another. See [Setting up a USB backup drive / Cloning backups to a second
  drive](usb-drive-setup.md) for the step-by-step.
- **Restoring a backup.** This plugin only handles the pull; it has no restore button of
  its own, by design - use FPP's own built-in **File Copy Backup/Restore** page instead,
  so recovery goes through FPP's own, well-tested restore path. See
  [Restoring a Backup](restoring-a-backup.md) for the full walkthrough, including why
  File Copy Restore's device browser shows a `/` and a `System Volume Information`
  folder, and why neither of those should ever be selected as the thing you're restoring.
