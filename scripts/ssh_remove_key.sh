#!/bin/bash
# Best-effort removal of this plugin's own SSH public key from a remote's
# ~fpp/.ssh/authorized_keys, run during uninstall as the symmetric
# counterpart to ssh_setup.sh's push.
#
# Deliberately authenticates with the key being retired itself (key-based,
# BatchMode) rather than a password: that key is only trusted on a remote
# where the earlier push actually succeeded, which is exactly the only
# case where there's anything to remove. A remote that's offline, was
# never pushed to, or has since rotated its host keys simply fails to
# connect here - harmless, since uninstall shouldn't be blocked or slowed
# waiting on remotes it can't reach anyway.
#
# Usage: ssh_remove_key.sh <address> [sshUser] [sshPort]
# Prints JSON: {"ok":true|false,"message":"..."}

. "$(dirname "$0")/lib_common.sh"

ADDRESS="$1"
SSH_USER="${2:-$(rb_setting '.sshUser' 'fpp')}"
SSH_PORT="${3:-$(rb_setting '.sshPort' '22')}"
SSH_KEY=$(rb_setting '.sshKeyPath' '/home/fpp/.ssh/id_rsa_remotebackup')

if [ -z "$ADDRESS" ]; then
    echo '{"ok":false,"message":"No address given"}'
    exit 0
fi
if [ ! -f "$SSH_KEY" ] || [ ! -f "${SSH_KEY}.pub" ]; then
    echo '{"ok":false,"message":"No local SSH key found; nothing to remove remotely."}'
    exit 0
fi

# Same bare-address-for-ssh reasoning as ssh_setup.sh.
SSH_HOST="$ADDRESS"

# Only strips the exact line matching this plugin's own key (grep -vxF),
# leaving any other keys/lines in the remote's authorized_keys untouched.
# A missing file means there was nothing to remove in the first place.
REMOTE_CMD='umask 077; if [ -f ~/.ssh/authorized_keys ]; then KEY=$(cat); grep -vxF "$KEY" ~/.ssh/authorized_keys > ~/.ssh/authorized_keys.rbtmp 2>/dev/null; mv ~/.ssh/authorized_keys.rbtmp ~/.ssh/authorized_keys; fi; echo DONE'

# Tightly bounded and BatchMode=yes (no password fallback, no prompting) -
# this runs once per known remote during uninstall, so an unreachable
# remote needs to fail fast rather than stall the uninstall.
OUT=$(timeout --kill-after=3 10 ssh -i "$SSH_KEY" \
    -o StrictHostKeyChecking=accept-new -o ConnectTimeout=6 -o BatchMode=yes \
    -p "$SSH_PORT" "${SSH_USER}@${SSH_HOST}" "$REMOTE_CMD" < "${SSH_KEY}.pub" 2>&1)
RC=$?

if [ $RC -eq 0 ] && echo "$OUT" | grep -q "DONE"; then
    rb_log "ssh_remove_key: key removed (or already absent) on ${SSH_USER}@${ADDRESS}"
    echo '{"ok":true,"message":"SSH key removed from remote (or was already absent)."}'
else
    ESC=$(echo "$OUT" | tr '\n' ' ' | sed 's/"/\\"/g')
    rb_log "ssh_remove_key: could not reach/authenticate to ${SSH_USER}@${ADDRESS} (rc=${RC}): ${OUT}"
    echo "{\"ok\":false,\"message\":\"Could not remove key from ${ADDRESS} (rc=${RC}): ${ESC}\"}"
fi
