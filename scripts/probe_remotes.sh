#!/bin/bash
# Probe FPP's MultiSync system list for candidate remotes to back up.
# Uses the local fppd HTTP API (GET /api/fppd/multiSyncSystems). Falls
# back gracefully (empty list) if the endpoint is unavailable so the UI
# can still offer the "manually add a remote" path.
#
# FPP reports each system it has seen as one array entry PER ADDRESS it's
# been seen at - a dual-stack device shows up as two (or more) separate
# entries sharing the same hostname/uuid, one for its IPv4 and one for its
# IPv6. A naive per-entry parse (this script's own earlier version) treats
# those as independent candidates and lets whichever one happens to sort
# last silently win - in practice that was always the IPv6 entry, since it
# consistently sorts after the IPv4 one in FPP's own output. Grouping by
# hostname and explicitly picking the best address per group fixes that:
#   1. A real (non-link-local) IPv4 address, if the group has one.
#   2. Otherwise a link-local IPv4 (169.254.x.x) - still IPv4-shaped, but
#      deprioritized below a routable address.
#   3. Otherwise IPv6.
# Then, only if the winning address is STILL not IPv4 (a genuinely
# IPv6-only remote, no IPv4 entry anywhere in its group), try resolving
# <hostname>.local via mDNS/Avahi for an IPv4 A record - FPP images ship
# with Avahi and this is usually how these boxes are reachable by name
# anyway. Falls back to the IPv6 address (which works fine end-to-end) if
# mDNS resolution doesn't turn up anything.
#
# Deliberately does NOT filter out entries flagged "local" (the Backup
# Host's own system, i.e. itself): that flag is serialized as a plain 0/1
# integer, not a JSON boolean, and a naive `!= true` comparison against it
# silently never matched anything on any FPP version - the Host has
# always leaked through as a candidate regardless. Loopback addresses
# (127.0.0.1/::1) are still dropped outright since they're never a useful
# backup target, but the Host showing up under its real address is fine
# on purpose now: it gets recognized (rb_is_host_address() in
# lib_common.sh) and backed up as a local copy, with a "Host" badge on
# the Config page, instead of being scanned out entirely.
#
# Output JSON: { "remotes": [ {hostname, address, source}... ], "apiOk": bool }

. "$(dirname "$0")/lib_common.sh"

RAW=$(curl -s -m 5 "http://localhost/api/fppd/multiSyncSystems" 2>/dev/null || true)

if [ -z "$RAW" ] || ! echo "$RAW" | jq -e . >/dev/null 2>&1; then
    echo '{"remotes":[],"apiOk":false}'
    exit 0
fi

IPV4RE='^([0-9]{1,3}\.){3}[0-9]{1,3}$'

# Pass 1: group every reported entry by hostname, and within each group
# pick the single best address (real IPv4 > link-local IPv4 > IPv6).
PARSED=$(echo "$RAW" | jq -c '
  def ipv4re: "^([0-9]{1,3}\\.){3}[0-9]{1,3}$";
  def is_linklocal_v4: test("^169\\.254\\.");
  def addr_priority:
    if (. | test(ipv4re)) then
      (if (. | is_linklocal_v4) then 1 else 2 end)
    else 0 end;

  ( .systems? // .remoteSystems? // (if type=="array" then . else [] end) ) as $list
  | ( $list
      | map(select(.address != "127.0.0.1" and .address != "::1" and (.address | startswith("127.") | not)))
    ) as $candidates
  | ( $candidates | group_by(.hostname // .hostName // .HostName // .address // "unknown") ) as $groups
  | [ $groups[] | (sort_by(-(.address | addr_priority)) | .[0]) ]
  | map({
      hostname: (.hostname // .hostName // .HostName // .address // "unknown"),
      address: (.address // .ipAddress // .IP // .ip // ""),
      version: (.version // .Version // ""),
      fppMode: (.mode // .fppMode // "")
    })
  | map(select(.address != ""))
')

# Pass 2: for anything still not IPv4 after picking the best per-group
# address (a genuinely IPv6-only remote), try mDNS by hostname.
echo "$PARSED" | jq -c '.[]' | while IFS= read -r item; do
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
