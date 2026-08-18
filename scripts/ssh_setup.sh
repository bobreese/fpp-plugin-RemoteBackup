#!/bin/bash
# Push the plugin's dedicated SSH public key to a remote FPP so that
# unattended rsync pulls can authenticate without a password.
#
# Usage: ssh_setup.sh <address> [sshUser] [sshPort] [password]
# If password is omitted, falls back to FPP's factory default (fpp/falcon).
# Prints JSON: {"ok":true|false,"message":"..."}
#
# Implemented directly over ssh/sshpass rather than ssh-copy-id: some
# ssh-copy-id builds mis-parse bare (unbracketed) IPv6 targets combined
# with an explicit -p port, and doing it ourselves also lets us fix the
# ~/.ssh permissions on the remote in the same pass - wrong permissions
# there make sshd silently ignore authorized_keys, which looks exactly
# like "the key push didn't work" from this end.

. "$(dirname "$0")/lib_common.sh"

ADDRESS="$1"
SSH_USER="${2:-$(rb_setting '.sshUser' 'fpp')}"
SSH_PORT="${3:-$(rb_setting '.sshPort' '22')}"
PASSWORD="${4:-falcon}"
SSH_KEY=$(rb_setting '.sshKeyPath' '/home/fpp/.ssh/id_rsa_remotebackup')

if [ -z "$ADDRESS" ]; then
    echo '{"ok":false,"message":"No address given"}'
    exit 0
fi
if [ ! -f "${SSH_KEY}.pub" ]; then
    echo '{"ok":false,"message":"No local SSH key found; re-run the plugin install script."}'
    exit 0
fi

if ! command -v sshpass >/dev/null 2>&1; then
    echo '{"ok":false,"message":"sshpass is not installed on the Host. Install it (sudo apt-get install -y sshpass) or manually copy '"${SSH_KEY}"'.pub to the remote'"'"'s ~/.ssh/authorized_keys."}'
    exit 0
fi

# Unlike rsync's user@host:path syntax (which genuinely needs brackets
# to tell an IPv6 host apart from the trailing :path), plain ssh takes
# the destination as one bare argument with the port given separately
# via -p, and does NOT strip brackets there - ssh user@[::1] fails with
# "Could not resolve hostname [::1]" because it resolves the brackets
# as literal characters. So: bare address for ssh, brackets only for rsync.
SSH_HOST="$ADDRESS"

REMOTE_CMD='umask 077; mkdir -p ~/.ssh; touch ~/.ssh/authorized_keys; chmod 700 ~/.ssh; chmod 600 ~/.ssh/authorized_keys; KEY=$(cat); grep -qxF "$KEY" ~/.ssh/authorized_keys 2>/dev/null || echo "$KEY" >> ~/.ssh/authorized_keys; echo DONE'

# A remote that's been reimaged/rebuilt keeps the same IP/hostname but
# gets brand new SSH host keys, so this Host's known_hosts still has the
# OLD one on file. That alone makes ssh refuse the connection outright
# ("REMOTE HOST IDENTIFICATION HAS CHANGED") regardless of password -
# exactly the case "Push SSH Key" (this script) exists to recover from,
# so clear any stale entry before connecting rather than making the user
# SSH in by hand to run ssh-keygen -R themselves.
rb_clear_stale_host_key "$ADDRESS" "$SSH_PORT"

# Bounded independently of whatever timeout the caller (ajax.php)
# applies to this whole script, and NumberOfPasswordPrompts=1 stops ssh
# from sitting there re-prompting (with nothing able to answer it) if
# sshpass's password doesn't match what the remote's prompt looks like.
OUT=$(timeout --kill-after=5 20 sshpass -p "$PASSWORD" ssh \
    -o StrictHostKeyChecking=accept-new -o ConnectTimeout=10 -o BatchMode=no \
    -o NumberOfPasswordPrompts=1 \
    -p "$SSH_PORT" "${SSH_USER}@${SSH_HOST}" "$REMOTE_CMD" < "${SSH_KEY}.pub" 2>&1)
RC=$?
if [ $RC -eq 124 ] || [ $RC -eq 137 ]; then
    rb_log "ssh_setup: TIMED OUT connecting/authenticating to ${SSH_USER}@${ADDRESS}"
    echo "{\"ok\":false,\"message\":\"Timed out connecting/authenticating to ${ADDRESS}. Check the address/port are correct and the remote is reachable.\"}"
    exit 0
fi

if [ $RC -eq 0 ] && echo "$OUT" | grep -q "DONE"; then
    rb_log "ssh_setup: key installed on ${SSH_USER}@${ADDRESS}"
    echo '{"ok":true,"message":"SSH key installed on remote (and ~/.ssh permissions fixed to 700/600)."}'
else
    ESC=$(echo "$OUT" | tr '\n' ' ' | sed 's/"/\\"/g')
    rb_log "ssh_setup FAILED for ${SSH_USER}@${ADDRESS} (rc=$RC): $OUT"
    echo "{\"ok\":false,\"message\":\"Key push failed (rc=${RC}): ${ESC}\"}"
fi
