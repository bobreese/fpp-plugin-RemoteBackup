# Restoring a Backup

[← Back to README](../README.md)

This plugin only ever pulls backups down - it has no restore button of its own, by
design. Recovery always goes through FPP's own built-in **File Copy Backup/Restore**
page (under Content Setup), which already knows how to restore
sequences/media/playlists/effects safely. There are two ways to get to it, depending on
where the destination drive physically is at the time:

> **Do not restore `/` or `System Volume Information`** if File Copy Restore's device
> browser shows them - neither is a backup. Always browse all the way into a specific
> remote's own `<Hostname>-<YYYYMMDD>` folder first.

1. **Using the Host, over the network.** Leave the destination drive right where it is,
   still attached to the Host. On whichever system you're restoring to, open its own
   *File Copy Backup/Restore* page, point the "Remote Storage" source at the Host, and
   browse into the remote's own `<Hostname>-<YYYYMMDD>` folder (or
   `<Hostname>/<YYYYMMDD>` if Snapshot mode was enabled) to restore from. This is the
   easiest option whenever the system you're restoring to still has working network
   access back to the Host.
2. **Using the drive directly in the device's own USB port.** Useful when the system
   you're restoring has no network access yet (e.g. a from-scratch rebuild after a dead
   SD card) or you'd just rather not depend on the network for it. **Restoring onto the
   Host itself?** Skip the physical move entirely - just Unmount on the Config page,
   then go straight to step 3 on the Host's own File Copy Backup/Restore page; the drive
   never has to leave its own USB port.
   1. On the Config page, **Unmount** the destination drive from the Host first - never
      unplug it while still mounted.
   2. Physically move the drive to the system you're restoring, and plug it into one of
      *that system's own* USB ports (skip this step if you're restoring onto the Host
      itself - see above).
   3. Open *that system's own* File Copy Backup/Restore page. Because this plugin always
      formats destination drives with a real GPT partition table (not a filesystem
      directly on the raw disk), the drive is recognized by FPP's own device picker on
      any FPP system, not just this plugin - it'll show up there the same way it would
      in this plugin's own Config page.
   4. Browse into that remote's own `<Hostname>-<YYYYMMDD>` folder on the drive - the
      same layout as restoring over the network, just browsed locally instead. The
      drive normally holds every selected remote's backups side by side; ignore the
      others and pick the one that's yours.
   5. When you're done, move the drive back to the Host, Mount it again on the Config
      page, and re-select it as the destination if needed before the next backup run -
      only one system can have it plugged in at a time.

A few things that apply either way:

- You don't have to restore to the same physical remote a backup came from - either
  method works just as well for rebuilding/cloning onto a different system, as long as
  you pick that remote's own folder on the drive.
- The `system-logs.tar.gz`/`system-config.tar.gz` archives inside a backup (if "Include
  system config" is enabled) are deliberately packaged as `.tar.gz` files rather than
  plain folders, specifically so File Copy Restore's device browser doesn't mistake them
  for restorable show-content backups of their own - they aren't part of its file-level
  restore either way. Extract those yourself (`tar xzf`) over SSH/SCP if you need the
  original `/etc/fpp`, network config, or relocated log directory back.
- Rolling mode (the default) only ever keeps each remote's most recent backup -
  restoring an older point in time requires Snapshot mode to have been enabled before
  that backup was made.
- Because this plugin formats the drive with a real GPT partition table and a single
  partition, File Copy Restore's device browser starts you at the root of that
  partition, shown as `/` - the same way any file browser shows the top of a drive
  before you descend into it, not something this plugin adds. You may also see a
  `System Volume Information` folder there - that's Windows, not this plugin,
  automatically created the moment the drive is plugged into a Windows PC (System
  Restore, Volume Shadow Copy, indexing). It's harmless and can be ignored.
- **Do not select `/` itself or `System Volume Information` as what you're restoring.**
  Neither is a backup - `/` is the whole drive (every remote's backups and anything else
  on it, all at once) and `System Volume Information` has no show content in it at all.
  Always navigate all the way into a specific remote's own `<Hostname>-<YYYYMMDD>`
  folder (or `<Hostname>/<YYYYMMDD>` in Snapshot mode) before restoring - that folder,
  not the drive root, is the actual backup.

## After a fresh SD card (a from-scratch rebuild)

Restoring your content/config backup onto a brand new SD image brings your show and
network settings back, but it can't bring FPP's own software version along - that's a
property of the image you flashed, not something any backup/restore tool (this plugin's
or FPP's own File Copy Restore) touches. A couple of things worth doing around that,
specifically because you're starting from a fresh image rather than upgrading in place:

- **Flash the latest official FPP release** - via [Raspberry Pi Imager](https://www.raspberrypi.com/software/)
  (search for "FPP" under Choose OS) or directly from
  [FPP's GitHub releases](https://github.com/FalconChristmas/fpp/releases) - not an older
  release image you happen to have sitting around, and **not a nightly build**. Whatever
  image you flash ships with whichever FPP version was current when that image was built,
  and FPP only catches itself up from there via its own updater - the older the image, the
  more individual updates stand between it and current, so starting from the latest
  release minimizes that gap. Nightly builds are worth avoiding here specifically (even
  though they're newer): they come up in Master mode rather than Player mode, which isn't
  what a from-scratch Player rebuild wants. This applies to any FPP system being rebuilt
  from an image, whether or not you use this plugin at all.
- **On FPP 10, look for the "Restore from Backup" button on first boot** - it sits at the
  top right of the very first setup screen, before you've gone through the rest of the
  setup wizard, and can jump straight into a restore from there. Older FPP versions don't
  have this shortcut: you'd finish the setup wizard first, then separately find your way
  to File Copy Restore under Content Setup afterward, as described above.
- **Update FPP itself first, then check for plugin updates separately** - FPP's home page
  shows its own warning/update indicator when FPP core is behind, but an installed
  plugin's own update state (this one included) is tracked separately from FPP core.
  Being caught up on FPP itself doesn't mean every plugin is too - check the Plugin
  Manager after updating FPP, not just the home page's own warning.
- Restore your content/config from this plugin's backup after FPP (and its plugins) are
  updated, not before. It works either order, but updating first means you're only
  troubleshooting one variable at a time if anything looks off afterward, rather than
  wondering whether it's the restore or the pending updates.
