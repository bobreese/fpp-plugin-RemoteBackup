# Show Schedule Conflict Check

[← Back to README](../README.md)

Config has a **"Show Schedule Conflict Check"** panel to help you pick a backup time that
won't land in the middle of a live show, by reading the configured schedule straight off
whichever system you designate as the show master.

## Why this exists

This plugin already refuses (or, if you've turned on Skip policy, works around) a backup
that's about to start while a remote happens to be playing *right now* - see
[Troubleshooting](troubleshooting.md#remote-playing-a-sequence). That's a reactive,
point-in-time check. This panel is the proactive complement: it lets you look at what's
*scheduled* to play, across the whole week, before you ever pick a time for the "Run Remote
Backup" Scheduler entry in the first place.

## Using it

1. Open Config and scroll to **"Show Schedule Conflict Check."**
2. Pick the show master from the dropdown (it's populated from your configured remotes),
   or choose **"Custom address..."** and type a hostname/IP - the master isn't necessarily
   one of the systems you back up, so it's tracked separately from your remote selections.
3. Click **Check Schedule**. It fetches that system's own `/api/schedule` and shows a
   Sunday-through-Saturday table: a green **Clear** cell means nothing configured and
   enabled applies to that day; anything else lists what does. Times are shown in
   whichever format that same master is actually configured to use (Settings >
   Localization > Time Format, `12-hour AM/PM` or `24-hour`) - not a hardcoded choice,
   and not the browser's own locale guess.
4. Optionally, use **"Check a specific time"** below the table to pick a day and time (the
   picker itself also follows the master's Time Format setting) and get a direct answer -
   Clear, a named conflict, or an approximate warning - without having to read the whole
   table yourself.
5. If you're happy with the picker's address, click **Save Settings** to remember it for
   next time.

## Read the Note in the panel - this is a recommendation, not a guarantee

A few concrete reasons this can't be a hard guarantee, and why the panel says so directly:

- **Only entries that are `enabled` and not already expired (`endDate` in the past) are
  shown.** A schedule with a lot of old seasonal entries (a prior year's Christmas show,
  for instance) won't clutter the picture with dead schedule data.
- **Day-of-week codes** (FPP's `0`-`6` = Sunday-Saturday, `7` = every day, `8` = weekdays,
  `9` = weekends) follow documented FPP convention and match every real schedule pulled
  during development, but haven't been verified against every FPP version. Any code this
  plugin doesn't recognize is treated as "every day" rather than silently ignored - a false
  "this looks clear" is a worse outcome than an overly cautious one.
- **`SunSet`/`SunRise`-anchored entries are shown as-is, not resolved to an exact time.**
  A schedule entry like `startTime: "12:00:00"`, `endTime: "SunSet"` is extremely common
  for a seasonal light show (run from midday/afternoon until dusk) - but sunset itself
  shifts by roughly four hours across the year depending on your latitude. Rather than
  guess, these show up flagged (amber, "approximate - sun-relative") and folded into the
  time-checker as an "APPROX-CLEAR: verify manually" answer instead of a false Clear or a
  falsely precise boundary.
- **Anything this plugin's parser doesn't recognize at all** (an unexpected time format,
  for instance) is flagged red ("unrecognized time - verify manually") and kept visible,
  never silently dropped from the table.

**Before trusting a time this panel suggests against an actual live show, test it once.**
Run the real backup on a night nothing is scheduled, watch it start-to-finish, and note
roughly how long it takes - then build a real safety margin into the time you actually
schedule, rather than picking a slot that just barely clears the next scheduled item on
paper. A backup that runs a little longer than expected one night (a bigger-than-usual
incremental, a slow remote, network hiccups) is a normal, easy thing to plan around; finding
out about it for the first time during an actual show is not.
