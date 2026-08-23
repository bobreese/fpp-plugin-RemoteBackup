#!/bin/bash
# Fetches the configured show schedule from a designated "show master" FPP
# system's own web API and classifies it into busy/free windows per day of
# week - the data behind Config's "Show Schedule Conflict Check" panel,
# which helps pick a backup time that won't land during a live show.
#
# Read-only and purely advisory: this never touches the master, never
# affects any backup, and its output is a recommendation to verify, not a
# guarantee - see the Note in that Config panel and
# docs/schedule-conflict-check.md for exactly why (day-of-week codes taken
# from FPP's own src/ScheduleEntry.h INX_* constants, and sun-relative
# "SunSet"/"SunRise" entries deliberately left unresolved to an exact
# clock time rather than risk guessing wrong).
#
# Usage: check_master_schedule.sh <address>
# Output JSON: {"ok":true,"timeFormat":"12"|"24","days":{"Sun":[...],...}}
# Each interval: {start, end, sunRelative, unparsed, dateParity, label}
#   sunRelative - start/end (or both) is a "SunSet"/"SunRise" anchor, not an
#     exact clock time; the value shown includes any configured offset
#     (e.g. "SunSet+30m") but has NOT been resolved to a real time.
#   unparsed - start/end didn't match anything this script recognizes
#     (neither a literal HH:MM:SS nor a known sun-relative keyword) - kept
#     visible rather than silently dropped, since a real configured entry
#     this script can't fully understand should never just vanish from
#     the picture.
#   dateParity - "odd"|"even"|null. FPP's "Odd"/"Even" day option runs the
#     entry on alternating calendar days of the month (1st, 3rd, 5th... or
#     2nd, 4th, 6th...), not a fixed weekday - so which weekday it lands on
#     shifts every time. It's shown under every day of the week (its start/
#     end times are real, just not tied to one weekday) but flagged here so
#     the UI can mark it "verify manually" instead of a false Clear or a
#     falsely certain conflict on a day it may not actually run.
#   start/end are always the raw literal HH:MM:SS (or sun-relative label)
#   as reported by the master's own /api/schedule - not pre-formatted for
#   display. timeFormat says how the client should render a literal time:
#   read from the SAME master's own Settings > Localization > Time Format
#   (GET /api/settings/TimeFormat, a strftime-style value - "%H:%M" for
#   24-hour, "%I:%M %p" for 12-hour AM/PM), so the schedule panel matches
#   whatever that system is actually configured to show, not a hardcoded
#   choice. Defaults to "12" (FPP's own default) if that setting can't be
#   read for any reason - never fails the whole request over it.

. "$(dirname "$0")/lib_common.sh"

ADDRESS="$1"
if [ -z "$ADDRESS" ]; then
    echo '{"ok":false,"error":"address required"}'
    exit 0
fi

urlhost="$ADDRESS"
case "$ADDRESS" in *:*) urlhost="[${ADDRESS}]" ;; esac

RAW=$(curl -s --max-time 8 "http://${urlhost}/api/schedule" 2>/dev/null)
if [ -z "$RAW" ] || ! echo "$RAW" | jq -e . >/dev/null 2>&1; then
    jq -n --arg a "$ADDRESS" \
        '{ok:false, error:("Could not reach " + $a + " or read its schedule API - check the address and that FPP is running there.")}'
    exit 0
fi

TODAY=$(date '+%Y-%m-%d')

# TimeFormat is its own separate FPP setting (Settings > Localization),
# best-effort only - a missing/unreachable/unexpected value falls back to
# "12" (FPP's own factory default) rather than failing the whole check
# over a setting that has nothing to do with the schedule data itself.
TIME_FORMAT_RAW=$(curl -s --max-time 5 "http://${urlhost}/api/settings/TimeFormat" 2>/dev/null | jq -r '.value // empty' 2>/dev/null)
case "$TIME_FORMAT_RAW" in
    *%H*) TIME_FORMAT="24" ;;
    *) TIME_FORMAT="12" ;;
esac

