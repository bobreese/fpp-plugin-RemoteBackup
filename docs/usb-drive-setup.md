# Setting up a USB Backup Drive / Cloning Backups to a Second Drive

[← Back to README](../README.md)

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
   plus `- volume label "<label>"` when the drive has one) - **already selected**, since a
   successful Mount or Format & Mount pre-selects the drive it just mounted for you. Click
   **"Save Settings"** at the bottom of the page to make it official - like every other
   Config change, nothing takes effect, including which storage is actually used, until you
   save. (You can still pick a different drive first if you'd rather not use the one you
   just mounted.)

A few related things:

- **Re-formatting** an already-mounted drive uses the same dialog - click **"Re-format..."**
  next to it in the main storage list instead of "Format & Mount as Backups." If it's your
  active destination, this also clears every remote's backup status on the Status page,
  since whatever was there is now gone too.
- **Unmount** before physically unplugging the drive - click **"Unmount"** next to it.
  This detaches it and removes the `/etc/fstab` entry; the backups on it are untouched,
  and you'll need to Mount it again before the next backup run.
- **Using the drive with FPP's own File Copy Backup/Restore** (e.g. to restore from it, see
  [Features](features.md)): Unmount it here first. FPP's own device pickers never list a
  drive this plugin still has mounted.

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
