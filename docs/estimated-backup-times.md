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
done >> /tmp/system_monitor.log
```

Start it on the Host and on whichever remote you want to compare, let it run through a
real backup, then stop it and pull both log files off (over SSH or `scp`, since `/tmp`
isn't reachable through FPP's own File Manager). Line the timestamps up against
`engine.log`'s own `starting rsync for <remote>` / `finished rsync` lines to know exactly
which samples fall inside the real transfer window - everything outside that window is
just idle baseline, useful for contrast but not the number that matters.

**Keep the monitor's own log out of `/home/fpp/media` entirely.** Every device backs up
from `/home/fpp/media` (see [Requirements](requirements-install-uninstall.md#requirements)),
so a log file written under there - `logs/system_monitor.log`, say - is live source content:
still being appended to *while* the very backup that's supposed to capture it is running,
the same "still changing during this exact run" class of issue this plugin's own
operational files hit internally (see the [changelog](changelog.md)), just on your own
script this time. `/tmp` above sidesteps the problem outright, on the Host and on every
remote, with nothing to configure - it's simply never part of what gets backed up, so it
can never show up as a false "still differs" on Status/VERIFY. If you'd rather keep the log
under `/home/fpp/media` anyway (e.g. so FPP's own File Manager can reach it), add its path
to Config's Exclude patterns instead - that setting is shared across the Host and every
remote's backup in one place, so it only needs adding once, not per device.

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

### 2026-08-31: Pi5Backup (Host) vs. PiMaster (remote, 64-bit)

PiMaster is 64-bit (unlike the 32-bit FPPBeagleBlack in the example above). Backup window
from `engine.log`: PiMaster's own transfer ran 09:08:17-09:08:32, sending 136 MB across 188
files - by far the largest single transfer in this run (the two remotes running concurrently
with it under the default 2-concurrent-remote queue, Santa-Sleigh and SantaFPP, moved 0 bytes
and 237 KB respectively), so the Host's network/CPU load below is effectively all PiMaster.

As a bonus, this run's own `engine.log` shows exactly the false-positive the "How to capture
your own" section above warns about: `VERIFY PiMaster: 1 file(s) still differ from source
after backup: logs/system_monitor.log` - the monitoring loop's own log file, caught mid-write.
Confirms the guidance is worth following, not just theoretical.

**PiMaster (remote, 64-bit, 1844.8 MB RAM)**

| Time | Load avg | CPU busy | Mem used | Network (out) |
|---|---|---|---|---|
| 09:08:13 | 0.08 | 6% | 354.3 MB | ~3 KB/s (idle) |
| 09:08:18 | 0.07 | 7% | 373.0 MB | ~1.66 MB/s (rsync started 09:08:17) |
| **09:08:23** | 0.07 | **11%** | 374.0 MB | **~12.4 MB/s** ← peak send |
| **09:08:29** | 0.06 | **11%** | 378.6 MB | **~9.7 MB/s** ← still sending |
| 09:08:34 | 0.06 | 6% | 369.8 MB | ~778 KB/s (finished 09:08:32) |
| 09:08:39 | 0.05 | 6% | 362.6 MB | ~3 KB/s (idle again) |

**Pi5Backup (Host, 920 MB RAM)**

| Time | Load avg | CPU busy | Mem used | Mem free | Network (in) |
|---|---|---|---|---|---|
| 09:08:12 | 0.82 | 39% | 237.6 MB | 116.0 MB | ~23 KB/s (idle) |
| 09:08:17 | 0.84 | 50% | 247.6 MB | 104.7 MB | ~51 KB/s (PiMaster starting) |
| **09:08:22** | 0.93 | **80%** | 248.4 MB | **34.5 MB** | **~12.3 MB/s** ← peak receive |
| **09:08:28** | 1.10 | **94%** | 242.1 MB | **38.4 MB** | **~10.0 MB/s** ← still receiving |
| 09:08:33 | 1.17 | 56% | 231.8 MB | 43.1 MB | ~2.5 MB/s (finished 09:08:32) |
| 09:08:39 | 1.24 | 8% | 230.4 MB | 44.5 MB | ~0.6 KB/s (idle) |

**What this shows**: PiMaster's 64-bit CPU barely noticed sending 136 MB at up to ~12.4 MB/s -
CPU busy peaked at just 11%, and memory moved a modest ~370 MB → 379 MB and back. The Host
worked considerably harder receiving the same transfer while writing it to the destination
drive: CPU busy climbed to 94%, and real `wa` (iowait, up to 22%) and `si` (softirq, up to
17%) time showed up in `top` - both consistent with a drive-write-bound receiver under network
load, not something the destination-storage-type table above spelled out on its own. Free
memory on the Host did crater to ~34-38 MB during the peak, but *available* memory (the column
that accounts for reclaimable buffer/cache, i.e., what the kernel can actually hand back to a
process that needs it) stayed flat around 670-690 MB the entire time - the free-memory dip is
the Linux page cache doing its job (buffering the incoming write), not the Host running low on
usable RAM. Load average again lags CPU%, peaking a sample or two after the run's actual busiest
moment.

### 2026-08-31: PiMaster (Host) vs. SantaFPP (remote, 64-bit)

Roles flipped from the example above: PiMaster is now the Host, and both it and SantaFPP are
64-bit, on what looks like real Gigabit Ethernet rather than a Fast-Ethernet-limited link. Backup
window from `engine.log`: SantaFPP's own transfer ran 10:19:15-10:19:39, sending 940 MB across
30 files - the fastest transfer captured across these examples so far.

**SantaFPP (remote, 64-bit, 1844.8 MB RAM)**

| Time | Load avg | CPU busy | Mem used | Network (out) |
|---|---|---|---|---|
| 10:19:08 | 0.55 | 2% | 345.2 MB | ~3 KB/s (idle) |
| 10:19:14 | 0.50 | 4% | 345.4 MB | ~5 KB/s (just before start) |
| **10:19:19** | 0.54 | **51%** | 413.0 MB | **~76.4 MB/s** ← peak send |
| 10:19:24 | 0.50 | 36% | 419.6 MB | ~27.0 MB/s |
| **10:19:29** | 0.46 | 2% | 391.7 MB | **~57.5 MB/s** ← still sending, CPU already dropped |
| 10:19:35 | 0.42 | 2% | 383.4 MB | ~22.4 MB/s (tailing off) |
| 10:19:40 | 0.39 | 2% | 370.1 MB | ~25 KB/s (finished 10:19:39) |
| 10:19:45 | 0.36 | 4% | 356.0 MB | ~3 KB/s (idle again) |

**PiMaster (Host, 1844.8 MB RAM)**

| Time | Load avg | CPU busy | Mem used | Mem free | Network (in) |
|---|---|---|---|---|---|
| 10:19:11 | 0.06 | 14% | 362.1 MB | 885.5 MB | ~7 KB/s (idle) |
| 10:19:16 | 0.13 | 36% | 388.1 MB | 831.8 MB | ~7.0 MB/s (ramping up) |
| **10:19:21** | 0.52 | **51%** | 442.7 MB | 490.2 MB | **~70.3 MB/s** ← peak receive |
| 10:19:26 | 0.80 | 50% | 476.4 MB | 184.5 MB | ~60.0 MB/s |
| **10:19:31** | 1.30 | 54% | 446.8 MB | **100.3 MB** | ~23.6 MB/s |
| 10:19:37 | 1.60 | 4% | 401.7 MB | 65.2 MB | ~22.4 MB/s (near the end) |
| 10:19:42 | 1.47 | 2% | 403.9 MB | 63.0 MB | ~23 KB/s (idle again) |

**What this shows**: on a healthy Gigabit pair, throughput dwarfs anything in the earlier
examples - peaking around 70 MB/s received on the Host and 76 MB/s sent from SantaFPP in the
same 5-second window. Neither side got close to pegged: CPU busy topped out around 50-54% on
each end rather than the 80-94% seen on the Host in the 32-bit/920 MB-RAM example above. Notably,
SantaFPP's CPU dropped back to near-idle (2%) *while still pushing 57 MB/s* a few seconds later
- the brief 51% spike at the very start looks like rsync's own file-list/checksum setup work,
not the data-copy itself, which apparently isn't CPU-bound on this board once the transfer gets
going. PiMaster's free memory dropped from ~885 MB to ~63 MB over the run - the same page-cache
pattern as every earlier example, just with more headroom to fall through given its 1844.8 MB
total. Both VERIFY passes came back clean this run.

## Bottom line

Pi3 anywhere in the chain flattens everything else to Fast-Ethernet speed. Beyond that,
destination storage (USB stick vs SSD/NVMe) matters more than Pi4-vs-Pi5 CPU
differences. Run a [dry run](how-it-works.md) first if you want a size estimate before
committing to a real backup window.
