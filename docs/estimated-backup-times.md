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

## Real-World System Load Examples

Everything above is a reasoned estimate. This section is the opposite - actual CPU,
memory, and network measurements captured from both ends of a real transfer at once (the
Host and the remote it's pulling from), lined up against the exact backup window from
`data/logs/engine.log`. More get added here as they're captured on different device
combinations.

### How to capture your own

On each device you want to compare, a simple loop samples `top` and the network counters
every few seconds and appends them to a log file:

```bash
while true; do
    date '+%Y-%m-%d %H:%M:%S'
    top -bn1 | head -6
    echo "--- NETWORK ---"
    grep -E 'eth0|wlan0' /proc/net/dev
    sleep 5
done >> /home/fpp/media/logs/system_monitor.log
```

Start it on the Host and on whichever remote you want to compare, let it run through a
real backup, then stop it and pull both log files off (over SSH, or FPP's own File
Manager). Line the timestamps up against `engine.log`'s own `starting rsync for <remote>`
/ `finished rsync` lines to know exactly which samples fall inside the real transfer
window - everything outside that window is just idle baseline, useful for contrast but not
the number that matters.

**Exclude the monitor's own log from the backup itself.** Left under
`/home/fpp/media/logs/`, this loop's log file is still being appended to *while* the
backup that's supposed to capture it is running - the same "still changing during this
exact run" class of issue this plugin's own operational files hit internally (see the
[changelog](changelog.md)), just on your own script this time. Add
`logs/system_monitor.log` to Config's Exclude patterns (Remote Systems section) on each
device you monitor this way, so it stops showing up as a false "still differs" on
Status/VERIFY.

### 2026-08-31: Pi5Backup (Host) vs. FPPBeagleBlack (remote)

Both boards 32-bit. Backup window from `engine.log`: FPPBeagleBlack's own transfer ran
06:53:31-06:53:36; the Host's own local backup (no network involved) ran right after,
06:53:36-06:53:39.

**FPPBeagleBlack (remote, 483 MB RAM)**

| Time | Load avg | CPU busy | Mem used | Network |
|---|---|---|---|---|
| 06:53:13 | 0.04 | 31% | 167.6 MB | ~7 KB/s (idle) |
| 06:53:18 | 0.04 | 100% | 167.5 MB | ~11 KB/s |
| 06:53:23 | 0.04 | 29% | 167.5 MB | ~6 KB/s |
| 06:53:29 | 0.03 | 29% | 167.5 MB | ~6 KB/s |
| **06:53:34** | 0.03 | **100%** | 167.4 MB | **~550 KB/s** ← sending its own transfer |
| 06:53:40 | 0.03 | 29% | 167.5 MB | ~8 KB/s (already done) |
| 06:53:45 | 0.02 | 89% | 167.5 MB | ~14 KB/s |

**Pi5Backup (Host, 920 MB RAM)**

| Time | Load avg | CPU busy | Mem used | Network |
|---|---|---|---|---|
| 06:53:19 | 0.20 | 6% | 254.2 MB | ~10 KB/s (idle) |
| 06:53:25 | 0.18 | 4% | 255.3 MB | ~27 KB/s |
| **06:53:30** | 0.17 | **44%** | 262.9 MB | ~19 KB/s (run just starting) |
| **06:53:35** | 0.23 | **68%** | 262.5 MB | **~576 KB/s** ← receiving FPPBeagleBlack |
| 06:53:41 | 0.37 | 11% | 258.2 MB | ~41 KB/s (both transfers done) |
| 06:53:46 | 0.34 | 35% | 254.1 MB | ~105 KB/s (other remotes still running) |
| 06:53:51 | 0.40 | 6% | 252.6 MB | ~12 KB/s (run complete) |

**What this shows**: the CPU and network spikes line up exactly across both devices -
FPPBeagleBlack pegs at 100% CPU and pushes ~550 KB/s out over `eth0` in the same ~5-second
window the Host's CPU climbs to 68% and pulls in ~576 KB/s - that's the actual rsync
transfer, visible simultaneously from both ends. Memory was a non-issue on either board:
FPPBeagleBlack sat essentially flat at 167.5 MB the entire time, and the Host only moved
from ~253 MB to ~263 MB during the run before dropping right back down - no growth, no
pressure, well within either 32-bit board's limited RAM. Load average lags CPU% by design
(a smoothed trailing average), so it rises a beat after the spike rather than during it.

## Bottom line

Pi3 anywhere in the chain flattens everything else to Fast-Ethernet speed. Beyond that,
destination storage (USB stick vs SSD/NVMe) matters more than Pi4-vs-Pi5 CPU
differences. Run a [dry run](how-it-works.md) first if you want a size estimate before
committing to a real backup window.
