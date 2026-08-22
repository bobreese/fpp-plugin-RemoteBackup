# Changelog

[← Back to README](../README.md)

Notable fixes and changes, newest first (this plugin tracks `master` directly rather
than tagging releases, so this is a running list rather than versioned entries):

- **Added** a "Replacing FPP's Native Backup: A Readiness Assessment" section to the
  documentation (`docs/backup-replacement-assessment.md`), linked from README. A
  plain-language audit of what stands between Remote Backup today and fully replacing
  FPP's native `Backup/Restore Configuration` and `File Copy Backup/Restore`, kept as a
  working list to re-evaluate as the plugin matures rather than a one-time verdict.
  Findings are rated Critical (no equivalent to FPP's config-only backup; no restore
  path of its own; explicitly Beta with no fallback), Significant (single-Host point of
  failure; every remote must be SSH-reachable; no proactive alert on silent failure), and
  Minor (no integrity/test-restore verification; a couple of already-documented rough
  edges; still shaking out real bugs). Also covers a narrower question - replacing just
  `File Copy Backup` while leaving config backup and restore native - which two of the
  three Critical findings don't apply to, and concludes as a net strengthening of FPP's
  overall backup story provided the single-Host and silent-failure findings are actually
  addressed first.
- **Fixed:** "Download All Logs" (and "Download," for a single log) could keep serving a
  stale, previously-downloaded archive/file on every click after the first, instead of
  the current contents of `data/logs/`. Both are plain GET requests to a URL that never
  varies, and `ajax.php` never started a PHP session (the usual source of an automatic
  `Cache-Control: no-store`) or set one explicitly, so a browser was free to cache the
  response and keep replaying it indefinitely - confirmed against a real report where the
  downloaded zip's `ajax.log`/`engine.log` were both far smaller than the actual files on
  disk, and running `scripts/zip_logs.sh` directly over SSH correctly reported every log
  file (`fileCount`) while the web UI kept returning the same old 2-file archive. Added an
  explicit `Cache-Control: no-store, no-cache, must-revalidate` header to both download
  responses, plus `{cache: 'no-store'}` on the client `fetch()` call as defense-in-depth.