# --- Classification -------------------------------------------------------
# Day-of-week codes come straight from FPP's own src/ScheduleEntry.h:
#   0-6 Sun-Sat, 7 Everyday, 8 Weekdays (Mon-Fri), 9 Weekend (Sat/Sun),
#   10 Mon/Wed/Fri, 11 Tue/Thu, 12 Sun-Thurs, 13 Fri/Sat, 14 Odd day,
#   15 Even day, and >=65536 (bit 0x10000 set) a custom "Day Mask" whose
#   specific days are OR'ed into the same integer as bits 0x4000 (Sun)
#   down to 0x0100 (Sat) - see hasBit()/dayMaskDays() below. Any code
#   outside all of that fails safe as "every day" rather than being
#   silently dropped from the picture - a false "this looks clear" is a
#   worse outcome than an overcautious one. Same fail-safe idea for a
#   start/end time that's neither a literal HH:MM:SS nor "SunSet"/
#   "SunRise" - it's flagged (unparsed) and kept, never quietly ignored.
jq --arg today "$TODAY" --arg timeFormat "$TIME_FORMAT" '
def dayNames: ["Sun","Mon","Tue","Wed","Thu","Fri","Sat"];

# Tests a single power-of-two bit via floor-division/mod, since jq has no
# native bitwise operators.
def hasBit($n; $bit): (($n / $bit) | floor) % 2 == 1;

def dayMaskDays($d):
  [ {b:16384,n:"Sun"}, {b:8192,n:"Mon"}, {b:4096,n:"Tue"}, {b:2048,n:"Wed"},
    {b:1024,n:"Thu"}, {b:512,n:"Fri"}, {b:256,n:"Sat"} ]
  | map(select(hasBit($d; .b)) | .n);

def daysForCode($d):
  if ($d >= 0 and $d <= 6) then [dayNames[$d]]
  elif ($d == 7) then dayNames
  elif ($d == 8) then ["Mon","Tue","Wed","Thu","Fri"]
  elif ($d == 9) then ["Sat","Sun"]
  elif ($d == 10) then ["Mon","Wed","Fri"]
  elif ($d == 11) then ["Tue","Thu"]
  elif ($d == 12) then ["Sun","Mon","Tue","Wed","Thu"]
  elif ($d == 13) then ["Fri","Sat"]
  elif ($d == 14) then dayNames
  elif ($d == 15) then dayNames
  elif hasBit($d; 65536) then dayMaskDays($d)
  else dayNames
  end;

def dateParityFor($d): if ($d == 14) then "odd" elif ($d == 15) then "even" else null end;

def isLiteralTime($t): ($t|type=="string") and ($t|test("^[0-2][0-9]:[0-5][0-9]:[0-5][0-9]$"));
def isSunKeyword($t): ($t|type=="string") and (($t=="SunSet") or ($t=="SunRise"));
def offsetLabel($off):
  if ($off==0 or $off==null) then ""
  else (if ($off>0) then "+" else "" end) + ($off|tostring) + "m"
  end;

[ .[]
  | select((.enabled == 1) or (.enabled == true))
  | select((.endDate // "9999-12-31") >= $today)
  | . as $e
  | {
      label: (if (($e.playlist // "") != "") then $e.playlist
              elif ($e.command != null) then
                ("Command: " + $e.command +
                 (if ($e.args and ($e.args|length>0)) then " (" + ($e.args|join(", ")) + ")" else "" end))
              else "Untitled schedule entry" end),
      days: daysForCode($e.day),
      dateParity: dateParityFor($e.day),
      startLiteral: isLiteralTime($e.startTime),
      endLiteral: isLiteralTime($e.endTime),
      startSun: isSunKeyword($e.startTime),
      endSun: isSunKeyword($e.endTime),
      raw: $e
    }
  | . as $c
  | {
      label: $c.label,
      days: $c.days,
      dateParity: $c.dateParity,
      sunRelative: ($c.startSun or $c.endSun),
      unparsed: ((($c.startLiteral or $c.startSun)|not) or (($c.endLiteral or $c.endSun)|not)),
      start: (if $c.startLiteral then $c.raw.startTime
              elif $c.startSun then ($c.raw.startTime + offsetLabel($c.raw.startTimeOffset))
              else ("? " + ($c.raw.startTime|tostring)) end),
      end: (if $c.endLiteral then $c.raw.endTime
            elif $c.endSun then ($c.raw.endTime + offsetLabel($c.raw.endTimeOffset))
            else ("? " + ($c.raw.endTime|tostring)) end)
    }
]
| . as $entries
| { ok: true,
    timeFormat: $timeFormat,
    days: (dayNames | reduce .[] as $d ({};
      . + { ($d): ( [ $entries[] | select(.days | index($d)) | {start,end,sunRelative,unparsed,dateParity,label} ]
                    | sort_by(if (.sunRelative or .unparsed) then "zzz" else .start end) ) }
    ))
  }
' <<< "$RAW"
