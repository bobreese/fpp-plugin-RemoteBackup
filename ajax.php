<?php
// AJAX backend for the Remote Backup plugin.
// Reached at: plugin.php?plugin=fpp-plugin-RemoteBackup&page=ajax.php&nopage=1&action=...
// Always emits JSON and nothing else.
//
// IMPORTANT: FPP's own plugin.php always requires its www/config.php
// before including this file (even with nopage=1), and that file (or
// PHP notices/warnings) can print stray whitespace/output ahead of
// ours. We discard any such output below so it never corrupts the
// JSON response the browser is trying to parse.
while (ob_get_level() > 0) { @ob_end_clean(); }
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', '0'); // never let PHP notices/warnings leak into the JSON body

$PLUGIN_DIR = __DIR__;
$DATA_DIR = "$PLUGIN_DIR/data";
$SCRIPTS_DIR = "$PLUGIN_DIR/scripts";
$SETTINGS_FILE = "$DATA_DIR/settings.json";
$STATUS_DIR = "$DATA_DIR/status";
$LOG_DIR = "$DATA_DIR/logs";
$AJAX_LOG = "$LOG_DIR/ajax.log";

@mkdir($DATA_DIR, 0777, true);
@mkdir($STATUS_DIR, 0777, true);
@mkdir($LOG_DIR, 0777, true);
@chmod($DATA_DIR, 0777);
@chmod($STATUS_DIR, 0777);
@chmod($LOG_DIR, 0777);

function rb_log_line($msg) {
    global $AJAX_LOG;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    @file_put_contents($AJAX_LOG, $line, FILE_APPEND | LOCK_EX);
}

// Catch fatal errors / uncaught exceptions and still return valid JSON
// instead of letting PHP dump an HTML error page into the response body.
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        rb_log_line('FATAL: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        while (ob_get_level() > 0) { @ob_end_clean(); }
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code(500);
        }
        echo json_encode(['ok' => false, 'error' => 'Internal error - see data/logs/ajax.log for details']);
    }
});

header('Content-Type: application/json');

function rb_fail($msg, $code = 400) {
    rb_log_line("FAIL ($code): $msg");
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function rb_json_body() {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $d = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        rb_log_line('WARN: failed to decode POST body as JSON: ' . json_last_error_msg() . ' raw=' . substr($raw, 0, 300));
    }
    return is_array($d) ? $d : [];
}

// Run a shell command with a hard timeout, capturing stdout separately
// from stderr so JSON-producing scripts can't get corrupted by stray
// warnings, while still logging exactly what happened for diagnostics.
function rb_run($scriptPath, $args = [], $timeoutSec = 20) {
    global $LOG_DIR;
    $cmd = 'timeout --kill-after=5 ' . intval($timeoutSec) . ' ' . escapeshellcmd($scriptPath);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }

    // Use proc_open so we can capture stdout, stderr, and the exit code reliably.
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];
    $process = proc_open($cmd, $descriptors, $pipes);
    $out = '';
    $stderr = '';
    $return_value = null;
    if (is_resource($process)) {
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $return_value = proc_close($process);
    } else {
        $stderr = 'proc_open failed';
        $return_value = 255;
    }

    if ($out === null || trim((string)$out) === '') {
        rb_log_line("RUN EMPTY OUTPUT cmd=$cmd stderr=" . substr((string)$stderr, 0, 500));
    } elseif (!empty($stderr)) {
        rb_log_line("RUN cmd=$cmd (rc=$return_value) stderr=" . substr($stderr, 0, 500));
    } else {
        rb_log_line("RUN OK cmd=$cmd rc=$return_value");
    }
    return $out;
}

function rb_run_json($scriptPath, $args = [], $timeoutSec = 20) {
    $out = rb_run($scriptPath, $args, $timeoutSec);
    $data = json_decode((string)$out, true);
    if ($data === null) {
        rb_log_line('WARN: non-JSON output from ' . $scriptPath . ': ' . substr((string)$out, 0, 500));
    }
    return $data;
}

// Reads a run_active.json-shaped {"active":true/false,...} file and
// returns whether it's currently active. Shared by every action that
// needs to check (or enforce) mutual exclusion between a primary backup
// run, a primary-drive format/unmount, and a backup-set clone to a
// second drive - all of which read and/or write the primary
// destination and must never overlap.
function rb_is_active($file) {
    $raw = @file_get_contents($file);
    $data = $raw ? json_decode($raw, true) : null;
    return $data && !empty($data['active']);
}

// True if $path is currently an active mountpoint - reads /proc/mounts
// directly rather than shelling out to the `mountpoint` command, since
// this is just a quick pre-flight check (e.g. startClone below) and not
// worth a process spawn for. is_dir() alone isn't enough here: an
// unmounted-but-still-present empty directory would pass it too.
function rb_is_mounted($path) {
    $mounts = @file('/proc/mounts');
    if (!$mounts) return false;
    foreach ($mounts as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if (isset($parts[1]) && $parts[1] === $path) return true;
    }
    return false;
}