- **Added** missing-destination detection with a Halt/Failover popup. If a configured
  destination drive stops being found mounted while the Status or Config page happens to
  be open, a "Backup Destination Missing" popup now offers **Halt Backups** (refuses any
  manual or scheduled run with a clear reason, logged to `engine.log`, until resolved) or
  **Use Failover** (immediately switches the destination to SD Card / System Storage,
  always available since it's the filesystem root). New `haltBackups`/`useFailover` ajax
  actions and a `haltedReason` setting that `run_backup.sh` checks and refuses on, same
  guard pattern as the existing "already running"/"remote playing" refusals. The halt
  clears itself automatically once the missing destination reappears mounted (checked
  every `status` poll) or a different destination is saved - no separate "resume" step.
  Each page detects independently (status.php's existing poll; config.php gained its own
  lightweight 15s background poll purely for this, since it otherwise has no live-run
  polling of its own). See [Troubleshooting](troubleshooting.md#backup-destination-missing)
  and [Features](features.md).
- **Changed** the Config page's Mount/Format & Mount flow to pre-select the drive it just
  mounted as the destination (the storage list's radio button is now checked
  automatically), so activating a freshly mounted drive is one click ("Save Settings")
  instead of two. Applies to both the plain "Mount as Backups" flow and "Format & Mount
  as Backups"/"Re-format..." (the latter is a no-op there, since a re-formatted drive was
  already the active destination). Nothing is saved automatically - "Save Settings" is
  still required, same as every other Config change.
- **Fixed:** the "SD Card / System Storage" group in the Config page's storage list could
  show a second, spurious entry with a full activation radio button for the system's boot
  partition (e.g. `/boot` or `/boot/firmware`, labeled `bootfs` on a typical Pi image) -
  `probe_storage.sh` buckets that group by physical disk, not by mountpoint "/", so a boot
  partition mounted on the same disk as root landed in the same group as the real SD
  Card/System Storage fallback. Only "/" was ever a valid destination; selecting the boot
  partition would have meant trying to write backups onto FPP's own tiny FAT32 boot
  partition. The boot partition is still shown (same label/format as before, for
  visibility) but with no activation control - a small note explains why.
- **Added** an "Estimated Backup Times" section to the documentation
  (`docs/estimated-backup-times.md`), linked from README right after "Scheduling
  backups." Since this plugin's transfers are `rsync` over SSH, the dominant factors are
  network link speed and destination write speed rather than raw Pi CPU power (all of
  Pi3/Pi4/Pi5 have hardware-accelerated AES): a Pi3 anywhere in the chain caps
  everything at its 100 Mbps Fast Ethernet regardless of destination storage, while on
  Pi4/Pi5 over Gigabit the destination drive (USB stick vs SSD/NVMe) usually becomes the
  limiting factor instead. Also covers how the default 2-concurrent-remote queue shares
  whatever the actual bottleneck is. These are reasoned estimates from each Pi
  generation's known hardware limits, not measured benchmarks from this plugin.
- **Fixed:** the Status page's dry-run summary showing a garbage (sometimes negative)
  "Available on destination" figure and wrongly reporting "NOT enough free space on
  destination" on 32-bit systems (e.g. a stock 32-bit Raspberry Pi OS image on a Pi3),
  even with plenty of real free space on a correctly-mounted drive. `ajax.php` computed
  the destination's free/total space via PHP's `disk_free_space()`/`disk_total_space()` -
  which deliberately return `float`, precisely so a real drive's size survives on a
  32-bit PHP build, where native `int` tops out around 2.1GB - and then immediately
  cast that through `intval()`, truncating/overflowing back down to a 32-bit int for
  any drive larger than ~2GB. Removed the `intval()` casts on both the primary
  destination and the secondary clone drive's free/total/used space, keeping them as
  the floats PHP already returns; `json_encode()` and JS handle those natively with no
  precision loss for any realistic drive size (doubles are exact up to 2^53 bytes,
  around 9000 TB). Purely a display bug - the actual backup/dry-run itself was never
  blocked by this, since `run_backup.sh` does its own free-space math independently in
  bash, unaffected by PHP's int size.
- **Added** a "Troubleshooting" section to the documentation (`docs/troubleshooting.md`),
  covering what SSH key push failures actually look like (the Config page's "key push
  failed" status, each `ssh_setup.sh` error message, and the raw `ssh`/`sshpass` text
  bucketed under the generic "Key push failed (rc=N)" case) and how to fix each one -
  including the password-mismatch case that's by far the most common, the reimaged-remote
  ("new SD/boot device") host-key case that's already handled automatically and shouldn't
  normally be seen, and the rarer "push reports success but backups still fail with
  Permission denied (publickey)" case caused by wrong `~/.ssh` permissions on the remote.
  Linked from the "Authenticate" step in [How Remote Backup Works](how-it-works.md).
- **Fixed:** the "Authenticate" step in the in-app Help page and
  [How Remote Backup Works](how-it-works.md) read as if pushing the SSH key were a manual
  action required after checking a remote. In reality `config.php` already pushes the key
  automatically (silently, using the stored/default password) the instant a remote's
  checkbox is checked or a remote is manually added; the "Push SSH Key" button is only
  needed as the retry path when that automatic push fails. Reworded both copies of the
  step to describe the actual behavior.
- **Changed** the README from one long document into a short landing page (title,
  description, and a linked table of contents) plus a set of focused files under
  `docs/` - Features, Requirements/Install/Uninstall, USB drive setup + cloning,
  Scheduling, Restoring a Backup, Log Files, Directory layout, Notes/assumptions +
  License, and this Changelog - so each topic can be read (and linked to) on its own
  instead of scrolling one very long page. The Features section's "Restoring a backup"
  bullet, which had grown to substantially duplicate the in-app Help page's own
  "Restoring a Backup" section, is now a short summary linking to
  `docs/restoring-a-backup.md` instead of repeating the same ~50 lines in two places.
  Also added `docs/plugin-guidelines-compliance.md`, a compliance summary against FPP's
  `PLUGIN_GUIDELINES.md`/`PLUGININFO_FORMAT.md`. `pluginInfo.json`'s `documentation`
  field and the in-app Help page's "Full documentation on GitHub (README)" button both
  still point at `README.md` directly and needed no change.
- **Added** a usage hint to the Config page's "Remote Systems to Back Up" section,
  pointing out that manually adding a remote starts with clicking in the Hostname box on
  the left. (The per-row **Remove** button, to drop a remote from the list entirely, was
  already added in an earlier change alongside the MultiSync rename fix.)
- **Added** a click confirmation to Dry Run, Start Backup, and Start Clone: each button
  turns green the instant it's clicked and reverts to its normal color once the run it
  started actually finishes - purely a "yes, that registered" signal, distinct from the
  existing state label/progress bar. Dry Run and Start Backup share one underlying active
  flag, so which of the two to revert is tracked separately from the flag itself (clicking
  one never turns the other green). A 60s safety timer reverts a button on its own in case
  the normal active-to-inactive detection is ever missed (e.g. a run finishing faster than
  the poll interval), so a button can't get stuck green indefinitely. Verified with a
  standalone test: click-to-green, revert on the run finishing, the two shared-flag buttons
  staying independent of each other, the clone button being fully independent of both, and
  the safety timeout firing correctly when nothing else clears it.
- **Fixed:** a Host-local run (the Host backing itself up, not over SSH) with "Include
  system config" enabled left a `data/tmp_extras_<id>_*` scratch directory behind after
  every run, full of `rm: ... Permission denied` errors in `last_start.log` for files
  under `system-config/network/`, `system-config/wpa_supplicant/`, `system-config/fpp/`,
  etc. Cause: `SYSTEM_CONFIG_PATHS` (`/etc/network`, `/etc/wpa_supplicant`, `/etc/fpp`,
  ...) is pulled locally via `sudo rsync` for the Host's own backup - `rsync -a` run as
  root preserves the source files' real root:root ownership, so the scratch directory
  ended up containing root-owned entries that the plain (non-sudo) cleanup `rm -rf`
  afterward couldn't remove, since this script otherwise runs as the plain `fpp` user.
  Changed that cleanup to `sudo rm -rf` (passwordless local sudo for `fpp` is already
  relied on elsewhere in this script and plugin). Also added a one-time sweep at the
  start of every run for any `tmp_extras_*` directory left behind by an earlier run
  (before this fix, or from any other interruption) - verified against a real directory
  tree that it removes exactly the leftover `tmp_extras_*` entries and nothing else
  (`settings.json`, `logs/`, `status/` untouched).
- **Changed** `pluginInfo.json`'s `versions` array from one open-ended entry
  (`minFPPVersion: "9.0"`) to two explicit entries - one for FPP 10.0+ and one for FPP
  9.0+, both tracking `master` at the latest commit (`sha: ""`). Functionally identical
  to the single entry it replaces (FPP picks the first matching entry, and 10.x already
  matched the old 9.0+ range), but makes FPP 10 compatibility explicit rather than
  implicit in an open-ended upper bound - modeled on a two-entry pattern from another
  FPP plugin's `pluginInfo.json` (`fpp-plugin-AdvancedStats`), minus that plugin's
  pinned-sha-for-older-FPP entry, since this plugin has no FPP9-vs-FPP10 code
  divergence to pin against.
- **Added** a "Not seen in N days" badge for a MultiSync-discovered remote that hasn't
  appeared in a scan for over 24 hours - `mergeRemoteLists()` now stamps `lastSeenAt` on
  every multisync-sourced entry each time it's actually seen (persisted in
  `settings.json`), and `renderRemotes()` flags anything past that threshold. Flags only,
  never auto-removes: since rescans only ever happen when the Config page is open (no
  scheduled background scan), auto-removing on a timer would risk silently dropping a
  remote that's simply been offline briefly, or just hasn't had a rescan happen in a
  while, out of the active backup selection with no notice - the same failure mode ruled
  out for a full-rebuild-on-rescan approach. Never applies to manually-added remotes
  (`source: 'manual'`), which are expected to not appear in a MultiSync scan by design.
  Verified with a standalone test: a remote scanned 23h ago stays unflagged, one at 25h
  gets flagged (while still keeping its selected state, never removed), and the flag
  clears the moment it reappears in a scan.
