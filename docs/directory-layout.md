# Directory Layout

[← Back to README](../README.md)

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
    check_master_schedule.sh fetches the show master's schedule for the Schedule Conflict Check panel
    host_info.sh             reports this Host's own hostname/IPs for the "Host" badge
    run_backup.sh            the rsync pull engine (concurrency, delete, snapshots)
    prune_logs.sh            applies logRetentionCount to every remote's logs immediately on save
    clone_backups.sh         mirrors the primary destination onto a second drive (manual only)
    zip_logs.sh               zips data/logs/ for the Status page's "Download All Logs" button
    ssh_setup.sh              pushes the backup SSH key to a remote
    format_usb.sh             formats + mounts a USB device at /mnt/Backups or /mnt/BackupsCopy
    mount_usb.sh              mounts an already-formatted device and persists it in /etc/fstab
    unmount_usb.sh            unmounts a destination drive and drops its /etc/fstab entry
    bindmount_backups.sh      bind-mounts the primary drive onto FPP's own backups path (opt-in restore visibility)
    list_backups.sh           enumerates existing backups for the Status page's "Backed Up" dropdown
    get_backup_info.sh        size/file-count/contents for one backup selected from that dropdown
    delete_backup.sh          deletes one specific backup folder, with its own independent safety checks
    purge_sdcard_backups.sh   removes leftover SD Card/System Storage backups after switching away from it
  commands/
    descriptions.json, run_remote_backup.sh, run_remote_backup_dryrun.sh
  data/                      created on install
    settings.json            Config page's saved settings
    status/<id>.json         each remote's live status, polled by the Status page
    label_cache.json          volume labels, cached ~30s to avoid re-shelling out to
                               findmnt on every status/cloneStatus poll
    run_active.json, clone_active.json, run.lock, clone.lock, pids/
                               run/clone overlap guards
    known_hosts.lock          serializes concurrent remotes' known_hosts edits
                               (see rb_clear_stale_host_key() in lib_common.sh)
    logs/
      engine.log             run_backup.sh's own log (start/finish, refusals, errors)
      ajax.log                every backend script ajax.php invokes, plus its stderr
      <id>-<runId>.log        one full rsync run log per remote per run (kept per
                               Config > Backup Options' "Run logs to keep per remote")
      clone-<runId>.log       one per Clone Backups to a Second Drive run
```
