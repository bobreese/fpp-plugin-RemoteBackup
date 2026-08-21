# Pre-submission Checklist / Plugin Guidelines Compliance

[← Back to README](../README.md)

This is a self-audit against FPP's own plugin submission guidance -
[`PLUGIN_GUIDELINES.md`](https://github.com/FalconChristmas/fpp-plugin-Template/blob/master/PLUGIN_GUIDELINES.md)
and
[`PLUGININFO_FORMAT.md`](https://github.com/FalconChristmas/fpp-plugin-Template/blob/master/PLUGININFO_FORMAT.md)
from FPP's own template plugin repository - with the reasoning behind each result, not
just a pass/fail list.

## Passing

- **`pluginInfo.json` is valid JSON and has every mandatory field** (`name`, `author`,
  `description`, `homeURL`, `srcURL`, `bugURL`, `iconURL`, `documentation`, `versions`,
  `dependencies`). `iconURL` resolves to a real committed `icon.png` (256x256) - this was
  a genuine finding earlier (a wrongly-cased `Icon.png` had briefly existed and never
  matched the lowercase `iconURL`), since fixed.
- **No hardcoded colors.** The Config/Status pages use FPP's own dialog, toast, and CSS
  color idioms (matching the native File Copy and System Stats pages) instead of
  hardcoded hex/RGB values, so they adapt correctly to FPP's dark theme. This was also a
  real, since-fixed finding - see the Changelog entry for the switch away from plain
  browser `alert()`/`confirm()`/`prompt()` and hardcoded colors.
- **Install/uninstall are idempotent.** Re-running `fpp_install.sh` doesn't duplicate the
  SSH keypair, cron-less command registration, or `data/` setup; `fpp_uninstall.sh` is
  safe to reason about as a single clean pass (see
  [Requirements, Install, and Uninstall](requirements-install-uninstall.md)).
- **No cron jobs, systemd units, or symlinks** are created outside FPP's own plugin
  lifecycle hooks and `commands/descriptions.json` mechanism.
- **No donation prompts, analytics, telemetry, or advertising** anywhere in the plugin's
  UI or scripts.
- **`dependencies.packages` is deliberately left empty** (`[]`) in `pluginInfo.json`.
  This is intentional, not an oversight: FPP ref-counts packages listed there per-plugin
  and `apt-remove`s one once no plugin/user still claims it. For foundational tools like
  `rsync`, `openssh-client`, and `jq` - which plenty of other things on a real system can
  depend on outside this plugin's own knowledge - that ref-counting previously cascaded,
  via real `apt` dependency resolution, into removing `raspi-firmware` and the entire
  `openssh-server`/`ssh` stack on a real Pi5 when this plugin was uninstalled. Declaring
  these packages in `dependencies.packages` would restore FPP's automatic
  install-on-add behavior, but at the cost of reintroducing that same
  uninstall-cascade risk. Instead, `fpp_install.sh` installs them directly via a plain
  `apt-get install` (see [Requirements](requirements-install-uninstall.md)), and
  `fpp_uninstall.sh` never removes them.

## Findings

### Open: extensive use of `sudo`

`scripts/mount_usb.sh`, `unmount_usb.sh`, `format_usb.sh`, `run_backup.sh`, and
`fpp_uninstall.sh` all shell out to `sudo` for privileged operations - mounting/
unmounting/formatting block devices, editing `/etc/fstab`, and (for a Host-local backup)
preserving root-owned files pulled from `/etc/network`, `/etc/wpa_supplicant`, and
`/etc/fpp`.

This is flagged as an architectural question rather than a straightforward violation:
none of what this plugin does (managing a dedicated backup block device, reading system
config paths that are root-owned by design) is achievable without some privilege
escalation, and FPP itself relies on passwordless local `sudo` for the `fpp` user
elsewhere. The guideline's spirit is to avoid *unnecessary* privilege escalation, not to
forbid it outright for tools that are inherently disk/system-management utilities. This
finding remains open/unresolved rather than fixed, since narrowing `sudo` usage further
(e.g. via a tightly scoped sudoers policy per script) would be a real architectural
change, not a small patch - noted here for visibility rather than acted on yet.

### Fixed: two help-type menu entries

`PLUGIN_GUIDELINES.md` calls out that a plugin should register at most one `help`-type
entry in `menu.inc`. This plugin previously had two: a dedicated Help page and a
separate About page. Fixed as an incidental side effect of restructuring the in-app Help
page - the standalone About page was merged into a new "About" section at the bottom of
Help, `about.php` was removed, and `menu.inc` now registers a single `help`-type entry.
See the Changelog for the change itself.

### Fixed: missing `icon.png`

Covered under Passing above - `pluginInfo.json` declared an `iconURL` that 404'd until
`icon.png` was actually committed (see Changelog).