- **Fixed:** renaming a remote's System Name in FPP (same device, same address) produced
  a stale duplicate entry on Rescan - the old name stayed in the list untouched while a
  second entry was added for the new name, both pointing at the same address.
  `mergeRemoteLists()` matched purely by hostname, so a rename computed a different id
  than the existing entry and was treated as "a new remote appeared" rather than "an
  existing one's name changed." Now matches by address as a fallback and updates the
  existing entry in place (keeping its selected state and Push SSH Key status) when the
  hostname at a known address changes, with a jGrowl notice when it happens. Also added a
  **Remove** button per remote row, since there was previously no way to delete a stale
  entry (like any duplicate from before this fix) from the list at all. Verified with a
  standalone test reproducing the exact reported scenario plus edge cases (an unrelated
  new remote at a different address still gets added normally; repeated no-op rescans
  don't spuriously fire a rename; a manually-added remote's `source` survives a rename
  same as a MultiSync-discovered one's).
- **Changed** the Diagnostic Log's "Auto-tail" checkbox label to "Tail Follow." Behavior,
  the underlying element id, and the saved per-browser preference are all unchanged - this
  is a label-only rename.
- **Fixed/Changed**, prompted by a user question about `status`/`cloneStatus` polling
  showing up constantly in `ajax.log`:
  - `ajax.log`'s per-request `REQUEST action=...` line no longer logs routine
    `status`/`cloneStatus`/`getLog` polls (fired every 2-7s for as long as the Status page
    is open) - it was drowning out everything actually worth reading in there. Every other
    action (saves, formats, mounts, starts, deletes, downloads, ...) is still logged.
  - That same line's `user=` field was always the Host's own hostname
    (`php_uname('n')`), identical on every request regardless of caller - looked like
    caller identity, never was. Replaced with `client=$_SERVER['REMOTE_ADDR']`, the
    actual requesting IP.
  - `rb_volume_label()` (used by `status`/`cloneStatus` to show a drive's volume label)
    was shelling out to `findmnt` on every single poll - real, if small, recurring
    fork/exec overhead for a value that only ever changes on a reformat. Added a ~30s
    cache (`data/label_cache.json`), directly re-seeded with the known-fresh label right
    after a successful format rather than waiting on the next poll to re-discover it.
- **Added** "Download" and "Download All Logs" buttons to the Status page's Diagnostic Log
  section. Download saves the currently selected log as a plain text file; Download All
  Logs zips everything under `data/logs/` server-side (new `scripts/zip_logs.sh`, `zip`
  added to `fpp_install.sh`'s dependency list) and downloads the archive instead. Both show
  live status text while the file/archive is prepared, and surface real errors (e.g. `zip`
  missing) instead of a broken download. Investigated moving/copying a zip into FPP's own
  log directory to reuse its native download button instead, but a self-contained download
  endpoint keeps everything on this plugin's own Status page in one click, so went with
  that instead.
- **Added** a short, prominent `callout-warning` box to the Help page's "Restoring a
  Backup" section (right before the restore steps) with a condensed version of the `/`/
  `System Volume Information` warning below - the fuller explanation is still there too,
  this just makes sure it's seen before someone starts restoring, not just read afterward.
- **Added** a warning (README and Help page) not to select `/` or `System Volume
  Information` as the restore source in File Copy Restore - neither is a backup, and a
  specific remote's own `<Hostname>-<YYYYMMDD>` folder is always the actual thing to
  restore. Also brought the Help page's "Restoring a Backup" section up to date with the
  `/`-and-System-Volume-Information explanation the README already had.
- **Documented** why File Copy Restore's device browser shows a `/` before a drive's
  backup folders (it's just the root of the partition this plugin formats/mounts the
  drive with, same as any file browser), and why a `System Volume Information` folder can
  show up alongside them (a Windows-created artifact from plugging an exFAT-formatted
  drive into a Windows PC, not something this plugin creates).
- **Added** `icon.png` (256x256) so `pluginInfo.json`'s `iconURL` resolves to a real
  image instead of 404ing - it was declared but had never actually been committed.
  Replaces a wrongly-cased `Icon.png` that had briefly existed at the repo root
  (GitHub's raw file URLs are case-sensitive, so it never matched `iconURL`'s lowercase
  `icon.png` either).
- **Added** a `LICENSE` file (MIT) and a README "License" section linking to it - the
  plugin previously had no license file at all.
- **Documented** the existing boot-device format safety check in the README's Features
  list (no code change - `format_usb.sh` already refused to format whichever disk FPP is
  currently running from; this was just never called out).
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
