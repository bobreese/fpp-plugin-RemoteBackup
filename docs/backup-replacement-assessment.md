# Replacing FPP's Native Backup: A Readiness Assessment

[← Back to README](../README.md)

A working list of what would stand between Remote Backup as it is today and fully
replacing FPP's native backup and restore tools - not a goal or roadmap of this plugin,
which has never set out to replace anything, but a plain-language readiness check kept
here in case FPP's own developers ever want to weigh this plugin as a possibility for
that role, revisited as the plugin matures. Each finding is rated by how much it would
actually block a "replace FPP's native tools" decision, not by how hard it would be to
fix.

Three FPP-side things are in scope for comparison:

- **FPP's native `Backup/Restore Configuration`** - exports just FPP's own settings
  (channel outputs, universes, schedule, playlists metadata, plugin config) as a small
  archive with no media in it, built for a fast re-setup after a reflash.
- **FPP's native `File Copy Backup/Restore`** - copies actual show content (media,
  sequences, effects, playlists) for one system, run by hand.
- **Remote Backup (this plugin)** - pulls the full `/home/fpp/media` content tree from
  every selected MultiSync remote onto one Host, on a schedule, plus optional system/
  network config as a raw archive.

## Findings, by severity

### Critical - block replacing FPP's native backup outright

1. **No equivalent to FPP's own *packaged* config backup/restore artifact.** Corrected
   after initially overstating this: FPP's own internal settings - channel outputs,
   playlists, schedule, model/universe config - live as JSON files under
   `/home/fpp/media/config/`, which is *inside* the tree Remote Backup already pulls
   wholesale (no exclude pattern touches `config/`). That data genuinely does get backed
   up, as an ordinary side effect of the content pull, not because this plugin does
   anything special for it. What's actually missing is FPP's own native `Backup/Restore
   Configuration` mechanism specifically: a portable, versioned, single-file export built
   for fast re-setup after a reflash, with a guided restore flow that knows how to unpack
   it. Remote Backup's copy of `config/` is undifferentiated - files inside the general
   content backup, restorable via File Copy Restore like anything else, but not through
   that same curated single-click "restore my settings" experience, and not extractable
   as a standalone artifact without the whole media backup alongside it. The optional
   system-config capture (`/etc/fpp`, network config as a raw `.tar.gz`) has the same
   restore gap, one layer further down: getting it back onto a system means manually
   untarring the archive and copying files into place by hand - there's no guided restore
   for it from FPP or this plugin, only the raw material to do it yourself.
   - *Severity worth reconsidering:* this is a real gap in convenience and portability,
     not in whether the underlying settings data survives a disaster - meaningfully
     narrower than "no equivalent... to config-only backup" originally implied. Whether
     it still belongs in the Critical bucket is worth revisiting rather than assumed.
   - *To close it:* a mode that calls FPP's own config-export mechanism on each remote
     (if exposed over its API) and stores the result as a clearly separate, restorable
     artifact matching FPP's own native format - not just relying on the raw files
     already being swept up in the general pull.
2. **No restore path of its own.** By design, Remote Backup only pulls. Content restore
   is handed off entirely to FPP's native `File Copy Restore`; anything in a
   `system-config.tar.gz` has to be manually untarred and placed back by hand, with no
   guided flow at all.
   - *To close it:* the biggest architectural decision on this whole list - either
     accept that FPP's native restore stays in the loop permanently (in which case
     "replace" isn't really the right goal), or build and prove out a real restore path
     independent of it.
3. **Explicitly Beta, with no fallback if it's the only copy.** Reasonable for a
   complement running alongside native backup; a different risk entirely as someone's
   sole backup mechanism, with zero redundancy if a bug in an actively-changing codebase
   silently breaks a run.
   - *To close it:* a real track record - enough time in the field, and enough of the
     findings below closed, to drop the Beta label in good conscience.

### Significant - weaken the case for full replacement, matter more once it's the only backup

4. **One Host is a single point of failure for everyone - in two different ways worth
   separating.** Every remote's backup depends on one Host's SSH access, its SD card,
   and its `settings.json`:
   - **Data loss**, if the Host or its destination drive is destroyed: this half already
     has a real, existing partial mitigation - **Clone Backups to a Second Drive**
     mirrors the entire primary destination, and is explicitly documented as meant for
     an off-site/rotating spare copy. Physically rotating that second drive off-site
     protects every backup that already existed from exactly this scenario.
   - **Operational continuity**, if the Host itself goes down: unaddressed by Clone or
     anything else. Clone is manual only (no Scheduler command) and still runs *from
     that same Host*, using its own software and SSH keys - if the Host dies, Clone
     can't run either. There's no second Host to fail over to, and every remote's
     scheduled backup stops at once until the Host is repaired/replaced and
     reconfigured. The Host's own SSH keys and configuration aren't themselves backed up
     anywhere either.
   - *To close it:* the operational-continuity half is the real open gap - a documented,
     tested Host-recovery procedure at minimum; ideally the Host's own SSH keypair and
     `data/settings.json` get folded into its own backup set automatically. The
     data-loss half already has a real answer today, provided the second drive is
     actually rotated off-site rather than left plugged in next to the primary the whole
     time (which would just mean the same disaster takes out both).
5. **Every remote must be SSH-reachable from the Host.** A remote behind a firewall
   change, moved to a different VLAN, or with SSH locked down simply can't be backed up
   - there's no fallback path. FPP's native File Copy Backup runs locally on each
     system, so it has no such network dependency to begin with.
   - *To close it:* not fully solvable while the architecture is "Host pulls over SSH" -
     worth documenting plainly as a standing precondition, and worth extending the
     missing-destination Halt/Failover pattern to an unreachable-remote case too.
