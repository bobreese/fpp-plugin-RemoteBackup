# Features & Safe Guards

[← Back to README](../README.md)

## Features

[↓ Jump to Safe Guards](#safe-guards)

- **One Host, many remotes.** Designate a single FPP system as the Host. The install
  script and Config page both warn that only one system should ever have Host Mode enabled.
- **First-run Config walkthrough.** A brand-new install shows a one-time, click-through tour
  of the Config page - a spotlight and arrow step through each fieldset top to bottom with a
  short plain-language explanation, advancing on "Next" or on a click anywhere in the
  highlighted setting itself. The three sections whose contents are found by a scan (storage
  drives, remotes) say so explicitly rather than pretending to show you what's there, since
  nothing's been discovered yet the moment the page first loads. The "Show the setup
  walkthrough" checkbox above Backup Host Mode doubles as the recall control - check it
  anytime to run the walkthrough again - and as the on/off switch for the automatic first-run
  popup; a "?" next to it explains both in one place. Existing installs upgrading to this
  version never see the automatic popup unsolicited, since it's only wired to trigger on a
  genuinely fresh install.
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
  A **Select All** checkbox in the table header selects (or deselects) every listed remote
  in one click, including auto-pushing this Host's SSH key to each newly-selected one, same
  as checking each box individually - not just a raw "check every box" that would silently
  leave keys unpushed. It reflects the table's real state either way: fully checked only
  when every remote already is, fully unchecked only when none are, and shown as a dashed/
  indeterminate box for anything in between.
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
- **Runs as a real background process, independent of the browser.** Clicking Dry Run,
  Start Backup, or Start Clone launches the underlying script detached from that one web
  request and returns immediately - the transfer itself is a background process on the FPP
  system, not something tied to the page or tab that started it. Navigating to the FPP home
  page, closing the browser, or your phone locking doesn't pause or cancel it; it runs to
  completion (or failure) exactly the same either way, and progress is tracked on disk
  (`data/status/*.json`, `data/run_active.json`) rather than in the page itself, so coming
  back later - even from a different browser - just resumes showing the current live state.
  The only way to stop one mid-run is the explicit **Stop**/**Stop Clone** button; there's no
  timeout tied to the page being open. Expect a real delay between clicking and the Backup
  Status table showing anything, scaling with how many remotes are selected - Dry Run and
  Start Backup both run the pre-flight space check (see
  [Safe Guards](#pre-flight-space-check-with-a-safety-margin-on-sd-card-storage) below)
  sequentially, one remote at a time, before any of them show as queued/running, so the
  more remotes selected, the longer that initial wait. This is expected, not a hang.
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
  it first if you want to use it from FPP's native File Copy Restore, or turn on the
  restore-visibility toggle described under
  [Safe Guards](#restore-visibility-automatically-paused-during-a-run) below instead. Drives
  formatted by an older version of this plugin (filesystem directly on the raw disk, no
  partition table) need to be re-formatted to pick this up; existing backups on them are
  unaffected until you do.
- **Show Schedule Conflict Check.** Config's own panel of the same name reads the
  configured schedule straight off whichever system you designate as the show master
  (`/api/schedule`, that system's own FPP API) and lays it out as a Sunday-through-Saturday
  table, plus a quick "does this day/time conflict" checker - a proactive complement to the
  reactive "won't start while a show is running" check under
  [Safe Guards](#wont-start-while-a-remote-is-playing-a-sequence) below, for picking a
  backup time in the first place rather than reacting to one that's already live. Clearly
  marked as a recommendation to verify, not a guarantee - see
  [Show Schedule Conflict Check](schedule-conflict-check.md) for exactly why (expired/
  disabled entries filtered out, but day-of-week codes and `SunSet`/`SunRise`-anchored
  entries both carry real caveats worth reading before trusting it against a live show).
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
- **Browse backups.** The Status page's "Backed Up" dropdown lists every backup on the
  destination storage - both a rolling "current" backup and a dated snapshot-mode one are
  listed the same way - as `<Hostname> - <date>`, sorted by remote first and date second
  (not purely by most-recent-first). The ⟳ button next to it re-scans without a full page
  reload. Selecting one shows its full path (e.g.
  `/mnt/Backups/FPPBeagleBlack-20260824`), total size and file count, and a folder-structure
  listing with each top-level item's own size - folders before files, each folder's size
  rolled up as a whole rather than expanded item-by-item, so a quick look tells you where
  the space actually went without walking the whole tree by hand. See
  [Safe Guards](#deleting-a-backup-requires-an-explicit-confirmation) below for what stops
  a delete from that same panel from being an accidental click.
- **Clone backups to a second drive.** Optional and manual only (no Scheduler command) -
  format/mount a second USB drive on the Config page, then click "Start Clone" on the
  Status page to mirror everything on the primary destination onto it in one pass
  (`rsync --delete`, so it always exactly matches the primary - a backup you deleted there
  is removed from the clone too), for an occasional off-site or rotating spare copy. See
  [Setting up a USB backup drive / Cloning backups to a second
  drive](usb-drive-setup.md) for the step-by-step, and
  [Safe Guards](#clone-safety-checks) below for what stops it from running at the wrong
  time or onto the wrong drive.
- **Restoring a backup.** This plugin only handles the pull; it has no restore button of
  its own, by design - use FPP's own built-in **File Copy Backup/Restore** page instead,
  so recovery goes through FPP's own, well-tested restore path. See
  [Restoring a Backup](restoring-a-backup.md) for the full walkthrough, including why
  File Copy Restore's device browser shows a `/` and a `System Volume Information`
  folder, and why neither of those should ever be selected as the thing you're restoring.
- **A floating Save Settings bar.** On by default - the Save button (with Status and the
  save result message alongside it) stays pinned to the bottom of the screen as you scroll
  through Config's fieldsets, instead of only being reachable at the very bottom of the
  page. Its own "Keep floating while scrolling" checkbox turns this off if you'd rather
  have it back in its normal spot in the page - a per-browser display preference stored
  locally, not a saved setting, so it applies immediately and doesn't need Save Settings
  clicked to take effect.

## Safe Guards

Everything below exists to stop this plugin from doing something destructive or
misleading - erasing the wrong drive, running out of space mid-backup, two things writing
to the same place at once, or a restore reading data that isn't actually finished yet.
Plain-language summary first; click anything to jump straight to the full explanation.

- [Won't start while a remote is playing a sequence](#wont-start-while-a-remote-is-playing-a-sequence)
- [If the backup drive goes missing, backups pause instead of failing blind](#missing-destination-detection-and-failover)
- [Backups refuse to start without enough room - and always leave a cushion on the SD card](#pre-flight-space-check-with-a-safety-margin-on-sd-card-storage)
- [Formatting a drive has extra safeguards against erasing the wrong one](#format-safety-checks)
- [Cloning to a second drive can't overwrite the wrong drive either](#clone-safety-checks)
- [Only one backup can run at a time](#only-one-backup-run-at-a-time)
- [Plugin settings repair themselves automatically if they ever get corrupted](#self-healing-plugin-settings)
- [While a backup is running, restores are automatically paused](#restore-visibility-automatically-paused-during-a-run)
- [Deleting a backup requires an explicit confirmation](#deleting-a-backup-requires-an-explicit-confirmation)
- [A real backup refuses to run unless Host Mode is enabled](#a-real-backup-refuses-to-run-unless-host-mode-is-enabled)
- [Leaving the SD Card as destination offers to clean up old backups there](#leaving-the-sd-card-as-destination-offers-to-clean-up-old-backups-there)

### Won't start while a remote is playing a sequence

Before a manual or scheduled run touches anything, every selected remote's own FPP API is
checked; if any of them are currently playing a sequence, by default the whole run is
refused (not just that remote) rather than risking stutters/dropped frames from reading the
same SD card fppd is actively playing off of - Config's Backup Options can instead have it
skip just the busy remote(s) and back up everything else. A remote that can't be reached is
treated as unknown, not playing, so it doesn't block backing up everything else. See
[Troubleshooting](troubleshooting.md#remote-playing-a-sequence) for the full walkthrough.

[↑ Back to Safe Guards list](#safe-guards)

### Missing-destination detection and failover

If a configured USB/NVMe/SSD destination stops being found mounted (unplugged, powered
off, failed) while the Status or Config page happens to be open, a popup offers two ways
to respond: **Halt Backups** refuses any manual or scheduled run with a clear reason until
the drive reappears or a new destination is saved, and **Use Failover** immediately
switches the destination to SD Card / System Storage (always available, no drive required)
so scheduled backups keep running. A halt clears itself automatically the moment the
missing drive is seen mounted again, or a different destination is saved. See
[Troubleshooting](troubleshooting.md#backup-destination-missing) for the full walkthrough.

[↑ Back to Safe Guards list](#safe-guards)

### Pre-flight space check, with a safety margin on SD Card storage

Before copying anything, every real run estimates the total transfer across every selected
remote (the same `rsync --dry-run` pass a regular Dry Run does, so it correctly credits
files that already exist on the destination) and compares it to free space right at that
moment. If it won't fit, the run is refused before anything is copied and a **"Backup Space
Insufficient"** popup offers **Start Anyway**, **Replace Destination**, **Use Failover**, or
**Stop Backup**. A scheduled run applies a fixed policy instead (refuse and log, or
auto-failover if turned on), since there's nobody there to answer a popup.

When the destination is **SD Card / System Storage** specifically, this check always keeps
back 500MB, not configurable - that's the one destination sharing its filesystem with FPP
itself (its logs, its database, and whatever sequence is actively playing), unlike a
dedicated USB/NVMe/SSD drive where running it down to the last byte only breaks backups, not
the system. A run that would leave less than 500MB free there is refused the same way as one
that flat-out doesn't fit. This also applies when a scheduled run auto-fails-over to SD
Card - it's re-checked against the SD card's own free space (with the same reserve) rather
than just assumed to have room. "SD Card" here means whatever the OS is actually booted
from - **onboard eMMC on boards that use it as the boot/root device carries the exact same
risk and the exact same 500MB reserve**, not just a physical SD card.

See [Troubleshooting](troubleshooting.md#backup-space-insufficient) for the full
walkthrough, including the eMMC-as-auxiliary-storage case (some boards can boot from SD
and expose eMMC as separate, non-root storage instead) and why this plugin doesn't
currently detect a non-root eMMC as its own selectable destination.

[↑ Back to Safe Guards list](#safe-guards)

### Format safety checks

Both the primary and secondary/clone drive Format flows refuse to touch the disk FPP
itself is currently running from - resolved by asking the system for the actual device
backing the root filesystem (whatever it is: SD card, NVMe, or USB), not by guessing from a
device name pattern, so it works the same way no matter which media FPP booted from. This
also blocks any other partition on that same physical disk (e.g. a boot partition sitting
alongside the root partition), not just the exact root partition itself. On top of that,
every Format dialog's button stays disabled until you type the exact device path shown
(e.g. `/dev/sda`) into a confirm box - a deliberate extra step before anything that erases a
drive, not just a single "are you sure?" click.

[↑ Back to Safe Guards list](#safe-guards)

### Clone safety checks

Cloning to a second drive refuses to run at the same time as a backup run or a primary-drive
format (it reads from the same destination those write to), and the reverse is also true -
you can't start a backup, or format/unmount the primary drive, while a clone is running.
It also refuses outright if the primary and secondary turn out to be the same physical
drive, or one is nested inside the other's mountpoint - mirroring a directory into itself
(or its own parent) with `rsync --delete` could otherwise corrupt or wipe every backup on
the primary.

[↑ Back to Safe Guards list](#safe-guards)

### Only one backup run at a time

A real, kernel-enforced lock file (`data/run.lock`, held via `flock` for the entire duration
of a run) guarantees only one backup can be running at once, no matter how it was started -
a manual click, a Scheduler entry, a Playlist/Event Command, or a direct command-line
invocation. A second attempt while one is already running is refused outright with a clear
reason, rather than letting two runs write to the same destination, log files, and per-remote
status at the same time. Unlike a hand-rolled "is something running" flag, this kind of lock
can never get stuck "held" by a process that crashed, was killed, or lost power mid-run - it
releases automatically the instant that process stops running, for any reason, so a bad exit
can never require manually clearing a stuck lock before the next backup can start.

[↑ Back to Safe Guards list](#safe-guards)

### Self-healing plugin settings

`data/settings.json` is mirrored on every successful write to both `data/settings.json.bak`
and an external copy at `/home/fpp/media/.fpp-plugin-RemoteBackup-settings.bak` -
deliberately outside `data/` (and outside this plugin's own directory entirely), since a
real incident proved a single in-directory backup isn't independent protection against
whatever's actually causing this (something outside this plugin, entirely outside its
control, wiping the whole `data/` directory - not just one file in it - on some systems). If
the live file is ever found empty or unreadable, it's restored automatically from whichever
backup is still good the next time anything touches it, instead of silently running on
defaults indefinitely. See [Troubleshooting](troubleshooting.md#settings-reset-to-defaults)
for the full story.

[↑ Back to Safe Guards list](#safe-guards)

### Restore visibility automatically paused during a run

The optional "see current backups without unmounting" toggle (Config > Backup Destination
Storage - see [Setting up a USB backup
drive](usb-drive-setup.md#seeing-current-backups-without-unmounting-the-drive)) makes a
mounted drive's current contents visible to FPP's native restore without unmounting it
first. That visibility is automatically paused for the entire duration of every backup run
- manual, scheduled, or Command-triggered - and restored the instant the run finishes, so
FPP's native restore (or a remote's own File Copy Backup/Restore) can never read a
partially-written, in-progress backup through this path. This is expected behavior, not a
malfunction: the Config page shows "Temporarily paused - a backup run is in progress"
during that window instead of the usual "active" status. A crash, a kill, or power loss
mid-run can't leave it stuck paused either - it's guaranteed to reconcile back to normal
automatically, whether that run finished cleanly or not.

[↑ Back to Safe Guards list](#safe-guards)

### Deleting a backup requires an explicit confirmation

Selecting **Delete This Backup** in the Status page's "Backed Up" panel opens a
confirmation dialog naming the exact folder about to be deleted and its size, with a
checkbox - **Confirm the backup folder being deleted** - that has to be ticked before the
Delete button in that dialog does anything; leaving it unchecked and clicking Delete
refuses with a toast instead of deleting anything. This is a checkbox, not a
type-the-name-back field, deliberately: the folder being deleted is already shown right
there in the dialog, so retyping it doesn't add a real extra safety check the same
deliberate read-and-tick already provides. There's no undo and nothing kept in a trash -
once confirmed, the folder is gone, so this confirmation step is the only thing standing
between a click and losing that backup.

[↑ Back to Safe Guards list](#safe-guards)

### A real backup refuses to run unless Host Mode is enabled

Config's **"Enable this system as the Remote Backup Host"** checkbox now actually gates
whether a real backup can run - a manual Start Backup, a Scheduler command, or a bare
CLI/cron invocation of `run_backup.sh` all refuse outright if this system's own
`hostModeEnabled` setting is off, with a clear error either way (an immediate toast from
the Status page, or a plain line in the Scheduler's own command output/`engine.log`).
`run_backup.sh` is the authoritative check, so nothing can route around it by adding a new
way to trigger a run - the Status page and Scheduler command just add an earlier, friendlier
refusal in front of it. **Dry Run is deliberately exempt** and always works regardless of
this setting: it never writes anything to any destination, so there's nothing unsafe about
running one to sanity-check a system before flipping Host Mode on for the first time.

[↑ Back to Safe Guards list](#safe-guards)

### Leaving the SD Card as destination offers to clean up old backups there

Scoped specifically to switching *away from* SD Card / System Storage - a real external
drive being swapped out already physically leaves with its data either way, but the SD
card fallback's backups (`/home/fpp/media/backups`) live on the Host's own limited system
storage, so forgotten ones just sit there indefinitely eating into space FPP itself needs.
The moment you click a different storage radio on the Config page (not deferred until
Save Settings, so it's never a surprise buried behind a click that might happen much
later), a popup offers **Leave Them** or **Remove Them Now**. Nothing actually happens
either way until you click **Save Settings** - the choice is just recorded until then,
consistent with every other Config change - and a small note next to the Save button
reflects whichever you picked so it's not a forgotten, silent decision. Choosing "Remove
Them Now" only ever deletes folders that actually match this plugin's own backup naming
pattern, the same safety check `fpp_uninstall.sh --purge-backups` already uses elsewhere -
never a blind wipe of the whole directory.

[↑ Back to Safe Guards list](#safe-guards)
