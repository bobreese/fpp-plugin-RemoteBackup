# FPP Backup vs. Remote Backup

[← Back to README](../README.md)

FPP's built-in File Copy Backup is time-tested and reliable. This plugin doesn't replace
it - it adds capabilities FPP's own backup doesn't have, for backing up one or more
MultiSync remotes from a central Host.

- **One device at a time vs. many at once.** FPP File Copy Backup has to be run locally,
  one device at a time. Remote Backup can back up one or multiple devices in a single run.
- **Manual only vs. scheduled.** FPP File Copy Backup can't be scheduled. Remote Backup can
  run both a Dry Run and a full backup from FPP's own Scheduler, and includes a Show
  Schedule Conflict Check so a scheduled backup doesn't land during a live show.
- **No space check vs. a built-in one.** FPP File Copy Backup has no way to check free
  space first, risking filling a device's storage completely. Remote Backup compares the
  estimated size to be backed up (both a Dry Run and a real backup) against space available
  on the destination before it runs. When the destination is SD Card / System Storage, it
  also reserves an extra 500MB so that fallback storage is never filled all the way to 100%.
- **No playback awareness vs. two configurable options.** FPP File Copy Backup doesn't check
  whether a device is playing a sequence. Remote Backup can either refuse the whole run, or
  skip just the busy remote(s) and back up the rest. Note: under "skip," the busy remote's
  own storage is never touched, but the *other* remotes' transfers still run on the same
  network while the show plays - that shared network traffic can add some contention/timing
  risk of its own.
- **No status alert vs. an optional email summary.** FPP File Copy Backup has no way to
  tell you how a run went short of checking it yourself. Remote Backup can email a summary
  after runs - all runs or scheduled-only, and for any outcome from "at least one failed"
  up to every run - reusing FPP's own outbound email (`FPP Settings > Email`) rather than
  managing delivery itself.
- **Restoring stays the same.** Remote Backup doesn't replace FPP's own File Copy Restore -
  it's already proven reliable, so there's no reason to.

For the full picture, see the [README](../README.md) and
[Features & Safe Guards](features.md).
