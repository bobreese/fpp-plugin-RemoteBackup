#!/bin/bash
# Checks whether any target remote is currently playing a sequence, via
# each remote's own FPP web API (GET /api/system/status). Used as a
# synchronous pre-check by ajax.php's 'start' action, so a manual Start
# Backup/Dry Run click gets an immediate, honest answer instead of a
# false "started" followed by silence - see run_backup.sh for the
# authoritative guard that actually refuses the run (this script only
# reports; it never blocks anything by itself).
#
# Usage: check_remotes_playing.sh [--remotes id1,id2,...]
# With no --remotes, every remote marked "selected": true in
# data/settings.json is checked - same selection rule run_backup.sh uses.
#
# Output JSON: {"ok":true,"playing":[{id,hostname,address,statusName}...]}

. "$(dirname "$0")/lib_common.sh"

REMOTE_FILTER=""
while [ $# -gt 0 ]; do
    case "$1" in
        --remotes) shift; REMOTE_FILTER="$1" ;;
    esac
    shift
done

if [ ! -f "$SETTINGS_FILE" ]; then
    echo '{"ok":true,"playing":[]}'
    exit 0
fi

if [ -n "$REMOTE_FILTER" ]; then
    IFS=',' read -ra WANT <<< "$REMOTE_FILTER"
    WANT_JSON=$(printf '%s\n' "${WANT[@]}" | jq -R . | jq -s .)
    REMOTES_JSON=$(jq -c --argjson want "$WANT_JSON" '[.remotes[] | select(.id as $i | $want | index($i))]' "$SETTINGS_FILE")
else
    REMOTES_JSON=$(jq -c '[.remotes[] | select(.selected == true)]' "$SETTINGS_FILE")
fi

# Queried in parallel (one background job per remote), not one after
# another - this runs synchronously from ajax.php's 'start' action while
# the UI waits, and rb_remote_status_name's curl has its own 5s timeout
# per remote. Sequentially, a handful of unreachable remotes alone could
# take longer than the UI's own 20s request timeout; in parallel the
# whole check takes about as long as the single slowest remote.
TMP_LIST=$(mktemp "${DATA_DIR}/tmp_playcheck_list_XXXXXX")
TMP_OUT_DIR=$(mktemp -d "${DATA_DIR}/tmp_playcheck_out_XXXXXX")
trap 'rm -f "$TMP_LIST"; rm -rf "$TMP_OUT_DIR"' EXIT

echo "$REMOTES_JSON" | jq -c '.[]' > "$TMP_LIST"

i=0
while IFS= read -r r; do
    i=$((i + 1))
    (
        id=$(echo "$r" | jq -r '.id')
        hostname=$(echo "$r" | jq -r '.hostname')
        address=$(echo "$r" | jq -r '.address')
        statusName=$(rb_remote_status_name "$address")
        if [ "$statusName" = "playing" ]; then
            jq -n --arg id "$id" --arg hostname "$hostname" --arg address "$address" --arg statusName "$statusName" \
                '{id:$id, hostname:$hostname, address:$address, statusName:$statusName}' > "${TMP_OUT_DIR}/${i}.json"
        fi
    ) &
done < "$TMP_LIST"
wait

RESULTS=$(cat "${TMP_OUT_DIR}"/*.json 2>/dev/null)
if [ -n "$RESULTS" ]; then
    echo "$RESULTS" | jq -s '{ok: true, playing: .}'
else
    echo '{"ok":true,"playing":[]}'
fi