// Sanitizes a user-supplied filesystem volume label before it ever
// reaches a shell command: strips anything but alphanumerics/space/
// hyphen/underscore (mkfs.exfat/mkfs.ext4 both reject or mangle some
// punctuation anyway, and this keeps it simple rather than trying to
// allow-list per-filesystem quirks), then truncates to 11 chars - the
// more restrictive of the two filesystems this plugin supports (legacy
// FAT/exFAT volume label limit; ext4 allows up to 16). format_usb.sh
// re-truncates too in case it's ever invoked directly, so this is
// defense in depth, not the only place it's enforced. Falls back to
// "Backups" for an empty/all-invalid-characters input.
function rb_sanitize_label($label) {
    $label = preg_replace('/[^A-Za-z0-9 _-]/', '', (string)$label);
    $label = trim(substr($label, 0, 11));
    return $label !== '' ? $label : 'Backups';
}

// status/cloneStatus (and therefore rb_volume_label below) get polled
// every 2-7s for as long as the Status page is left open - a volume label
// never changes on its own between polls (only a reformat changes it, and
// that already re-seeds this cache directly via rb_cache_volume_label
// below), so re-shelling out to findmnt that often was pure wasted
// fork/exec overhead. This file is the cache: {mountpoint: {label,
// checkedAt}}.
$RB_LABEL_CACHE_FILE = "$DATA_DIR/label_cache.json";
$RB_LABEL_CACHE_TTL = 30; // seconds

function rb_label_cache_read() {
    global $RB_LABEL_CACHE_FILE;
    if (!file_exists($RB_LABEL_CACHE_FILE)) return [];
    $raw = @file_get_contents($RB_LABEL_CACHE_FILE);
    $decoded = $raw ? json_decode($raw, true) : null;
    return is_array($decoded) ? $decoded : [];
}

function rb_label_cache_write($cache) {
    global $RB_LABEL_CACHE_FILE;
    @file_put_contents($RB_LABEL_CACHE_FILE, json_encode($cache), LOCK_EX);
}

// Directly seeds the cache with an already-known-fresh label (e.g. right
// after formatUsb/formatSecondary applies one) - cheaper and more
// immediately accurate than just invalidating and waiting for the next
// poll to re-shell out.
function rb_cache_volume_label($mountpoint, $label) {
    $cache = rb_label_cache_read();
    $cache[$mountpoint] = ['label' => ($label !== '' ? $label : null), 'checkedAt' => time()];
    rb_label_cache_write($cache);
}

// Looks up a mounted filesystem's volume label by mountpoint, or null
// if it doesn't have one (or isn't mounted) - findmnt's LABEL column
// pulls this from blkid without needing to separately resolve the
// underlying device first. Cached per mountpoint (see above) so repeated
// polling doesn't re-shell out every single time.
function rb_volume_label($mountpoint) {
    global $RB_LABEL_CACHE_TTL;
    $cache = rb_label_cache_read();
    if (isset($cache[$mountpoint]) && (time() - $cache[$mountpoint]['checkedAt']) < $RB_LABEL_CACHE_TTL) {
        return $cache[$mountpoint]['label'];
    }
    $out = @shell_exec('findmnt -no LABEL ' . escapeshellarg($mountpoint) . ' 2>/dev/null');
    $label = trim((string)$out);
    $label = $label !== '' ? $label : null;
    $cache[$mountpoint] = ['label' => $label, 'checkedAt' => time()];
    rb_label_cache_write($cache);
    return $label;
}

// Resolves a Diagnostic Log dropdown value ("ajax", "engine", "clone",
// "remote:<id>") to the actual log file path on disk - shared by getLog
// (view) and downloadLog (download) so the two can never disagree about
// which file a given selection means.
function rb_resolve_log_file($which) {
    global $LOG_DIR, $AJAX_LOG;
    if (strpos($which, 'remote:') === 0) {
        $rid = rb_slugify(substr($which, 7));
        $matches = glob("$LOG_DIR/{$rid}-*.log");
        if ($matches) {
            usort($matches, function ($a, $b) { return filemtime($b) - filemtime($a); });
            return $matches[0];
        }
        return "$LOG_DIR/{$rid}-(no log yet).log";
    } elseif ($which === 'engine') {
        return "$LOG_DIR/engine.log";
    } elseif ($which === 'clone') {
        $matches = glob("$LOG_DIR/clone-*.log");
        if ($matches) {
            usort($matches, function ($a, $b) { return filemtime($b) - filemtime($a); });
            return $matches[0];
        }
        return "$LOG_DIR/clone-(no log yet).log";
    }
    return $AJAX_LOG;
}

