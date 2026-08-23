# Log Files

[← Back to README](../README.md)

This plugin keeps its own logs separate from FPP's - they live under `data/logs/`
inside the plugin's own directory
(`/home/fpp/media/plugins/fpp-plugin-RemoteBackup/data/logs/`), not in FPP's own log
directory. A single rsync run can log a fresh line per file transferred and per progress
update with no TTY to overwrite in place, so keeping that volume out of FPP's own File
Manager → Logs view is deliberate - it would otherwise flood that list with entries that
have nothing to do with FPP itself.

**View them from *Remote Backup - Status* instead**, under the "Diagnostic Log" section.
The dropdown there covers everything this plugin writes:

- `ajax.log` - every Config/Status page action and the backend script it ran
- `engine.log` - the backup run engine's own log (starts, finishes, refusals, errors)
- one entry per remote (`<hostname> rsync log`) - that remote's most recent full `rsync`
  run log
- `clone.log` - the most recent "Clone Backups to a Second Drive" run

Pick one, click "Refresh Log" (or check "Tail Follow" to poll it every few seconds while
watching a run live), same page for all of them - no SSH or file browser needed. Check
**"Errors/warnings only"** to filter whichever log is currently loaded down to just the
lines that matter - this plugin's own `ABORT`/`ERROR`/`WARN`/`FAIL`/`LOW SPACE`/
`RECOVERED` lines, rsync/ssh failure text, or a non-zero `rc=` on a "finished rsync"
line - instead of scrolling past every routine start/finish/progress line to find the
one that isn't. Filters whatever was already loaded, so toggling it is instant; no
separate error log to keep in sync.

**Download** saves the selected log to your browser as a plain text file; **Download All
Logs** zips everything currently under `data/logs/` into one archive instead - handy for
grabbing a full diagnostic snapshot in one go, e.g. when reporting an issue. Both show
live status text while the file/archive is being prepared. This is separate from FPP's
own File Manager download button, which can't reach these logs since they deliberately
live outside FPP's own log directory (see above).
