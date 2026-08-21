# Scheduling Backups

[← Back to README](../README.md)

The plugin registers two FPP Commands (`commands/descriptions.json`) that show up in
FPP's own Scheduler automatically - no extra scripting needed:

1. **Select which remotes to back up.** On the Remote Backup Config page, check the remotes you want
   included and hit Save Settings. A scheduled run always backs up whatever is currently
   checked there - the command itself takes no per-run arguments.
2. **Open FPP's Scheduler** and add a new scheduled entry.
3. For that entry's action, choose **Run Command**, then pick one of:
   - **Run Remote Backup** - starts a real backup (rsync pull from every selected remote).
   - **Run Remote Backup Dry Run** - simulates it and logs the estimated size vs.
     available space, without copying anything (useful as an earlier "will this fit"
     check before a real backup night - it updates the same "Estimated total transfer"
     summary on the Status page).
4. Set the day/time/recurrence you want and save the schedule entry.

A few things worth knowing before scheduling it:

- Both commands launch `scripts/run_backup.sh` in the background (`nohup ... &`) and
  return immediately - the Scheduler entry itself finishes right away, while the actual
  backup keeps running and reports progress on the plugin's Status page, not in the
  Scheduler's own log.
- A second run can't actually start while one is already in progress - `run_backup.sh`
  holds an exclusive lock (`data/run.lock`) for its whole duration, so a Scheduler entry
  that fires mid-run is refused outright rather than competing for the same destination.
  It's still worth not scheduling entries close enough together that this becomes the
  normal outcome - a refused run explains why in `data/logs/engine.log` and in FPP's own
  command output, but it still means that scheduled backup didn't happen.
- A scheduled run is also refused outright (same "explains why, but didn't happen"
  outcome) if any selected remote is actively playing a sequence at the moment it fires -
  worth keeping in mind if you schedule backups during hours a show might still be
  running rather than only overnight.
- Host Mode must be enabled and destination storage configured/mounted before the
  schedule fires, same as running it manually - otherwise the scheduled run fails
  immediately (visible in its log, but nothing gets backed up).
