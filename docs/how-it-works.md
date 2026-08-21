# How Remote Backup Works

[← Back to README](../README.md)

1. **Pick exactly one Host.** On the single FPP system that will store backups, open
   *Remote Backup - Config*, enable **Host Mode**, and choose a destination storage
   device. NVMe/SSD is preferred; a USB flash drive or free space on the SD card can be
   used if no NVMe/SSD is present.
2. **Select remotes.** The Config page scans FPP's MultiSync system list for candidate
   remotes. Check the ones you want backed up, or add one manually by hostname/IP if it
   isn't discovered automatically.
3. **Click "Save Settings" at the bottom of the Config page.** Nothing above is applied
   until you do - Host Mode, the destination device, selected remotes, and every option
   on this page only take effect once saved.
4. **Authenticate.** Each remote needs to accept SSH connections from the Host for the
   `fpp` user. Use the "Push SSH Key" button next to a remote to install the Host's
   dedicated backup key (generated automatically on plugin install), or copy
   `~fpp/.ssh/id_rsa_remotebackup.pub` to the remote's `~fpp/.ssh/authorized_keys`
   yourself.
5. **Dry run first.** Use *Remote Backup - Status* → "Dry Run" to see the estimated
   transfer size for all selected remotes compared against free space on the Host's
   destination storage, with no files copied.
6. **Start Backup.** Runs `rsync` pulls of each remote's `/home/fpp/media` from the
   Host, up to 2 remotes at a time by default; as each finishes, the next queued remote
   starts automatically.
