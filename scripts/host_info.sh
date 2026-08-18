#!/bin/bash
# Reports this Host's own hostname and local IP addresses, so the Config
# page can recognize (and label) a MultiSync-discovered or manually-added
# "remote" that is actually this system itself - see rb_is_host_address()
# in lib_common.sh, which run_backup.sh uses for the same recognition to
# back such a "remote" up as a local copy instead of an SSH pull.
#
# Output JSON: {"hostname": "...", "addresses": ["ip1", "ip2", ...]}

. "$(dirname "$0")/lib_common.sh"

HOSTNAME_VAL=$(hostname 2>/dev/null)
ADDR_JSON=$(rb_host_addresses | tr ' ' '\n' | grep -v '^$' | jq -R . | jq -s . 2>/dev/null)
[ -z "$ADDR_JSON" ] && ADDR_JSON='[]'

jq -n --arg hostname "$HOSTNAME_VAL" --argjson addresses "$ADDR_JSON" \
    '{hostname: $hostname, addresses: $addresses}'
