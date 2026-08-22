#!/bin/bash
# Fetches the configured show schedule from a designated "show master" FPP
# system's own web API and classifies it into busy/free windows per day of
# week - the data behind Config's "Show Schedule Conflict Check" panel,
# which helps pick a backup time that won't land during a live show.
#
# Read-only and purely advisory: this never touches the master, never
# affects any backup, and its output is a recommendation to verify, not a
# guarantee - see the Note in that Config panel and
# docs/schedule-conflict-check.md for exactly why (day-of-week codes
# inferred from observed FPP behavior rather than FPP's own source, and
# sun-relative "SunSet"/"SunRise" entries deliberately left unresolved to
# an exact clock time rather than risk guessing wrong).
#
# Usage: check_master_schedule.sh <address>
# Output JSON: {"ok":true,"timeFormat":"12"|"24","days":{"Sun":[...],...}}
# Each interval: {start, end, sunRelative, unparsed, label}
#   sunRelative - start/end (or both) is a "SunSet"/"SunRise" anchor, not an
#     exact clock time; the value shown includes any configured offset
#     (e.g. "SunSet+30m") but has NOT been resolved to a real time.
#   unparsed - start/end didn't match anything this script recognizes
#     (neither a literal HH:MM:SS nor a known sun-relative keyword) - kept
#     visible rather than silently dropped, since a real configured entry
#     this script can't fully understand should never just vanish from
#     the picture.
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
# Day-of-week codes (0-6 = Sun-Sat, 7 = every day, 8 = weekdays, 9 =
# weekends) follow documented FPP convention, corroborated by two real
# schedules pulled during development (a day:7 "every day" set and a
# day:1 Monday-only entry) - but not proven against every FPP version, so
# any code this doesn't recognize fails safe as "every day" rather than
# being silently dropped from the picture. Same fail-safe idea for a
# start/end time that's neither a literal HH:MM:SS nor "SunSet"/"SunRise" -
# it's flagged (unparsed) and kept, never quietly ignored, since a false
# "this day looks clear" is a much worse outcome here than an overcautious
# one.
jq --arg today "$TODAY" --arg timeFormat "$TIME_FORMAT" '
def dayNames: ["Sun","Mon","Tue","Wed","Thu","Fri","Sat"];

def daysForCode($d):
  if ($d >= 0 and $d <= 6) then [dayNames[$d]]
  elif ($d == 7) then dayNames
  elif ($d == 8) then ["Mon","Tue","Wed","Thu","Fri"]
  elif ($d == 9) then ["Sat","Sun"]
  else dayNames
  end;

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
      . + { ($d): ( [ $entries[] | select(.days | index($d)) | {start,end,sunRelative,unparsed,label} ]
                    | sort_by(if (.sunRelative or .unparsed) then "zzz" else .start end) ) }
    ))
  }
' <<< "$RAW"