function rb_default_settings() {
    return [
        'hostModeEnabled' => false,
        'destinationMount' => '',
        'destinationLabel' => '',
        'maxConcurrent' => 2,
        'logRetentionCount' => 15,
        'deleteExtraneous' => false,
        'snapshotMode' => false,
        'sshUser' => 'fpp',
        'sshPort' => 22,
        'sshPassword' => null,
        'sshKeyPath' => '/home/fpp/.ssh/id_rsa_remotebackup',
        // NOTE: logs were previously excluded by default here (Logs/*,
        // logs/*) - removed since a backup that silently drops FPP's
        // logs isn't a very useful backup. Existing installs that saved
        // settings.json before this change still have those two entries
        // baked in and need to remove them from Config > Excludes by hand -
        // this default is only consulted for a *new* settings.json.
        'excludes' => ['tmp/*', 'upload/*', 'cache/*', '*.tmp'],
        'includeSystemConfig' => true,
        'remotes' => []
    ];
}

function rb_load_settings($SETTINGS_FILE) {
    if (!file_exists($SETTINGS_FILE)) return rb_default_settings();
    $raw = @file_get_contents($SETTINGS_FILE);
    $d = json_decode((string)$raw, true);
    if (!is_array($d)) {
        rb_log_line("WARN: settings.json unreadable or invalid, using defaults. raw=" . substr((string)$raw, 0, 300));
        return rb_default_settings();
    }
    return array_merge(rb_default_settings(), $d);
}

// Returns true/false so callers can report real failures back to the UI
// instead of silently pretending the save succeeded.
function rb_save_settings($SETTINGS_FILE, $settings) {
    $json = json_encode($settings, JSON_PRETTY_PRINT);
    $tmp = $SETTINGS_FILE . '.tmp';
    $ok = @file_put_contents($tmp, $json);
    if ($ok === false) {
        $err = error_get_last();
        rb_log_line('SAVE FAILED writing ' . $tmp . ': ' . ($err['message'] ?? 'unknown error'));
        return false;
    }
    if (!@rename($tmp, $SETTINGS_FILE)) {
        $err = error_get_last();
        rb_log_line('SAVE FAILED renaming ' . $tmp . ' -> ' . $SETTINGS_FILE . ': ' . ($err['message'] ?? 'unknown error'));
        return false;
    }
    @chmod($SETTINGS_FILE, 0666);
    rb_log_line("SAVE OK to $SETTINGS_FILE (" . strlen($json) . ' bytes)');
    return true;
}

function rb_slugify($s) {
    $s = preg_replace('/[^A-Za-z0-9._-]+/', '_', $s);
    return trim($s, '_');
}

$action = isset($_GET['action']) ? $_GET['action'] : '';
$method = $_SERVER['REQUEST_METHOD'];
// status/cloneStatus/getLog are routine polls (every 2-7s while the Status
// page is open, indefinitely, for as long as it's left open) - logging
// every single one here drowned out everything actually worth reading in
// ajax.log with pure heartbeat noise. Everything else (saves, formats,
// mounts, starts, deletes, downloads, ...) still gets logged, since those
// are the events actually worth an audit trail.
$QUIET_ACTIONS = ['status', 'cloneStatus', 'getLog'];
if (!in_array($action, $QUIET_ACTIONS, true)) {
    // The client's actual IP, not the Host's own hostname - php_uname('n')
    // here previously returned the SAME value (this Host's own hostname)
    // on every single request regardless of who/what called it, which
    // looked like caller identity but never actually was any.
    $client = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
    rb_log_line("REQUEST action=$action method=$method client=$client");
}

