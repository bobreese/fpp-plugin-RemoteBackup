#!/bin/bash
# Probe FPP's MultiSync system list for candidate remotes to back up.
# Uses the local fppd HTTP API (GET /api/fppd/multiSyncSystems). Falls
# back gracefully (empty list) if the endpoint is unavailable so the UI
# can still offer the "manually add a remote" path.
#
# Prefers IPv4. Two passes:
#  1. If the API itself reports an IPv4-looking value anywhere on a
#     system, use that.
#  2. Otherwise (e.g. this network's multisync only publishes each
#     system's IPv6 SLAAC address, which is what we're seeing in
#     practice), try resolving <hostname>.local via mDNS/Avahi for an
#     IPv4 A record - FPP images ship with Avahi and this is usually
#     how these boxes are reachable by name anyway. Falls back to
#     whatever address the API gave (including IPv6, which now works
#     fine end-to-end) if mDNS resolution doesn't turn up an IPv4.
#
# Also drops loopback entries (127.0.0.1/::1) outright: some FPP
# versions report the local system without a reliable "local" flag we
# can filter on, and 127.0.0.1 is never a useful backup target anyway.
#
# Output JSON: { "remotes": [ {hostname, address, source}... ], "apiOk": bool }

. "$(dirname "$0")/lib_common.sh"

RAW=$(curl -s -m 5 "http://localhost/api/fppd/multiSyncSystems" 2>/dev/null || true)

if [ -z "$RAW" ] || ! echo "$RAW" | jq -e . >/dev/null 2>&1; then
    echo '{"remotes":[],"apiOk":false}'
    exit 0
fi

IPV4RE='^([0-9]{1,3}\.){3}[0-9]{1,3}$'

# Pass 1: parse + prefer an IPv4 field already present in the API response.
PARSED=$(echo "$RAW" | jq -c '
  def ipv4re: "^([0-9]{1,3}\\.){3}[0-9]{1,3}$";
  ( .systems? // .remoteSystems? // (if type=="array" then . else [] end) ) as $list
  | $list[]
  | select((.local? // false) != true)
  | . as $item
  | {
      hostname: ($item.hostname // $item.hostName // $item.HostName // $item.address // "unknown"),
      address: (
        ( [ $item[]? | select(type=="string" and test(ipv4re)) ] | first )
        // ($item.address // $item.ipAddress // $item.IP // $item.ip // "")
      ),
      version: ($item.version // $item.Version // ""),
      fppMode: ($item.mode // $item.fppMode // "")
    }
  | select(.address != "")
  | select(.address != "127.0.0.1" and .address != "::1" and (.address | startswith("127.") | not))
')

# Pass 2: for anything still IPv6 (or non-IPv4-looking), try mDNS by hostname.
echo "$PARSED" | jq -c '.' | while IFS= read -r item; do
    addr=$(echo "$item" | jq -r '.address')
    host=$(echo "$item" | jq -r '.hostname')
    if ! echo "$addr" | grep -qE "$IPV4RE"; then
        resolved=$(timeout 2 getent ahostsv4 "${host}.local" 2>/dev/null | awk '{print $1}' | head -1)
        if [ -n "$resolved" ]; then
            item=$(echo "$item" | jq -c --arg a "$resolved" '. + {address: $a, addressSource: "mdns"}')
        fi
    fi
    echo "$item"
done | jq -s '{remotes: ., apiOk: true}'
