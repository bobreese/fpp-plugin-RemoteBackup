# Estimated Backup Times

[← Back to README](../README.md)

This plugin pulls backups via `rsync` over SSH, so the biggest factors in how long a
backup takes are network link speed and destination write speed - not the Host's or a
remote's raw CPU power. All of Pi3/Pi4/Pi5 have hardware-accelerated AES (ARMv8 crypto
extensions), so SSH/rsync's encryption overhead is not usually the bottleneck.

These figures are a reasoned estimate based on each Pi generation's known hardware
limits, not measured benchmarks from this plugin specifically - actual throughput
depends heavily on your network setup, the mix of file sizes being backed up (many
small sequence files is slower than a few large media files), and how many remotes are
transferring at once.

## By Pi generation

| | Pi3 | Pi4 | Pi5 |
|---|---|---|---|
| Ethernet | 100 Mbps (Fast Ethernet) | Gigabit (real, not USB-bottlenecked) | Gigabit |
| USB | USB2 only (~30-40 MB/s shared bus) | USB3 (~300-400 MB/s) | USB3 (~300-400 MB/s) |
| Relative CPU | slowest | mid | fastest (~2x Pi4, plus native PCIe for NVMe) |

**Pi3 anywhere in the chain (Host or remote) is the single biggest factor.** Its 100 Mbps
Fast Ethernet caps the whole transfer at roughly 10-12 MB/s, regardless of what the
destination drive is capable of - a fast SSD/NVMe destination won't help if either end
of the connection is a Pi3.

Pi4/Pi5 over Gigabit Ethernet can realistically push rsync+SSH somewhere in the
40-100 MB/s range depending on file mix; at that point destination write speed usually
becomes the limiting factor instead of the network.

## By destination storage type

- **USB flash drive** (exFAT or ext4, whether it's a USB2-class stick or plugged into a
  USB3 port) - typically well under 40 MB/s sustained, especially with lots of small
  files. Fine for Pi3-speed transfers, but can become the bottleneck ahead of Gigabit
  Ethernet on a Pi4/Pi5.
- **SSD/NVMe** - on a Pi5 in particular, can comfortably exceed Gigabit Ethernet's
  ceiling, meaning the network becomes the bottleneck instead of the drive.
- **SD Card/System Storage fallback** - shares the Host's own boot/system SD card, so its
  write speed is whatever that card is capable of; generally slower and more
  wear-sensitive than a dedicated USB/SSD destination, which is part of why it's a
  fallback rather than the preferred option (see [Setting up a USB backup
  drive](usb-drive-setup.md)).

## Concurrency

The default 2-concurrent-remote queue (see [Features & Safe Guards](features.md)) means two transfers
share whatever the actual bottleneck is - the Host's network link, or the destination
drive's write bus - so backing up several remotes at once won't be twice as fast if
either of those is already saturated by a single transfer.

## Bottom line

Pi3 anywhere in the chain flattens everything else to Fast-Ethernet speed. Beyond that,
destination storage (USB stick vs SSD/NVMe) matters more than Pi4-vs-Pi5 CPU
differences. Run a [dry run](how-it-works.md) first if you want a size estimate before
committing to a real backup window.
