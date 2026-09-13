# Remote Backup (fpp-plugin-RemoteBackup)

An FPP plugin that turns one Falcon Player system into a **Backup Host**, automatically
pulling backups from one or more of your other FPP systems onto local storage.

- Preview how much space a backup needs before it runs.
- Schedule backups to happen on their own, or run one manually anytime.
- Optionally keep a second copy on another drive for extra safety.
- Optional dated snapshot history per remote, with a configurable retention window,
  instead of just one rolling backup.
- Optional post-run integrity check compares source and destination once more and
  flags anything that doesn't match.
- Optionally back up system/network config alongside each remote's content, for a
  full rebuild after a reflash.
- Won't back up a system while it's playing a show, so playback is never put at risk.
- Can back up a remote even if its fppd has crashed or stopped — connects over
  plain SSH, independent of FPP's own multisync discovery and rsync daemon, which
  native File Copy Backup needs that remote's fppd alive for.
- Can automatically fall back to SD Card storage if a scheduled run's usual destination
  is too full, instead of just refusing to run.
- Built-in safeguards keep backups from overwriting the wrong drive, running two at once,
  or leaving things in a broken state.
- See current backups from FPP's own File Copy Restore without unmounting the
  destination drive first.
- Optional email status updates after a run, sent through FPP's own Email settings —
  no separate mail setup for this plugin to configure.

## Privacy & Data Handling

This plugin only pulls — it never sends your show content anywhere. In short:

- **What it sends:** SSH/rsync commands to the remotes you select (nothing else), and — only if you configure an email in FPP Settings — a short status summary after each run.
- **What it collects:** a full copy of each selected remote's `/home/fpp/media/` tree, stored at your chosen backup destination. This includes that remote's own FPP settings file (which can hold its UI/OS/email/MQTT passwords, GitHub token, etc. in plain text) and any data other installed plugins keep there — the same content a manual `rsync` of that folder would pull. If you enable "Include system config," it also grabs that remote's network config, including its WiFi password. If you set a default SSH password in this plugin's own settings, that's stored locally in `data/settings.json`.
- **What it changes:** pushes its own SSH key to each selected remote's `authorized_keys` for passwordless access (and removes it again, best-effort, on uninstall); can mount a USB drive via `/etc/fstab`; installs a handful of packages (`rsync`, `jq`, `sshpass`, etc.) via `apt-get` if missing; sets FPP's restart flag after install/uninstall.
- **No network exposure:** this plugin doesn't open any port or service of its own — all connections are outbound, initiated by this device toward remotes you've explicitly selected.
- **Source code:** fully open — nothing closed-source or obfuscated.

This mirrors the formal privacy declaration in [`pluginInfo.json`](pluginInfo.json), which is the authoritative, machine-readable version (used by FPP's plugin listing).

## Documentation

- [Notes / assumptions and License](docs/notes-and-license.md)
- [FPP Backup vs. Remote Backup](docs/fpp-vs-remote-backup.md)
- [How Remote Backup Works](docs/how-it-works.md)
- [Features & Safe Guards](docs/features.md)
- [Requirements, Install, and Uninstall (incl. Known minor gaps)](docs/requirements-install-uninstall.md)
- [Setting up a USB backup drive / Cloning backups to a second drive](docs/usb-drive-setup.md)
- [Network Shares and Virtual Machines (what's not supported, and why)](docs/network-shares-and-virtualization.md)
- [Scheduling backups](docs/scheduling.md)
- [Show Schedule Conflict Check](docs/schedule-conflict-check.md)
- [Estimated Backup Times](docs/estimated-backup-times.md)
- [Restoring a Backup](docs/restoring-a-backup.md)
- [Log Files](docs/log-files.md)
- [Troubleshooting](docs/troubleshooting.md)
- [Directory layout](docs/directory-layout.md)
- [Changelog](docs/changelog.md)
- [Replacing FPP's Native Backup: A Readiness Assessment](docs/backup-replacement-assessment.md)
