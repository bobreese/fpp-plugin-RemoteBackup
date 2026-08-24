# Requirements, Install, and Uninstall

[← Back to README](../README.md)

## Requirements

- `rsync`, `jq`, an OpenSSH client, `curl`, and `zip` (for the Status page's "Download All
  Logs" button) on the Host (installed automatically if missing by fpp_install.sh via a
  plain `apt-get install`). These are deliberately NOT
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

`fpp_install.sh` sets FPP's `restartFlag` at the end of a fresh install, so FPP restarts
itself (sequenced safely around anything already running, same as any other flagged
change) and the "Run Remote Backup" / "Run Remote Backup Dry Run" commands become
selectable in the Scheduler/Playlist/Event pickers right away. This plugin has no
callbacks script (nothing hooks show start/stop), and FPP's install/uninstall
live-reload only re-registers commands for a plugin that has one - without the flag,
those commands wouldn't appear until something else happened to restart FPP.

**Be aware this is considered a Beta Test version. Use with care.**

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
`engine.log`, `clone.log`, and per-remote rsync logs - see [Log Files](log-files.md)),
`data/settings.json`, and `data/status/`. None of that
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
