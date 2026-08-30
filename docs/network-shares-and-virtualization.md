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