6. **No proactive alert when a backup silently fails.** A broken SSH key or an
   unreachable remote fails quietly until someone opens the Status page or reads
   `engine.log`. That risk is larger specifically because the whole point of adopting
   this plugin is to stop checking backups by hand.
   - *To close it:* some form of push beyond the Status page - even something modest,
     like an FPP Command/event hook on repeated failure, or a summary emailed after
     every scheduled run.
   - *Shipped:* Config's Email Settings section now sends a status email after backup
     runs (which remotes to include - all runs vs. scheduled only - and which outcomes
     warrant one, up to always), reusing FPP's own outbound email setup rather than this
     plugin managing delivery itself. See [Features & Safe Guards](features.md#features).
     Narrows this finding rather than fully closing it: it depends on FPP's own Setting >
     Email actually being configured (nothing enforces that), and delivery past FPP's own
     local mail relay is never confirmed - a bad SMTP password or a blocked outbound port
     fails silently past that point, the same gap this finding describes just moved one
     layer down.

### Minor - worth knowing, not blocking

7. **No integrity check beyond rsync's own transfer guarantees.** No checksum
   verification pass and no test-restore step after a run completes.
   - *To close it:* an optional post-run verification pass, even a lightweight one
     (file count and size comparison against the source).
   - *Shipped (the lightweight half):* Backup Options' "Verify backup integrity after
     each run" runs a second read-only rsync dry-run pass comparing source and
     destination once more, shown as a badge on the Status page and folded into the
     email summary. Still not a checksum: it's the same size/mtime comparison rsync's
     own transfer already relies on, not byte-for-byte content verification, and a
     remote actively recording/playing between the backup and this check can produce a
     false "differs." A real checksum pass (`rsync --checksum`, reading every byte on
     both ends) and any test-restore step remain unbuilt.
8. **A couple of small, already-documented rough edges** - stray `/etc/fstab` backup
   files that never get cleaned up, and the SSH keypair path being hardcoded rather than
   reading its own `sshKeyPath` setting. Both already called out in
   [Requirements, Install, and Uninstall](requirements-install-uninstall.md#uninstall)'s
   Known Minor Gaps.
   - *Shipped (the fstab half, on uninstall only):* `fpp_uninstall.sh` now removes all
     four `/etc/fstab.rb-*-bak` files unconditionally as one of its last steps, so a
     normal uninstall leaves none behind. Deliberately not fixed by snapshotting the
     original `/etc/fstab` at install and restoring it wholesale at uninstall, which was
     considered and rejected - `/etc/fstab` isn't exclusive to this plugin, so a full-file
     restore would silently discard any other fstab changes made in between (a manual
     mount, another plugin's own entry). The narrower gap remains: nothing cleans these
     up between mount/unmount/reformat actions while the plugin stays installed, so up to
     three of the four can still accumulate day to day.
9. **Still shaking out real bugs.** A 32-bit integer overflow in the free-space display
   was found and fixed in this plugin's development - harmless (display-only, never
   blocked an actual backup), but a sign the codebase is still maturing rather than
   long-settled the way FPP's native tools are.

## Bottom line: not ready to replace - a strong complement today

Remote Backup solves a real problem FPP's native tools don't touch: unattended,
scheduled, centralized content backup across a whole MultiSync show. That's genuinely
valuable and worth running today, alongside FPP's native tools, not instead of them.

- **Keep FPP's native config backup and restore in the loop** - Remote Backup has no
  equivalent to either one today.
- **Don't treat it as the only copy** until it's out of Beta and the single-Host failure
  mode has a real answer.
- **The restore-path decision (#2) is the one that actually matters most** - everything
  else on this list is buildable; deciding whether Remote Backup should ever own restore
  is the real fork in the road.

## A narrower question: replacing just File Copy Backup

A different, smaller question worth separating out: not "replace everything," but
specifically swapping FPP's native `File Copy Backup` for Remote Backup, while leaving
config backup and restore native either way.

That reframing removes two of the three Critical findings above - they're about config
backup and restore, and neither is in scope for this narrower swap. Restore is
unaffected: Remote Backup already formats destinations the same way File Copy Backup
would, producing the same `<Hostname>-<date>` folder layout File Copy Restore expects,
so restoring content Remote Backup pulled is no different from restoring content File
Copy Backup produced.

On the one job both tools actually share - backing up content - Remote Backup is a real
upgrade, not a lateral move:

- File Copy Backup is manual, one system at a time. The most common way backups
  actually fail is "nobody ran it before the disaster" - scheduling closes exactly that
  gap, across every remote at once.
- Dry-run space verification and optional dated snapshot history are capabilities File
  Copy Backup doesn't have at all, not just does worse.
- Delete-mirroring keeps the backup from silently accumulating stale content
  indefinitely, if wanted.

The honest cost: swapping a simple, dependency-free, long-proven manual process for a
more capable but Beta, automated one that concentrates risk into a single Host and its
SSH access to everything. **Verdict: a net strengthening of FPP's overall backup story
for this narrower swap - provided findings #4 (protect the Host itself) and #6 (alert on
silent failure) are actually addressed first**, not left as-is. Without those two, it's
a trade of one failure mode (forgot to click) for a different one (didn't notice it
broke), which isn't obviously safer on its own.

## Revisiting this assessment

Re-check this list against the plugin's current state before treating any finding as
resolved - in particular, whether a given fix has actually shipped, not just been
proposed here. See the [Changelog](changelog.md) for what's actually landed.
