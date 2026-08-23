#!/bin/bash
# CLI entry point for the optional bind mount that lets remotes/File Manager
# see current backups on the primary drive without unmounting it - see
# rb_bindmount_backups_ensure()/rb_bindmount_backups_teardown() in
# lib_common.sh for the actual mechanics and the safety invariant this
# depends on. Opt-in via the "enableRestoreBindMount" setting (default off).
#
# Usage:
#   bindmount_backups.sh reconcile  - bind/unbind based on current settings
#                                      + mount state (safe to call anytime;
#                                      called by ajax.php after any save
#                                      that could touch destinationMount or
#                                      the toggle, and by mount_usb.sh after
#                                      a successful primary-drive mount)
#   bindmount_backups.sh teardown   - unconditionally unbind if bound,
#                                      regardless of settings (called by
#                                      unmount_usb.sh/format_usb.sh right
#                                      before they unmount the primary drive,
#                                      so the bind mount never outlives it)
# Output JSON: {"ok":true,"action":"reconcile","bound":true|false}

. "$(dirname "$0")/lib_common.sh"

MODE="${1:-reconcile}"

case "$MODE" in
    reconcile) rb_bindmount_backups_ensure ;;
    teardown) rb_bindmount_backups_teardown ;;
    *)
        printf '{"ok":false,"error":%s}\n' "$(printf '%s' "Unknown mode: $MODE" | jq -Rs .)"
        exit 0
        ;;
esac

BOUND=false
rb_bindmount_is_active && BOUND=true
jq -n --arg mode "$MODE" --argjson bound "$BOUND" '{ok:true, action:$mode, bound:$bound}'
