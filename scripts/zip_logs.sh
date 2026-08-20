#!/bin/bash
# Zips every *.log file under data/logs/ into a single archive, for the
# Status page's "Download All Logs" button (ajax.php's downloadAllLogs
# action streams the result back to the browser and deletes it
# afterward - this script only ever builds it).
#
# Output JSON: {"ok":true,"path":"/abs/path/to/archive.zip","sizeBytes":N,"fileCount":N}

. "$(dirname "$0")/lib_common.sh"

json_err() {
    printf '{"ok":false,"error":%s}\n' "$(printf '%s' "$1" | jq -Rs .)"
}

if ! command -v zip >/dev/null 2>&1; then
    json_err "zip is not installed on the Host. Install it (sudo apt-get install -y zip) or download logs individually instead."
    exit 0
fi

FILE_COUNT=$(cd "$LOG_DIR" 2>/dev/null && find . -maxdepth 1 -type f -name '*.log' | wc -l)
if [ -z "$FILE_COUNT" ] || [ "$FILE_COUNT" -eq 0 ]; then
    json_err "No log files found to zip."
    exit 0
fi

# Built under data/ (not data/logs/ itself) so the .zip can never be
# mistaken for a log file by getLog/rb_prune_remote_logs's own *.log
# globs, even transiently while this script is still running.
OUT=$(mktemp "${DATA_DIR}/tmp_logs_XXXXXX.zip")
rm -f "$OUT" # mktemp creates it empty; zip needs to create the file itself

if ! (cd "$LOG_DIR" && find . -maxdepth 1 -type f -name '*.log' | zip -q "$OUT" -@) >/tmp/rb_zip_err_$$ 2>&1; then
    ERR=$(cat /tmp/rb_zip_err_$$ 2>/dev/null); rm -f /tmp/rb_zip_err_$$
    rm -f "$OUT"
    json_err "zip failed: ${ERR:-unknown error}"
    exit 0
fi
rm -f /tmp/rb_zip_err_$$

if [ ! -f "$OUT" ]; then
    json_err "zip did not produce an archive."
    exit 0
fi

SIZE=$(stat -c%s "$OUT" 2>/dev/null || echo 0)
jq -n --arg path "$OUT" --argjson size "$SIZE" --argjson count "$FILE_COUNT" \
    '{ok: true, path: $path, sizeBytes: $size, fileCount: $count}'