switch ($action) {

    case 'probeStorage': {
        $data = rb_run_json("$SCRIPTS_DIR/probe_storage.sh", [], 15);
        if (!$data) rb_fail('Could not probe storage devices - see data/logs/ajax.log');
        echo json_encode(['ok' => true, 'data' => $data]);
        break;
    }

    case 'probeRemotes': {
        $data = rb_run_json("$SCRIPTS_DIR/probe_remotes.sh", [], 15);
        if (!$data) $data = ['remotes' => [], 'apiOk' => false];
        echo json_encode(['ok' => true, 'data' => $data]);
        break;
    }

    case 'hostInfo': {
        $data = rb_run_json("$SCRIPTS_DIR/host_info.sh", [], 10);
        if (!$data) $data = ['hostname' => '', 'addresses' => []];
        echo json_encode(['ok' => true, 'data' => $data]);
        break;
    }

    case 'mountUsb': {
        if ($method !== 'POST') rb_fail('POST required');
        $body = rb_json_body();
        $device = isset($body['device']) ? $body['device'] : '';
        if (!$device || substr($device, 0, 5) !== '/dev/') rb_fail('Invalid device path');

        $out = rb_run("$SCRIPTS_DIR/mount_usb.sh", [$device], 25);
        $data = json_decode((string)$out, true);
        if (!$data) $data = ['ok' => false, 'error' => 'No response from mount_usb.sh - see data/logs/ajax.log'];
        echo json_encode($data);
        break;
    }

    case 'unmountUsb': {
        if ($method !== 'POST') rb_fail('POST required');

        if (rb_is_active("$DATA_DIR/run_active.json")) {
            rb_fail('A backup run is currently in progress. Stop it (or wait for it to finish) before unmounting the destination drive.', 409);
        }
        if (rb_is_active("$DATA_DIR/clone_active.json")) {
            rb_fail('A backup clone to the secondary drive is currently in progress (it reads from this drive). Wait for it to finish before unmounting.', 409);
        }

        rb_log_line("UNMOUNT requested");
        $out = rb_run("$SCRIPTS_DIR/unmount_usb.sh", [], 20);
        $data = json_decode((string)$out, true);
        if (!$data) $data = ['ok' => false, 'error' => 'No response from unmount_usb.sh - see data/logs/ajax.log'];
        echo json_encode($data);
        break;
    }

    case 'formatUsb': {
        if ($method !== 'POST') rb_fail('POST required');
        $body = rb_json_body();
        $device = isset($body['device']) ? $body['device'] : '';
        $fstype = isset($body['fstype']) ? $body['fstype'] : 'ext4';
        $confirm = isset($body['confirm']) ? $body['confirm'] : '';
        $label = rb_sanitize_label(isset($body['label']) ? $body['label'] : '');
        if (!$device || substr($device, 0, 5) !== '/dev/') rb_fail('Invalid device path');
        if ($confirm !== 'I_UNDERSTAND_THIS_ERASES_THE_DRIVE') rb_fail('Missing confirmation');

        // Formatting wipes the destination out from under any in-progress
        // (or about-to-start) backup run, which surfaces as a confusing
        // "rsync mkdir failed" error on every remote at once. Share the
        // same run_active.json lock that 'start' uses so the two can
        // never overlap - and also refuse if a clone to the secondary
        // drive is reading from this destination right now.
        if (rb_is_active("$DATA_DIR/run_active.json")) {
            rb_fail('A backup run is currently in progress. Stop it (or wait for it to finish) before formatting the destination drive.', 409);
        }
        if (rb_is_active("$DATA_DIR/clone_active.json")) {
            rb_fail('A backup clone to the secondary drive is currently in progress (it reads from this drive). Wait for it to finish before formatting.', 409);
        }
        file_put_contents("$DATA_DIR/run_active.json", json_encode(['active' => true, 'action' => 'format']));

        rb_log_line("FORMAT requested device=$device fstype=$fstype label=$label");
        $out = rb_run("$SCRIPTS_DIR/format_usb.sh", [$device, $fstype, $confirm, '/mnt/Backups', $label], 90);
        $data = json_decode((string)$out, true);
        if (!$data) $data = ['ok' => false, 'error' => 'No response from format_usb.sh - see data/logs/ajax.log'];
        if (!empty($data['ok']) && isset($data['label'])) {
            rb_cache_volume_label('/mnt/Backups', $data['label']);
        }

        file_put_contents("$DATA_DIR/run_active.json", json_encode(['active' => false]));
        echo json_encode($data);
        break;
    }

    case 'mountSecondary': {
        if ($method !== 'POST') rb_fail('POST required');
        $body = rb_json_body();
        $device = isset($body['device']) ? $body['device'] : '';
        if (!$device || substr($device, 0, 5) !== '/dev/') rb_fail('Invalid device path');

        $out = rb_run("$SCRIPTS_DIR/mount_usb.sh", [$device, '', '/mnt/BackupsCopy'], 25);
        $data = json_decode((string)$out, true);
        if (!$data) $data = ['ok' => false, 'error' => 'No response from mount_usb.sh - see data/logs/ajax.log'];
        echo json_encode($data);
        break;
    }

    case 'unmountSecondary': {
        if ($method !== 'POST') rb_fail('POST required');

        if (rb_is_active("$DATA_DIR/clone_active.json")) {
            rb_fail('A backup clone to this drive is currently in progress. Stop it (or wait for it to finish) before unmounting.', 409);
        }

        rb_log_line("UNMOUNT SECONDARY requested");
        $out = rb_run("$SCRIPTS_DIR/unmount_usb.sh", ['/mnt/BackupsCopy'], 20);
        $data = json_decode((string)$out, true);
        if (!$data) $data = ['ok' => false, 'error' => 'No response from unmount_usb.sh - see data/logs/ajax.log'];
        echo json_encode($data);
        break;
    }

    case 'formatSecondary': {
        if ($method !== 'POST') rb_fail('POST required');
        $body = rb_json_body();
        $device = isset($body['device']) ? $body['device'] : '';
        $fstype = isset($body['fstype']) ? $body['fstype'] : 'ext4';
        $confirm = isset($body['confirm']) ? $body['confirm'] : '';
        $label = rb_sanitize_label(isset($body['label']) ? $body['label'] : '');
        if (!$device || substr($device, 0, 5) !== '/dev/') rb_fail('Invalid device path');
        if ($confirm !== 'I_UNDERSTAND_THIS_ERASES_THE_DRIVE') rb_fail('Missing confirmation');

        if (rb_is_active("$DATA_DIR/clone_active.json")) {
            rb_fail('A backup clone to this drive is currently in progress. Wait for it to finish before formatting.', 409);
        }
        file_put_contents("$DATA_DIR/clone_active.json", json_encode(['active' => true, 'action' => 'format-secondary']));

        rb_log_line("FORMAT SECONDARY requested device=$device fstype=$fstype label=$label");
        $out = rb_run("$SCRIPTS_DIR/format_usb.sh", [$device, $fstype, $confirm, '/mnt/BackupsCopy', $label], 90);
        $data = json_decode((string)$out, true);
        if (!$data) $data = ['ok' => false, 'error' => 'No response from format_usb.sh - see data/logs/ajax.log'];
        if (!empty($data['ok']) && isset($data['label'])) {
            rb_cache_volume_label('/mnt/BackupsCopy', $data['label']);
        }

        file_put_contents("$DATA_DIR/clone_active.json", json_encode(['active' => false]));
        echo json_encode($data);
        break;
    }

    case 'listBackups': {
        $data = rb_run_json("$SCRIPTS_DIR/list_backups.sh", [], 20);
        if (!$data) $data = ['ok' => false, 'error' => 'No response from list_backups.sh - see data/logs/ajax.log'];
        echo json_encode($data);
        break;
    }

    case 'getBackupInfo': {
        $p = isset($_GET['path']) ? $_GET['path'] : '';
        if (!$p) rb_fail('path required');
        $data = rb_run_json("$SCRIPTS_DIR/get_backup_info.sh", [$p], 30);
        if (!$data) $data = ['ok' => false, 'error' => 'No response from get_backup_info.sh - see data/logs/ajax.log'];
        echo json_encode($data);
        break;
    }

    case 'deleteBackup': {
        if ($method !== 'POST') rb_fail('POST required');
        $body = rb_json_body();
        $p = isset($body['path']) ? $body['path'] : '';
        $confirm = isset($body['confirm']) ? $body['confirm'] : '';
        if (!$p) rb_fail('path required');
        if ($confirm !== 'I_UNDERSTAND_THIS_DELETES_THE_BACKUP') rb_fail('Missing confirmation');

        rb_log_line("DELETE BACKUP requested path=$p");
        $out = rb_run("$SCRIPTS_DIR/delete_backup.sh", [$p, $confirm], 60);
        $data = json_decode((string)$out, true);
        if (!$data) $data = ['ok' => false, 'error' => 'No response from delete_backup.sh - see data/logs/ajax.log'];
        echo json_encode($data);
        break;
    }

    case 'loadSettings': {
        echo json_encode(['ok' => true, 'data' => rb_load_settings($SETTINGS_FILE)]);
        break;
    }

    case 'saveSettings': {
        if ($method !== 'POST') rb_fail('POST required');
        $body = rb_json_body();
        $settings = rb_load_settings($SETTINGS_FILE);

        foreach (['hostModeEnabled', 'deleteExtraneous', 'snapshotMode', 'includeSystemConfig'] as $k) {
            if (isset($body[$k])) $settings[$k] = (bool)$body[$k];
        }
        foreach (['destinationMount', 'destinationLabel', 'sshUser', 'sshKeyPath', 'sshPassword'] as $k) {
            if (!isset($body[$k])) continue;
            // Treat an empty sshPassword as "unset" so the system default
            // (rb_default_settings' 'falcon') remains in effect unless a
            // non-empty password is explicitly saved by the user.
            if ($k === 'sshPassword') {
                if ($body[$k] === '') {
                    if (isset($settings['sshPassword'])) unset($settings['sshPassword']);
                } else {
                    $settings['sshPassword'] = (string)$body[$k];
                }
            } else {
                $settings[$k] = (string)$body[$k];
            }
        }
        foreach (['maxConcurrent', 'sshPort'] as $k) {
            if (isset($body[$k])) $settings[$k] = (int)$body[$k];
        }
        if (isset($body['logRetentionCount'])) {
            // Clamped rather than trusted outright - this feeds straight into
            // a shell loop counter in prune_logs.sh/rb_prune_remote_logs, and
            // 0 or negative would prune every single log, including the one
            // from the run currently writing it.
            $settings['logRetentionCount'] = max(1, min(500, (int)$body['logRetentionCount']));
        }
        if (isset($body['excludes']) && is_array($body['excludes'])) {
            $settings['excludes'] = array_values($body['excludes']);
        }
        if (isset($body['remotes']) && is_array($body['remotes'])) {
            // Each remote: {id, hostname, address, selected}
            $clean = [];
            foreach ($body['remotes'] as $r) {
                if (!isset($r['hostname']) || !isset($r['address'])) continue;
                $id = isset($r['id']) && $r['id'] !== '' ? rb_slugify($r['id']) : rb_slugify($r['hostname']);
                $clean[] = [
                    'id' => $id,
                    'hostname' => $r['hostname'],
                    'address' => $r['address'],
                    'selected' => isset($r['selected']) ? (bool)$r['selected'] : false,
                    'source' => isset($r['source']) ? $r['source'] : 'manual',
                    'lastSeenAt' => isset($r['lastSeenAt']) ? $r['lastSeenAt'] : null
                ];
            }
            $settings['remotes'] = $clean;
        }

        if (!rb_save_settings($SETTINGS_FILE, $settings)) {
            rb_fail('Could not write settings.json - check that ' . dirname($SETTINGS_FILE) . ' is writable by the web server user. See data/logs/ajax.log.', 500);
        }

        // Applies the (possibly just-changed) logRetentionCount to every
        // remote's existing run logs right away, rather than leaving old
        // ones sitting there until each remote's next run happens to prune
        // its own. Best-effort - a pruning hiccup here should never fail
        // the settings save itself.
        rb_run("$SCRIPTS_DIR/prune_logs.sh", [], 15);

        echo json_encode(['ok' => true, 'data' => $settings]);
        break;
    }

    case 'pushSshKey': {
        if ($method !== 'POST') rb_fail('POST required');
        $body = rb_json_body();
        $address = isset($body['address']) ? $body['address'] : '';
        $user = isset($body['sshUser']) ? $body['sshUser'] : 'fpp';
        $port = isset($body['sshPort']) ? intval($body['sshPort']) : 22;
        $settingsForDefault = rb_load_settings($SETTINGS_FILE);
        // Password precedence: explicit POSTed password (if non-empty),
        // then plugin-wide stored password (if set and non-empty), then
        // finally the FPP factory default 'falcon'. Treat null/empty as
        // unset.
        $password = null;
        if (isset($body['password']) && $body['password'] !== '') {
            $password = $body['password'];
        } elseif (!empty($settingsForDefault['sshPassword'])) {
            $password = $settingsForDefault['sshPassword'];
        } else {
            $password = 'falcon';
        }
        if (!$address) rb_fail('address required');

        $out = rb_run("$SCRIPTS_DIR/ssh_setup.sh", [$address, $user, (string)$port, $password], 20);
        $data = json_decode((string)$out, true);
        if (!$data) $data = ['ok' => false, 'message' => 'No response from ssh_setup.sh - see data/logs/ajax.log'];
        echo json_encode($data);
        break;
    }

    case 'start': {
        if ($method !== 'POST') rb_fail('POST required');
        $body = rb_json_body();
        $dryRun = isset($body['dryRun']) && $body['dryRun'];
        $ids = isset($body['remotes']) && is_array($body['remotes']) ? $body['remotes'] : [];

        if (rb_is_active("$DATA_DIR/run_active.json")) {
            rb_fail('A backup run is already in progress', 409);
        }
        if (rb_is_active("$DATA_DIR/clone_active.json")) {
            rb_fail('A backup clone to the secondary drive is currently in progress (it reads from this destination). Wait for it to finish before starting a backup.', 409);
        }

        // Pulling media off a device's SD card while fppd is actively
        // reading those same files for playback risks stutters/dropped
        // frames during a live show, so refuse the WHOLE run (not just the
        // busy remote) if any selected remote is currently playing a
        // sequence. This is a synchronous pre-check purely for immediate
        // UI feedback - run_backup.sh performs the same check itself and
        // is the guard that actually matters, since it also covers
        // Scheduler-triggered and manual/cron runs that never go through
        // this endpoint at all.
        $playCheckArgs = empty($ids) ? [] : ['--remotes', implode(',', array_map('rb_slugify', $ids))];
        $playCheck = rb_run_json("$SCRIPTS_DIR/check_remotes_playing.sh", $playCheckArgs, 20);
        if ($playCheck && !empty($playCheck['playing'])) {
            $names = array_map(function ($r) { return $r['hostname']; }, $playCheck['playing']);
            rb_fail('Refusing to start: currently playing a sequence - ' . implode(', ', $names), 409);
        }

        // Clear stale per-remote status files from any previous run so
        // the UI table doesn't show leftovers from unrelated remotes.
        foreach (glob("$STATUS_DIR/*.json") as $f) { @unlink($f); }

        $args = $dryRun ? ' --dry-run' : '';
        if (!empty($ids)) {
            $safeIds = array_map('rb_slugify', $ids);
            $args .= ' --remotes ' . escapeshellarg(implode(',', $safeIds));
        }
        $cmd = escapeshellcmd("$SCRIPTS_DIR/run_backup.sh") . $args . ' > ' .
            escapeshellarg("$LOG_DIR/last_start.log") . ' 2>&1 &';
        rb_log_line("START cmd=$cmd");
        shell_exec($cmd);

        echo json_encode(['ok' => true, 'started' => true, 'dryRun' => $dryRun]);
        break;
    }

    case 'stop': {
        if ($method !== 'POST') rb_fail('POST required');
        $pidDir = "$DATA_DIR/pids";
        $killed = [];
        foreach (glob("$pidDir/*.pid") as $pf) {
            $pid = trim(@file_get_contents($pf));
            if ($pid && ctype_digit($pid)) {
                if (function_exists('posix_kill')) {
                    @posix_kill(intval($pid), 15);
                } else {
                    shell_exec('kill ' . escapeshellarg($pid) . ' 2>/dev/null');
                }
                $killed[] = basename($pf, '.pid');
            }
            @unlink($pf);
        }
        file_put_contents("$DATA_DIR/run_active.json", json_encode(['active' => false]));
        rb_log_line('STOP killed=' . implode(',', $killed));
        echo json_encode(['ok' => true, 'stopped' => $killed]);
        break;
    }

    case 'startClone': {
        if ($method !== 'POST') rb_fail('POST required');

        if (rb_is_active("$DATA_DIR/run_active.json")) {
            rb_fail('A backup run (or drive format) is currently in progress. Wait for it to finish before cloning backups.', 409);
        }
        if (rb_is_active("$DATA_DIR/clone_active.json")) {
            rb_fail('A backup clone is already in progress', 409);
        }
        // clone_backups.sh already refuses internally if the secondary
        // drive isn't mounted, but that check only happens after this
        // request has already returned {ok:true,started:true} (it runs in
        // the background) - the Status page briefly showed "Clone
        // started." before the next poll caught up to the real error a
        // couple seconds later. Checking here instead means an unmounted
        // drive fails this request outright, with an accurate response.
        if (!rb_is_mounted('/mnt/BackupsCopy')) {
            rb_fail('Secondary drive is not mounted at /mnt/BackupsCopy - format/mount it on the Config page first.', 409);
        }

        rb_log_line("START CLONE requested");
        $cmd = escapeshellcmd("$SCRIPTS_DIR/clone_backups.sh") . ' > ' .
            escapeshellarg("$LOG_DIR/last_clone_start.log") . ' 2>&1 &';
        shell_exec($cmd);

        echo json_encode(['ok' => true, 'started' => true]);
        break;
    }

    case 'stopClone': {
        if ($method !== 'POST') rb_fail('POST required');
        $pidFile = "$DATA_DIR/clone.pid";
        $pid = trim((string)@file_get_contents($pidFile));
        $killed = false;
        if ($pid && ctype_digit($pid)) {
            if (function_exists('posix_kill')) {
                @posix_kill(intval($pid), 15);
            } else {
                shell_exec('kill ' . escapeshellarg($pid) . ' 2>/dev/null');
            }
            $killed = true;
        }
        @unlink($pidFile);
        file_put_contents("$DATA_DIR/clone_active.json", json_encode(['active' => false]));
        rb_log_line('STOP CLONE killed=' . ($killed ? 'yes' : 'no (not running)'));
        echo json_encode(['ok' => true, 'stopped' => $killed]);
        break;
    }

    case 'cloneStatus': {
        $raw = @file_get_contents("$DATA_DIR/clone_status.json");
        $clone = $raw ? json_decode($raw, true) : null;

        // Free/used space on the secondary drive, same shape as
        // 'status''s destStorage, so the Status page can show it whether
        // or not a clone has ever actually run yet. rb_is_mounted(), not
        // is_dir() - mount_usb.sh's `mkdir -p` creates /mnt/BackupsCopy
        // before it ever mounts anything there, and unmounting leaves the
        // now-empty directory behind (by design - see unmount_usb.sh), so
        // is_dir() alone stays true long after the drive is gone and would
        // report the ROOT filesystem's free space as if it were the
        // drive's, instead of correctly showing "not mounted."
        $secondaryStorage = null;
        if (rb_is_mounted('/mnt/BackupsCopy')) {
            $dfFree = @disk_free_space('/mnt/BackupsCopy');
            $dfTotal = @disk_total_space('/mnt/BackupsCopy');
            if ($dfFree !== false && $dfTotal !== false) {
                $secondaryStorage = [
                    'mountpoint' => '/mnt/BackupsCopy',
                    // Deliberately NOT intval()'d - disk_free_space()/disk_total_space()
                    // return float specifically so a drive's real size survives on a
                    // 32-bit PHP build (native int there tops out around 2.1GB); intval()
                    // truncated/overflowed that for any real backup drive, producing
                    // garbage (even negative) free-space figures on 32-bit systems like a
                    // stock Pi3 image. json_encode()/JS handle these floats natively -
                    // safe well past any real drive size (doubles are exact up to 2^53
                    // bytes, ~9000 TB).
                    'totalBytes' => $dfTotal,
                    'freeBytes' => $dfFree,
                    'usedBytes' => $dfTotal - $dfFree,
                    'label' => rb_volume_label('/mnt/BackupsCopy')
                ];
            }
        }

        echo json_encode(['ok' => true, 'active' => rb_is_active("$DATA_DIR/clone_active.json"), 'clone' => $clone, 'secondaryStorage' => $secondaryStorage]);
        break;
    }

    case 'status': {
        $remotes = [];
        foreach (glob("$STATUS_DIR/*.json") as $f) {
            $d = json_decode(file_get_contents($f), true);
            if ($d) $remotes[] = $d;
        }
        $active = @file_get_contents("$DATA_DIR/run_active.json");
        $activeData = $active ? json_decode($active, true) : ['active' => false];

        $settings = rb_load_settings($SETTINGS_FILE);

        // Host destination storage used/free/total - shown in the Status
        // page header on every poll, independent of whether a run is
        // active or a dry run was ever performed. rb_is_mounted(), not
        // is_dir() - same reasoning as cloneStatus's secondaryStorage
        // below: an unmounted destination's directory can still exist on
        // disk (e.g. the SD-card fallback's dedicated folder, or a USB
        // mountpoint left behind after unmounting), and is_dir() alone
        // would then report the wrong filesystem's free space as the
        // destination's. The "/" (SD Card/System Storage fallback) case
        // is exempt, same as run_backup.sh's own rb_dest_mounted() - "/"
        // is always mounted by definition, there's nothing to check.
        $destStorage = null;
        if (!empty($settings['destinationMount']) && ($settings['destinationMount'] === '/' || rb_is_mounted($settings['destinationMount']))) {
            $dfFree = @disk_free_space($settings['destinationMount']);
            $dfTotal = @disk_total_space($settings['destinationMount']);
            if ($dfFree !== false && $dfTotal !== false) {
                $destStorage = [
                    'mountpoint' => $settings['destinationMount'],
                    // See the matching comment on secondaryStorage above - not intval()'d
                    // on purpose, to avoid 32-bit int overflow on a >~2GB drive.
                    'totalBytes' => $dfTotal,
                    'freeBytes' => $dfFree,
                    'usedBytes' => $dfTotal - $dfFree,
                    'label' => rb_volume_label($settings['destinationMount'])
                ];
            }
        }

        // If the most recent run was a dry run, build a space-comparison summary.
        $summary = null;
        $dryRemotes = array_filter($remotes, function ($r) { return !empty($r['dryRun']); });
        if (count($dryRemotes) > 0) {
            $estTotal = 0;
            foreach ($dryRemotes as $r) {
                $estTotal += isset($r['transferredBytes']) ? intval($r['transferredBytes']) : 0;
            }
            $avail = $destStorage ? $destStorage['freeBytes'] : null;
            $summary = [
                'estimatedTotalBytes' => $estTotal,
                'availableBytes' => $avail,
                'sufficient' => ($avail === null) ? null : ($avail > $estTotal)
            ];
        }

        echo json_encode(['ok' => true, 'active' => !empty($activeData['active']), 'remotes' => $remotes, 'dryRunSummary' => $summary, 'destStorage' => $destStorage]);
        break;
    }

    case 'getLog': {
        $which = isset($_GET['which']) ? $_GET['which'] : 'ajax';
        $file = rb_resolve_log_file($which);
        // 200 lines was nowhere near enough for a real rsync run log: -v
        // (one line per file) plus --info=progress2 (a fresh line per
        // progress update, since there's no TTY to redraw over) routinely
        // produces thousands of lines well before a transfer finishes, so
        // the Diagnostic Log was silently showing only the tail end of the
        // CURRENT run and never the start - "does not show entire current
        // log". Raised well past what a typical single-remote run log
        // needs, and the response now says plainly when it's still had to
        // truncate, instead of silently cutting content with no indication.
        $lines = 5000;
        $content = '';
        $totalLines = 0;
        $truncated = false;
        if (file_exists($file)) {
            $all = @file($file);
            if ($all) {
                $totalLines = count($all);
                if ($totalLines > $lines) {
                    $truncated = true;
                    $content = implode('', array_slice($all, -$lines));
                } else {
                    $content = implode('', $all);
                }
            }
        } else {
            $content = '(log file does not exist yet: ' . $file . ')';
        }
        echo json_encode(['ok' => true, 'file' => $file, 'content' => $content, 'truncated' => $truncated, 'totalLines' => $totalLines, 'shownLines' => $lines]);
        break;
    }

    case 'downloadLog': {
        $which = isset($_GET['which']) ? $_GET['which'] : 'ajax';
        $file = rb_resolve_log_file($which);
        if (!file_exists($file)) rb_fail('Log file does not exist yet: ' . $file, 404);

        rb_log_line("DOWNLOAD LOG which=$which file=$file");
        // Same JSON-by-default header set at the top of this file has to be
        // overridden here - PHP allows replacing a header value as long as
        // nothing's been sent yet, which ob_start() above guarantees.
        while (ob_get_level() > 0) { @ob_end_clean(); }
        $downloadName = 'RemoteBackup-' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $which) . '-' . basename($file);
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

    case 'downloadAllLogs': {
        rb_log_line('DOWNLOAD ALL LOGS requested');
        $out = rb_run("$SCRIPTS_DIR/zip_logs.sh", [], 30);
        $data = json_decode((string)$out, true);
        if (!$data || empty($data['ok'])) {
            $err = ($data && isset($data['error'])) ? $data['error'] : 'No response from zip_logs.sh - see data/logs/ajax.log';
            rb_fail($err, 500);
        }
        $path = $data['path'];
        if (!file_exists($path)) rb_fail('Archive was reported but not found on disk', 500);

        while (ob_get_level() > 0) { @ob_end_clean(); }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="RemoteBackup-logs-' . date('Ymd-His') . '.zip"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        @unlink($path); // was a temp file built solely for this response - clean it up now that it's sent
        exit;
    }

    default:
        rb_fail('Unknown action: ' . $action, 404);
}
