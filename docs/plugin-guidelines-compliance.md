# Pre-submission Checklist / Plugin Guidelines Compliance

[← Back to README](../README.md)

This is a self-audit against FPP's own plugin submission guidance -
[`PLUGIN_GUIDELINES.md`](https://github.com/FalconChristmas/fpp-plugin-Template/blob/master/PLUGIN_GUIDELINES.md)
and
[`PLUGININFO_FORMAT.md`](https://github.com/FalconChristmas/fpp-plugin-Template/blob/master/PLUGININFO_FORMAT.md)
from FPP's own template plugin repository - with the reasoning behind each result, not
just a pass/fail list. Re-run periodically against the live guidelines text (which the
guidelines themselves say can change without notice), not just re-stated from memory -
this pass fetched both documents fresh and re-checked every claim below against the
current codebase rather than trusting the previous write-up.

## Passing

- **`pluginInfo.json` is valid JSON and has every mandatory field** (`repoName`, `name`,
  `author`, `description`, `homeURL`, `srcURL`, `bugURL`, `versions`). `iconURL` resolves
  to a real committed `icon.png` at the repo root, confirmed 256x256.
- **No hardcoded colors** as of this pass - two rounds of findings so far (one hex color in
  an early pass, three more reintroduced by new code - the Config page walkthrough - in
  this one; see Findings below for both) - the Config/Status pages otherwise use FPP's own
  Bootstrap-based dialog, toast, and CSS color idioms (or, for the walkthrough's own accent,
  the `--bs-primary` CSS variable) instead of bare hex/RGB values, so they adapt correctly
  to FPP's dark theme. New UI added between audits is exactly where this tends to slip back
  in, so worth re-checking specifically on every future pass, not just assuming it still
  holds from last time.
- **No cron jobs, systemd units, or symlinks** are created outside FPP's own plugin
  lifecycle hooks and `commands/descriptions.json` mechanism - confirmed by inspection of
  `fpp_install.sh`/`fpp_uninstall.sh` (no `systemctl`, `crontab`, or `ln -s` anywhere in
  either).
- **Install/uninstall are idempotent.** `fpp_install.sh`'s SSH keypair generation is
  guarded (`if [ ! -f "$KEYFILE" ]`), so re-running it (via "Reinstall All Plugins" or an
  update) never regenerates or duplicates it; nothing else it does is order- or
  count-sensitive. `fpp_uninstall.sh` is safe to reason about as a single clean pass. See
  [Requirements, Install, and Uninstall](requirements-install-uninstall.md).
- **`menu.inc` registers exactly one entry per `type`** (`status`, `content`, `help`) -
  re-verified directly against the current file (this plugin previously had two `help`-type
  entries, a real finding from an earlier pass; see the Changelog for that fix).
- **No donation, payment, or subscription references anywhere** - UI, README, help pages,
  and `pluginInfo.json` all checked, no matches for PayPal/Ko-fi/Venmo/Patreon/GitHub
  Sponsors/etc.
- **No bundled analytics/telemetry SDK or usage phone-home** - no matches for any
  telemetry vendor name or a home-rolled reporting endpoint anywhere in the plugin.
- **No advertising** - the plugin's pages don't promote any other product, vendor, or
  plugin (including its own, beyond normal self-description).
- **No third-party tunneling/remote-access service** (Tailscale, ngrok, Cloudflare
  Tunnel, etc.) is set up or depended on, so the disclosure requirement for one doesn't
  apply.
- **No piped remote execution** (`curl … | bash` or similar) anywhere in any script.
- **Never reboots, shuts down, or directly restarts `fppd`** - no `reboot`, `shutdown -r`,
  `systemctl restart fppd`, or `fpp -r` calls anywhere; every "reboot" mention in the
  codebase is descriptive text about the *host* possibly rebooting (e.g. an `/etc/fstab`
  entry surviving one), never the plugin causing one.
- **Talks to FPP through its documented HTTP API, not its internals.** Confirmed no use
  of fppd's raw internal port (`:32322`) and no hand-reading/writing of FPP's own core
  config files (`channeloutputs.json`, `model-overlays.json`, the settings file, etc.) -
  remote/system state is read via `/api/fppd/multiSyncSystems`, `/api/system/status`,
  `/api/schedule`, and FPP's own `copy_settings_to_storage.sh`/backup-device API for the
  cases that touch another FPP system at all.
- **`dependencies.packages` is deliberately left empty** (`[]`) in `pluginInfo.json`, and
  the current guidelines explicitly confirm this is fine either way ("optional this year
  ... installing everything yourself from `fpp_install.sh` remains completely fine").
  This plugin's specific reason for opting out even though it's now optional rather than
  declaring `rsync`/`openssh-client`/`jq`: FPP ref-counts packages listed there per-plugin
  and `apt-remove`s one once no plugin/user still claims it. For foundational tools like
  these - which plenty of other things on a real system can depend on outside this
  plugin's own knowledge - that ref-counting previously cascaded, via real `apt`
  dependency resolution, into removing `raspi-firmware` and the entire
  `openssh-server`/`ssh` stack on a real Pi5 when this plugin was uninstalled.
  `fpp_install.sh` installs them directly via a plain `apt-get install` instead (see
  [Requirements](requirements-install-uninstall.md)), and `fpp_uninstall.sh` never
  removes them.
- **No native/C++ build concerns apply.** This is a script plugin (PHP + shell) - the
  guidelines' native-plugin sections on `FPP_PLUGIN_SUPPORTS_UNLOAD()`, `registerPluginApi()`
  vs. raw Drogon handlers, `setup.mk`, and `apiDocs.json` don't apply to it at all.
- **Resource hints not declared, and that's appropriate.** `minMemoryMB`/`minCpuCores`
  are optional, self-reported fields for a plugin that's genuinely memory- or CPU-hungry
  enough to disrupt a show on a low-end board. This plugin's work is I/O-bound (`rsync`
  transfers, mount/format operations), not something that meaningfully stresses RAM or
  CPU - nothing here has ever needed declaring.

## Findings

### Fixed: three hardcoded colors introduced by the Config page walkthrough

The first-run Config page walkthrough (spotlight/arrow tour added after the previous
pass) hardcoded `#0d6efd` for its highlight border and arrow accent color - the exact
anti-pattern this same doc already caught and fixed once before elsewhere (see "one
hardcoded color" below), reintroduced by new code rather than missed in old code. Fixed:
all three now read `var(--bs-primary, #0d6efd)` - Bootstrap's own theme variable, with the
literal value kept only as a fallback for the (unlikely) case that variable isn't defined
at all - so the accent follows FPP's own theme instead of staying fixed if a dark theme
ever overrides its primary color. Left as-is, deliberately: the spotlight's dimming scrim
(`rgba(0, 0, 0, 0.55)`) - a translucent black backdrop is the same convention in both
themes (it's what Bootstrap's own modal backdrop does too), not a color that needs to
adapt the way a border/text color does. Re-swept every `style="..."`/`<style>` block added
since the previous pass; found no other hex/named/`rgb()` colors.

### Fixed: `pluginInfo.json`'s description overstated a fixed transfer limit

Said *"Supports up to 2 concurrent transfers"* - true when first written, but Config's
"Max concurrent transfers" field has been user-configurable (1-8, default 2) since before
that description was last touched. Left uncorrected, it undersells the actual feature and
reads like a hard cap that isn't there. Reworded to *"Configurable concurrent transfers
(up to 8)"*.

### Fixed: SSH password was being logged in cleartext

`ajax.php`'s `rb_run()` helper (used by every action that shells out to a script) always
logged the full command line it ran, including every argument. The `pushSshKey` action
passes the SSH password straight through to `ssh_setup.sh` as an argument, so every
"Push SSH Key" click wrote that password in cleartext into `data/logs/ajax.log` via the
`RUN cmd=...` log line - directly against the guidelines' "don't log secrets (tokens,
passwords, PATs)" requirement, and a real credential-exposure issue independent of the
guidelines check (that log is also downloadable in full via "Download All Logs").

`rb_run()` now accepts an optional `$redact` list of raw argument values; anything in it
is replaced with `***REDACTED***` in the logged command line only - the real command
actually executed is never touched. The `pushSshKey` call site now passes the resolved
password through `$redact`. Verified with an isolated test: the real command string still
carries the actual password (execution unaffected), while the logged string has it
replaced. Confirmed no other `rb_run()` call site in the plugin passes anything else
sensitive (format/mount device paths and confirmation tokens aren't secrets), and that
`ssh_setup.sh` itself never echoes the password to its own stdout/stderr (which `rb_run()`
also captures and logs) - the command-line logging was the only leak vector.

**If you've used "Push SSH Key" before this fix**, your `data/logs/ajax.log` (and any
"Download All Logs" archive taken before now) contains that password in plain text -
worth treating as exposed and rotating if it's not already FPP's factory default.

### Fixed: redundant `sudo` in `fpp_uninstall.sh`

The current guidelines are explicit: *"Do not use `sudo`. Install/uninstall/hook scripts
already run as root ... `sudo apt …`, `sudo chmod …`, etc. are redundant and hide
assumptions — call the commands directly."* `fpp_uninstall.sh` had exactly this pattern -
`sudo sed -i ... /etc/fstab` - even though the script already runs as root as part of
FPP's own uninstall lifecycle. Removed; behavior is identical either way since the
script's execution context hasn't changed, only the redundant indirection.

### Fixed: optional bind mount wasn't torn down on uninstall

The "see current backups without unmounting" toggle bind-mounts `/mnt/Backups` onto
`/home/fpp/media/backups` while it's on - real kernel mount-table state living outside
this plugin's own directory. `fpp_uninstall.sh` never undid it, so uninstalling the
plugin while that bind mount was active would leave `/home/fpp/media/backups`
permanently bound to a path that (after uninstall) nothing manages anymore - a real gap
against *"clean up completely on uninstall ... anything you set up outside your plugin
folder must be removed."* Fixed: `fpp_uninstall.sh` now checks whether that exact path is
currently a mountpoint and un-binds it if so, using the same plain `mountpoint`-then-
`umount` check this plugin already uses elsewhere, rather than sourcing the whole
`lib_common.sh` for one function (it has unrelated side effects at source time, like
recreating `data/` directories FPP is about to delete anyway). Verified with a real bind
mount in an isolated test.

### Fixed: newly-registered commands needed a manual restart to appear

This plugin ships two FPP Commands (`commands/descriptions.json`: "Run Remote Backup" /
"Run Remote Backup Dry Run") but has no `callbacks` script - nothing hooks show
start/stop. The current guidelines spell out exactly what that means: *"If your plugin
registers commands but has no callbacks script at all ... your commands are not
live-registered on install; you still need `restartFlag` for that case."* `fpp_install.sh`
never set it, so on a fresh install the commands wouldn't actually appear in the
Scheduler/Playlist/Event pickers until something else happened to restart FPP - with
nothing telling a new user that was necessary. Fixed: `fpp_install.sh` now calls
`setSetting restartFlag 1` at the end of a successful install (guarded behind
`command -v setSetting`, matching the guidelines' own shell snippet), so FPP restarts
itself, sequenced safely around anything already running, and the commands are usable
right away. This doesn't affect uninstall - unloading a plugin's commands was already
separately confirmed (against FPP's own `Plugins.cpp`) to happen immediately with no
restart needed; see [Requirements, Install, and Uninstall](requirements-install-uninstall.md).

### Fixed: one hardcoded color

`status.php`'s Backed Up detail panel used `border-top:1px solid #ddd` - a literal hex
color, exactly the anti-pattern the guidelines call out by name (*"The old
`border: 2px solid #000` fieldset pattern is exactly what breaks in dark mode — use
`class="border"`."*). Missed previously because the earlier hardcoded-color pass focused
on dialogs/toasts/alerts, not every inline `style=`. Replaced with Bootstrap's
theme-aware `border-top` utility class; a full sweep of every `style="..."` in the plugin
found no other hex/named/`rgb()` colors anywhere.

### Open: several fixed-pixel widths, not yet visually verified

The same sweep found a handful of fixed-pixel dimensions in inline styles:
`config.php`'s manual-remote-add fields (`width:180px`/`width:150px`, side by side - the
two most likely to actually cause horizontal overflow on a ~320px phone, since they're an
absolute width pair rather than a cap), plus `min-width`/`max-width`/`max-height` values
in `status.php` (a storage-device select, a couple of truncating table/progress
containers, and the log viewer's scroll box). Not fixed as part of this pass: a `max-width`
or `max-height` cap doesn't force overflow the way a plain fixed `width` or a `min-width`
floor can, so these aren't uniformly equally risky, and getting a responsive-layout change
right needs actually looking at it on a real ~320px viewport in both themes - something
this pass didn't have a way to do safely without risking a change that reads fine in the
diff but breaks visually. Left as a concrete, scoped to-do rather than guessed at blind.

Re-checked against new UI added since: the Config page walkthrough's popup uses `width:
320px` but pairs it with `max-width: calc(100vw - 16px)`, so it shrinks to fit rather than
forcing overflow - same reasoning as the `max-width`/`max-height` values above, not a new
instance of the riskier bare-fixed-width pattern.

### Open: extensive use of `sudo` outside the install/uninstall lifecycle

`scripts/mount_usb.sh`, `unmount_usb.sh`, `format_usb.sh`, and `run_backup.sh` all shell
out to `sudo` for privileged operations - mounting/unmounting/formatting block devices,
editing `/etc/fstab`, and (for a Host-local backup) preserving root-owned files pulled
from `/etc/network`, `/etc/wpa_supplicant`, and `/etc/fpp`. Unlike the `fpp_uninstall.sh`
instance fixed above, this `sudo` is **not** redundant: these scripts are invoked
on-demand via `ajax.php` (through `rb_run()`), which runs as the web server user in
response to a button click - not as root, and not during FPP's install/uninstall/hook
lifecycle, which is specifically what the current guidelines' "do not use sudo" text is
scoped to (*"Install/uninstall/hook scripts already run as root"*). That rationale simply
doesn't apply to a script triggered by an ordinary web request. FPP's own web UI relies on
the same passwordless-`sudo`-for-`fpp` convention elsewhere for the same reason (a PHP
request handler isn't root either) - this plugin follows that existing convention rather
than inventing a different one.

This remains open/unresolved rather than fixed, since narrowing it further (e.g. a
tightly scoped sudoers policy restricting exactly which commands each script may run as
root, rather than blanket passwordless `sudo`) would be a real architectural change, not a
small patch - noted here for visibility rather than acted on yet.

### Open: multiple log files instead of one `plugin-<repoName>.log`

The current guidelines are specific and detailed on this: *"Exactly one runtime log file
... `<logdir>/plugin-<repoName>.log`, where `<logdir>` is FPP's logs directory ... Do not
open a second log, write into your plugin directory ... or create dated/numbered variants
yourself."* FPP rotates that one file for you (last 2 copies, compressed) specifically so
a chatty plugin doesn't need its own retention logic.

This plugin does the opposite by design: `LOG_DIR` (`lib_common.sh`) points at
`data/logs/` **inside** the plugin's own directory, and it deliberately keeps *several*
distinct logs there - `engine.log`, `ajax.log`, one `<hostname>-<run>.log` per remote per
run, `clone-<run>.log` - each with its own retention policy (configurable count per
remote, pruned on its own schedule), surfaced through a dedicated in-plugin Diagnostic Log
viewer (tail-follow, error/warning filtering, single-log download, "Download All Logs" as
one zip). See [Log Files](log-files.md) for the stated reasoning: a single real backup run
can produce a fresh log line per file transferred plus per progress update with no TTY to
overwrite in place, so folding that volume into FPP's own shared log view (and its
2-copies-then-gone rotation) would both flood FPP's own log list with entries that have
nothing to do with FPP itself, and lose the per-remote separation this plugin's own
Status-page log viewer depends on.

That reasoning is real, but it's worth being honest that it's in tension with, not
strictly forced by, the guideline - FPP's aggressive plugin-log rotation exists
specifically to answer "what if a plugin's log gets noisy," which is the same concern
`log-files.md` raises. This is flagged as an open architectural question rather than
fixed here, since properly reconciling the two would mean either a real redesign of this
plugin's logging (collapsing everything into one file some other way while keeping the
diagnostic viewer's usefulness) or a deliberate, documented decision to keep the current
design and accept the divergence - not something to silently resolve as a side effect of
a compliance write-up. One middle-ground option worth naming for whoever makes that call:
keep the existing rich per-remote/per-run logs for the in-plugin viewer as-is, and
additionally emit one thin summary line per run (start, finish, pass/fail, remote count)
into a real `<logdir>/plugin-fpp-plugin-RemoteBackup.log` - satisfying the letter of "one
file FPP manages" for anyone/anything that only looks there, without touching the
existing diagnostic system at all.

### Open: some plugin-managed paths necessarily live outside the plugin directory

The current guidelines' filesystem-boundaries section says to read/write only within the
plugin directory, the single log file, `config/plugin.<repoName>`, and
`<mediadir>/plugindata/`. Several things this plugin does are outside that list by
necessity, not oversight, since the plugin's entire purpose is managing a backup
destination and pulling from remote systems:

- The backup destination itself - `/mnt/Backups`, `/mnt/BackupsCopy` for the clone drive,
  and (for the SD Card/System Storage fallback) `/home/fpp/media/backups` - none of which
  can live inside the plugin's own directory without defeating the point of a dedicated,
  independently-mountable backup drive.
- The `/etc/fstab` entries that persist those mounts across a reboot.
- The dedicated SSH keypair at `~fpp/.ssh/id_rsa_remotebackup` (rather than under
  `plugindata/`) - conventional location for a per-purpose SSH identity, and outside the
  plugin directory specifically so it isn't wiped by an update/reinstall the way something
  under the plugin tree would be.
- The external settings backup at `/home/fpp/media/.fpp-plugin-RemoteBackup-settings.bak`
  - deliberately placed outside the plugin directory entirely, because a real incident
  proved an in-directory backup isn't independent protection: whatever wipes `data/` on
  some systems wipes a backup living in that same directory right along with it. See
  [Troubleshooting](troubleshooting.md#settings-reset-to-defaults) for the full story.

Also worth naming separately: this plugin stores its own `data/settings.json` as a plain
file it manages itself, rather than through FPP's `WriteSettingToFile()`/
`config/plugin.<repoName>` mechanism the guidelines describe as "the FPP way" for a
plugin's own config. That file living inside the plugin's own directory is squarely
within the sanctioned boundary either way, so this isn't a boundary violation - just a
divergence from the *recommended* mechanism, chosen because the dual-location
self-healing backup behavior above (an external copy plus atomic write-then-rename) isn't
something `WriteSettingToFile`'s simpler key/value model was checked to support.

None of the above is being treated as something to "fix" - each is a considered tradeoff
in service of what this plugin actually does, not an oversight - but they're real
divergences from the letter of the filesystem-boundaries guideline worth naming plainly
rather than glossing over in a self-audit.
