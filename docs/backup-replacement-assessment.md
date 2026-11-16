# Replacing FPP's Native Backup: A Readiness Assessment

[← Back to README](../README.md)

A working list of what would stand between Remote Backup as it is today and fully
replacing FPP's native backup and restore tools - not a goal or roadmap of this plugin,
which has never set out to replace anything, but a plain-language readiness check kept
here in case FPP's own developers ever want to weigh this plugin as a possibility for
that role, revisited as the plugin matures. Each finding is rated by how much it would
actually block a "replace FPP's native tools" decision, not by how hard it would be to
fix.

**This revision removes the Beta label** (see finding #3) and reassesses every other
finding against roughly two and a half months and 50+ merged changes of real field use
since the previous revision - including actual GitHub issue reports from someone running
this across 20-30 remotes, real hardware failures, an FPP OS version upgrade (10.3 →
10.4), and a device reimage/rename, not just lab testing. That track record is why the
Beta label comes off; it does not mean every other finding below moved, and a couple
didn't move at all.

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
   - *Narrowed on the restore side, not the backup side:* FPP 10.4 added a per-area
     restore picker to File Copy Restore itself - checking Configuration on a restore now
     offers the same area list as the JSON settings restore (Channel Outputs, Show Setup/
     Schedule, Network, etc.) instead of being all-or-nothing, and reads it straight out
     of whichever folder is being restored, no special artifact required. Verified this
     works against Remote Backup's own backups with zero changes needed on this plugin's
     side, since they're already the plain `settings`/`config/`/`playlists`/`scripts`
     layout that picker reads (see [Restoring a Backup](restoring-a-backup.md)). That
     closes most of the practical gap this finding was really about - restoring just the
     settings you need, without pulling in an unrelated system's hostname/network along
     with it - through FPP's own tool rather than anything Remote Backup built. What's
     still missing is the backup-side half: a portable, exportable single-file settings
     artifact of its own, and any guided restore for the system-config `.tar.gz`
     specifically (still a manual `tar xzf` either way).
   - *To close the remaining half:* a mode that calls FPP's own config-export mechanism on
     each remote (if exposed over its API) and stores the result as a clearly separate,
     restorable artifact matching FPP's own native format - not just relying on the raw
     files already being swept up in the general pull.
2. **No restore path of its own.** By design, Remote Backup only pulls. Content restore
   is handed off entirely to FPP's native `File Copy Restore`; anything in a
   `system-config.tar.gz` has to be manually untarred and placed back by hand, with no
   guided flow at all.
   - *Still fully open, architecturally unchanged:* FPP 10.4's per-area restore picker
     (above) makes what you can restore *through FPP's own tool* better, but Remote
     Backup itself still has no restore button, no "restore this remote" action anywhere
     in its own UI. Whether that's actually a gap worth closing, or a deliberate and
     correct division of labor, is still the real question - see the bottom line below.
   - *To close it:* the biggest architectural decision on this whole list - either
     accept that FPP's native restore stays in the loop permanently (in which case
     "replace" isn't really the right goal), or build and prove out a real restore path
     independent of it.
3. **~~Explicitly Beta, with no fallback if it's the only copy.~~ RESOLVED - Beta label
   removed.** Reasonable for a complement running alongside native backup when this was
   written; a different risk entirely as someone's sole backup mechanism, with zero
   redundancy if a bug in an actively-changing codebase silently breaks a run - which was
   the real concern behind the label, not the word itself.
   - *What actually closed it:* roughly two and a half months and 50+ merged changes since
     this assessment was last written, including genuine field use across 20-30 remotes,
     real hardware failures (a failing primary drive, a clone drive that dropped off the
     USB bus entirely), an FPP OS upgrade (10.3 → 10.4), and a device reimage/rename -
     not just internal testing. Every issue reported from that use was diagnosed and
     fixed, several within the same day reported. That's a real track record, not a
     time-based or version-number milestone, which is why this is the one finding that
     actually gets removed rather than narrowed.
   - *What this does NOT mean - see finding #9:* "no longer Beta" is not "bug-free" or
     "done finding bugs." Two of the issues closed in that window were genuinely
     significant, field-reported, silently-destructive bugs (not cosmetic ones caught by
     testing) - see finding #9 for why that's recorded honestly rather than folded quietly
     into this finding's resolution.

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
   - *Still fully open - not to be confused with a different fix that shipped nearby:*
     this plugin's own settings now self-heal from an external backup copy if the
     plugin itself is uninstalled/reinstalled on the *same, still-alive* Host (confirmed
     working as intended via a real reinstall log). That's a genuinely different scenario
     from the one this finding describes - it protects against losing settings to FPP's
     own Plugin Manager doing an uninstall+reinstall, not against the Host itself being
     destroyed - so it doesn't move this finding at all. A new Host still starts from
     nothing: no settings, no SSH keypair, no selected remotes, unless those were backed
     up somewhere else by hand.
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
   - *Also shipped:* a one-time "Scheduled Backup - Remote(s) Failed" popup on the
     Status/Config page, shown the next time either is opened after a scheduled run ends
     with one or more remotes in a real error state - the same "nobody was watching it
     happen" mechanism already used for a remote skipped because it was playing a
     sequence. Independent of FPP's own Email Settings being configured at all, so it
     narrows this finding along a different axis than the email summary does. Still
     pull-based, though - you have to open the page to see it - not a push notification
     reaching you anywhere else, so the finding isn't fully closed.
   - *Unchanged since:* no actual push mechanism (webhook, mobile notification, etc.) has
     shipped in the time since - still two independent pull-based mitigations, not a
     push one.

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
   - *Still open (the sshKeyPath half):* confirmed still accurate as of this revision -
     `fpp_uninstall.sh` still removes the keypair from a hardcoded path rather than
     reading the `sshKeyPath` setting it accepts on save.
9. **Still shaking out real bugs - and the ones found since are worth naming honestly,
   not folded quietly into "more of the same."** The original entry here was a single
   display-only integer overflow found during this plugin's own development, plus a
   testing pass that caught roughly seven more, none of which reached a real, unattended
   production failure. That framing doesn't fully hold for what's surfaced since:
   - **Two field-reported bugs since were genuinely significant, not cosmetic.** A
     reinstall/update (FPP's Plugin Manager does a full uninstall+reinstall for both)
     could silently reset every setting - remotes, SSH config, excludes, all of it - back
     to bare defaults on every single occurrence, discovered only because a real user
     noticed their settings kept disappearing and reported it. Separately, a rebuilt
     remote's new SSH host key could fail *every* backup/key-push attempt indefinitely
     (confirmed: 10 straight identical failures on a real system) because a single
     corrupted line elsewhere in `known_hosts` - itself a leftover scar from a
     concurrency bug predating this plugin's own fix for it - made the self-heal silently
     do nothing, discoverable only by a user willing to SSH in and read raw command
     output by hand. Both are now fixed, and the second fix also makes the self-heal
     actually log a real failure instead of swallowing it next time.
   - **What's genuinely different now, and worth counting as the real maturity signal:**
     not "no bugs found" - clearly untrue - but that the plugin has now been exercised
     against failure modes a lab environment doesn't produce on its own: real hardware
     dying mid-backup, an actual OS version upgrade, a device reimage/rename, and FPP's
     own Plugin Manager's real uninstall+reinstall behavior - and every one of those,
     plus every bug they exposed, got diagnosed from real logs and fixed. That's a
     meaningfully different (and arguably better) kind of evidence than a clean lab
     testing pass, but it is evidence of an actively-maintained, responsive codebase, not
     one that's stopped surfacing issues.
   - *To close it fully:* there's no real "done" state for this finding short of the
     bug rate genuinely trailing off over a much longer window - which is itself just
     more of the track record this revision is already crediting elsewhere. Treat this as
     an ongoing data point to keep appending to, not a thing to mark resolved.

## Bottom line: not ready to replace - a strong complement today

Remote Backup solves a real problem FPP's native tools don't touch: unattended,
scheduled, centralized content backup across a whole MultiSync show. That's genuinely
valuable and worth running today, alongside FPP's native tools, not instead of them.
Removing the Beta label (finding #3) doesn't change that - findings #1, #2, #4, and #5
were never about Beta status, and all four are still open.

- **Keep FPP's native config backup and restore in the loop** - Remote Backup has no
  equivalent to FPP's own packaged settings-export artifact today, though FPP 10.4's
  per-area restore picker (finding #1) now lets you restore just one settings area
  straight out of a Remote Backup folder, which narrows the practical gap more than it
  might first appear.
- **Don't treat it as the only copy** until the single-Host operational-continuity gap
  (finding #4) has a real answer - the "it's Beta" reason to hold off is gone, but this
  one isn't.
- **The restore-path decision (#2) is the one that actually matters most** - everything
  else on this list is buildable; deciding whether Remote Backup should ever own restore
  is the real fork in the road.

## A narrower question: replacing just File Copy Backup

A different, smaller question worth separating out: not "replace everything," but
specifically swapping FPP's native `File Copy Backup` for Remote Backup, while leaving
config backup and restore native either way.

That reframing removes two of the three original Critical findings above - they're
about config backup and restore, and neither is in scope for this narrower swap. Restore
is unaffected: Remote Backup already formats destinations the same way File Copy Backup
would, producing the same `<Hostname>-<date>` folder layout File Copy Restore expects,
so restoring content Remote Backup pulled is no different from restoring content File
Copy Backup produced - and, per finding #1, restoring just one settings area from it now
works too, on FPP 10.4+.

On the one job both tools actually share - backing up content - Remote Backup is a real
upgrade, not a lateral move:

- File Copy Backup is manual, one system at a time. The most common way backups
  actually fail is "nobody ran it before the disaster" - scheduling closes exactly that
  gap, across every remote at once.
- Dry-run space verification and optional dated snapshot history are capabilities File
  Copy Backup doesn't have at all, not just does worse.
- Delete-mirroring keeps the backup from silently accumulating stale content
  indefinitely, if wanted.

**Verdict, updated: a net strengthening of FPP's overall backup story for this narrower
swap, and a stronger one than the previous revision of this document could honestly
claim** - the Beta caveat that previously qualified this verdict is gone, for the real
reasons laid out in finding #3. What hasn't changed: **finding #4 (protect the Host
itself) is still the one condition that actually matters here, and it hasn't moved at
all.** Without it, this is still a trade of one failure mode (forgot to click) for a
different one (didn't notice the Host itself died), which isn't obviously safer on its
own - no amount of field-proven reliability on the Host that's running changes what
happens when that Host is the one that fails. Finding #6 (alert on silent failure)
continues toward an actual push notification rather than something you still have to go
looking for, but hasn't gained a third mechanism since the last revision either.

If finding #4 gets a real answer, this narrower swap - Remote Backup in place of File
Copy Backup specifically, config backup/restore left exactly as-is - is a reasonable
recommendation today, not a provisional one.

## Revisiting this assessment

Re-check this list against the plugin's current state before treating any finding as
resolved - in particular, whether a given fix has actually shipped, not just been
proposed here. See the [Changelog](changelog.md) for what's actually landed.
