# Recovering a Lost Host

[← Back to README](../README.md)

If the Backup Host itself is destroyed, stops booting, or otherwise has to be replaced
outright - not a remote, the Host that runs this plugin and does the pulling - every
remote's scheduled backup stops at once until a new Host exists and is reconfigured.
This is the one thing a second drive (Clone Backups) and everything else this plugin
does can't protect against on their own, since Clone Backups also runs *from* that same
Host. This page is the procedure for getting backups running again on a replacement Host
as fast as possible, not a way to avoid the outage entirely - see
[Replacing FPP's Native Backup: A Readiness Assessment](backup-replacement-assessment.md#significant---weaken-the-case-for-full-replacement-matter-more-once-its-the-only-backup)
(finding #4) for why that outage can't be fully engineered away while one Host does the
pulling for everyone.

## What makes this fast instead of starting from nothing

Every real (non-dry-run) backup run writes `host-identity-backup.tar.gz` to the root of
the destination storage - a small, redacted copy of this Host's own `settings.json`:
every remote's hostname/address/selection, and every other plugin option (excludes,
concurrency, Snapshot Mode, retention, email settings, system-config inclusion, etc.).
It rides along automatically; there's nothing to remember to do ahead of time beyond
having run at least one real backup since the last settings change. Because it lives on
the destination drive itself - the thing most likely to survive the Host's own
destruction - it's there precisely when the Host isn't.

**What it deliberately does NOT include: this Host's own SSH keypair**
(`id_rsa_remotebackup`/`.pub`), or the `sshPassword` default if one was configured.
Copying the Host's actual private key onto a removable drive would mean a lost or
stolen drive could be used to actively authenticate against every remote's
`authorized_keys`, not just expose old backup content the way losing the drive already
can today - a real escalation this procedure isn't worth accepting for the convenience
it would buy. A replacement Host generates its own fresh keypair on install, the same as
any new install, and needs to re-authenticate to every remote as part of this procedure
- see step 5.

## Procedure

1. **Set up the replacement Host.** Install FPP (or use an already-running system you're
   repurposing) and install this plugin on it, exactly as a first-ever install - see
   [Requirements, Install, and Uninstall](requirements-install-uninstall.md).
2. **Attach the drive.** Physically move the primary destination drive (or the Clone
   Backups secondary drive, if that's what you have access to) to this new Host and
   mount it on the Config page. Because this plugin always formats destination drives
   with a real GPT partition table, not a filesystem directly on the raw disk, it's
   recognized by FPP's own device picker on any FPP system, not just one this plugin was
   already installed on - see [Restoring a Backup](restoring-a-backup.md) for the same
   property in the content-restore case.
3. **Find and extract the archive.** `host-identity-backup.tar.gz` sits at the root of
   that drive (same level as every remote's own `<Hostname>-<date>` folder, not inside
   any of them). Over SSH on the new Host:
   ```
   cd /path/to/mounted/drive
   tar xzf host-identity-backup.tar.gz
   ```
   This extracts a single `settings.json`.
4. **Put it in place.**
   ```
   sudo cp settings.json /home/fpp/media/plugins/fpp-plugin-RemoteBackup/data/settings.json
   sudo chown fpp:fpp /home/fpp/media/plugins/fpp-plugin-RemoteBackup/data/settings.json
   sudo chmod 0666 /home/fpp/media/plugins/fpp-plugin-RemoteBackup/data/settings.json
   ```
   Reload the Config page - every remote and every option should reappear exactly as
   configured on the old Host. If a default SSH password was configured, re-enter it in
   Config's SSH settings now - it was deliberately not included in the archive (see
   above).
5. **Re-select the destination storage** under Config's Backup Destination Storage
   section (the same physical drive you just attached) and click **Save Settings**.
6. **Confirm before trusting the schedule again.** Run a **Dry Run** on the Status page.
   The new Host generated a brand-new SSH keypair on install, which none of the remotes
   trust yet - the old Host's key is gone with it, on purpose - but a failed connection
   for exactly that reason now auto-pushes the new key (using the default password from
   step 4) and retries once on its own, for every remote, with no per-remote click
   needed in the common case. Once the Dry Run looks right, a manual **Start Backup**
   and a check of the Status page's Backup Status table confirms real backups are
   flowing before you rely on the Scheduler to pick it back up automatically.
7. **Only if a remote still shows "key push failed" after that** - its actual password
   differs from whatever default you entered in step 4 - click **Push SSH Key** next to
   it on the Config page by hand, with that remote's real password.

## Limitations worth knowing

- **Only as fresh as the last real run.** If settings changed and no real (non-dry-run)
  backup has completed since, the archive on the drive still reflects the *previous*
  configuration, not the change. A Dry Run alone does not refresh it.
- **Doesn't restore the Host's identity, by design** - see above. Every remote needs its
  key re-pushed regardless of how current the archive is - usually automatic now (step
  6), per remote by hand only as the fallback (step 7).
- **The automatic re-push only works if the password you entered in step 4 is actually
  correct for a given remote.** A remote using a genuinely different password than the
  configured default still needs the manual fallback in step 7 - this procedure removes
  "remember and re-enter every remote and every option from scratch," and removes most
  of "re-authenticate to each one" too, but not the case where the default simply
  doesn't apply to a particular remote.
