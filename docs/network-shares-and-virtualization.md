# Network Shares and Virtual Machines

[← Back to README](../README.md)

Two questions that come up often enough to answer directly, in plain terms, rather than
leaving anyone to guess from a confusing error or an empty storage list: can this plugin
back up to a network drive, and does it work if FPP itself is running in Docker, Hyper-V,
or another virtual machine? Short answer to both: no, and the reasons are worth knowing
before you go looking for a workaround.

## Why can't I use a network share (NAS, SMB/CIFS, NFS) as my backup destination?

This plugin's storage list on the Config page only ever shows drives physically attached
to the Host - NVMe, SSD, USB, or the SD card/system storage it already runs on. A network
share (a folder on a NAS, a Windows share, anything reached over SMB/CIFS or NFS) will
never appear there, because nothing in FPP - or this plugin - actually knows how to reach
out and connect to one.

It's not an oversight so much as the reverse of what FPP already does: FPP can let *other*
computers reach *into* its own files - browsing them over the network via its own Samba/CIFS
or FTP settings - but there's nothing built in that lets FPP go the other direction and pull
in a network share as its own storage. This plugin's backups only ever land on a drive it
can see directly, the same as everything else it manages.

**What to do instead:** pick one of the supported local drive types (NVMe, SSD, or USB is
recommended; SD card/system storage works as a fallback). If the real goal is an off-site or
network-accessible copy, this plugin's own "Clone Backups to a Second Drive" feature can
mirror the primary backup onto a second physical drive you rotate out or keep elsewhere -
see [Setting up a USB backup drive](usb-drive-setup.md).

### Research notes: what it would actually take to add this

Not a roadmap commitment - this is a reference for evaluating the idea, kept here so the
findings aren't lost between looks at it.

The backup engine itself turns out not to be the obstacle: `run_backup.sh` never touches
disk types directly, it resolves the configured destination down to a plain filesystem path
and hands that to `rsync`, which writes into a network-mounted directory exactly the same
way it writes into a local one. The pre-flight free-space check is filesystem-agnostic the
same way. None of the actual backup logic would need to change.

Everything *around* that engine, though, currently assumes a local block device, all the way
down:

- **Discovery** (`probe_storage.sh`) is built entirely on `lsblk` - transport type, partition
  table, parent disk. A network share has none of that and can't be auto-discovered the way
  a USB drive is; it would need a manual "Add Network Share" form instead (server address,
  share/export path, protocol) - closer to how a remote is added manually today than how a
  drive is picked.
- **Mount/unmount/format** (`mount_usb.sh`, `unmount_usb.sh`, `format_usb.sh`) are keyed on
  `/dev/sdX` block-device paths throughout - `lsblk`-read UUID and filesystem type, fstab
  entries written by UUID. None of that has a CIFS/NFS equivalent; new scripts using
  `mount -t cifs`/`mount -t nfs` and differently-shaped fstab entries (`_netdev`, a
  credentials file for CIFS) would be needed. "Format" has no meaning here at all - the
  remote server owns its own filesystem - so that flow just wouldn't apply.
- **Credentials.** CIFS needs a username/password. The safer approach is a dedicated `600`,
  root-owned credentials file referenced from fstab, not inline plaintext - a materially
  different bar than how the existing SSH password field is stored today.
- **The storage picker UI** (`config.php`) is structured entirely around the four
  lsblk-derived groups (NVMe/SSD/USB/SD card); a network share doesn't fit that model and
  would need its own section.
- **Operational risks worth weighing, not just missing code:** a hung/unreachable network
  share can make a mount check *block* rather than fail fast, unlike a missing local drive -
  the existing missing-destination polling assumes a fast local check, so mount options
  forcing quick timeouts would matter from day one. Boot-time mounting is flakier (the
  network has to be up first). A mid-run network hiccup would affect every remote at once,
  since they'd all share the one destination, unlike today where one remote's own SSH
  hiccup only ever fails that one remote. And SMB3 can encrypt in transit where NFSv3
  typically doesn't - worth a decision given this carries show config and sequences.

Net: sizeable, but not blocked on anything fundamental - realistically comparable in scope
to the original USB-destination support (new discovery/add UI, new mount/unmount scripts,
credential handling done properly, hardened destination-missing detection), plus a rewrite
of this page once/if it lands.

## Why doesn't this work in Docker, Hyper-V, or another virtual machine?

This isn't a limitation specific to this plugin - it's inherited directly from FPP itself.
FPP's own project states plainly that Raspberry Pi and BeagleBone hardware are the only
platforms it supports; Docker, virtual machines (Hyper-V, VMware, or otherwise), and
general PC installs are explicitly called out as *not* supported.

This plugin leans on FPP's own drive detection, mounting, and permissions working the
normal way underneath it - the way they do on a real Pi or BeagleBone. Running FPP inside
Docker or a VM means all of that is happening in an environment FPP itself was never built
or tested for, well before this plugin enters the picture. Whatever does or doesn't work in
that situation isn't something this plugin can promise or fix - it's already outside FPP's
own supported ground.

**What to do instead:** run the Backup Host on real, supported hardware - a Raspberry Pi or
BeagleBone, per FPP's own requirements. If your remotes are running on supported hardware
already, only the Backup Host itself needs to be.

---

For everything else this plugin does support, see the
[Requirements](requirements-install-uninstall.md#requirements) and
[Troubleshooting](troubleshooting.md) pages.
