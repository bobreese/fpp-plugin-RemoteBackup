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
function rb_run($scriptPath, $args = [], $timeoutSec = 20, $redact = []) {
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

    // Build a redacted copy for logging only - $cmd itself (used above to
    // actually run the script) is never touched. $redact is a list of raw
    // argument VALUES (not positions - robust to call sites adding/
    // reordering args later) that must never reach ajax.log, e.g. an SSH
    // password passed straight through from pushSshKey. Matched against
    // the same escapeshellarg() form used to build $cmd, so it catches the
    // value exactly as it appears there.
    $logCmd = $cmd;
    foreach ($redact as $secret) {
        if ($secret === '' || $secret === null) continue;
        $logCmd = str_replace(escapeshellarg((string)$secret), escapeshellarg('***REDACTED***'), $logCmd);
    }

    if ($out === null || trim((string)$out) === '') {
        rb_log_line("RUN EMPTY OUTPUT cmd=$logCmd stderr=" . substr((string)$stderr, 0, 500));
    } elseif (!empty($stderr)) {
        rb_log_line("RUN cmd=$logCmd (rc=$return_value) stderr=" . substr($stderr, 0, 500));
    } else {
        rb_log_line("RUN OK cmd=$logCmd rc=$return_value");
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

// RB_BIND_SOURCE/RB_BIND_TARGET: the optional bind mount's fixed endpoints
// (see rb_bindmount_backups_ensure() in scripts/lib_common.sh, which is
// what actually establishes/tears it down - these two constants must stay
// in sync with that function's RB_BIND_SOURCE/RB_BIND_TARGET). Only used
// here read-only, to report status.
define('RB_BIND_SOURCE', '/mnt/Backups');
define('RB_BIND_TARGET', '/home/fpp/media/backups');

// Mirrors RB_SDCARD_MIN_FREE_BYTES in scripts/lib_common.sh - must stay in
// sync with it. Used only to make this action's 'sufficient' indicator
// agree with what run_backup.sh's own pre-flight check will actually do,
// not to enforce anything here - see the matching comment there for why a
// margin exists at all only for the SD Card/System Storage fallback.
define('RB_SDCARD_MIN_FREE_BYTES', 524288000);

// True if RB_BIND_TARGET is currently our bind mount of RB_BIND_SOURCE -
// both mounted, with the same underlying device. A bind mount shares its
// source's device field in /proc/mounts (e.g. both show /dev/sda1), which
// is the cheap way to confirm "these are the same live mount" here without
// shelling out to findmnt on every status poll, same reasoning as
// rb_is_mounted() above.
function rb_bindmount_is_active() {
    $mounts = @file('/proc/mounts');
    if (!$mounts) return false;
    $srcDev = null;
    $tgtDev = null;
    foreach ($mounts as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if (!isset($parts[0], $parts[1])) continue;
        if ($parts[1] === RB_BIND_SOURCE) $srcDev = $parts[0];
        if ($parts[1] === RB_BIND_TARGET) $tgtDev = $parts[0];
    }
    return $srcDev !== null && $tgtDev !== null && $srcDev === $tgtDev;
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
        'remotes' => [],
        // Non-empty when the user picked "Halt Backups" from the "backup
        // destination missing" popup (config.php/status.php) - checked by
        // run_backup.sh, which refuses to start while it's set. Cleared
        // automatically once the configured destination is seen mounted
        // again, or a different destinationMount is saved/activated.
        'haltedReason' => null,
        // Scheduled-run policy for the pre-flight space check in
        // run_backup.sh: off by default (a scheduled run refuses outright
        // and logs why when the estimated transfer won't fit); on switches
        // the destination to SD Card/System Storage automatically instead
        // of refusing, since an unattended run has nobody to answer the
        // "Backup Space Insufficient" popup a manual click would see.
        'autoFailoverOnLowSpace' => false,
        // Set by run_backup.sh's pre-flight space check when it refuses a
        // real run - non-empty means the Status/Config page's "Backup Space
        // Insufficient" popup should show. Cleared automatically the next
        // time a pre-flight check doesn't come up short, or a different
        // destinationMount is saved/activated.
        'lowSpaceReason' => null,
        'lowSpaceEstimatedBytes' => null,
        'lowSpaceAvailableBytes' => null,
        // What to do when a selected remote is found playing a sequence
        // right as a real run is about to start: 'stop' (default) refuses
        // the WHOLE run, same as this plugin has always done; 'skip'
        // instead leaves just that remote out of this run and backs up
        // the rest. Enforced by run_backup.sh - see its "remote-playing
        // check" block.
        'remotePlayingPolicy' => 'stop',
        // Set by run_backup.sh only for a --scheduled run (an FPP
        // Command - Scheduler/Playlist/Event, not a manual Start Backup
        // click) whose play-check actually did something: refused the
        // run outright, or skipped one or more remotes under 'skip'
        // policy. Nobody is watching a scheduled run happen, so the
        // Status/Config page shows a one-time "here's what happened"
        // popup the next time either is open, driven by this field.
        // Cleared only by acknowledgePlayOutcome (the popup's dismiss) or
        // overwritten by a newer notice - unlike haltedReason/
        // lowSpaceReason above, this reports a past event rather than an
        // ongoing condition, so it deliberately does not auto-clear on
        // its own.
        'lastScheduledPlayOutcome' => null,
        // Address of the FPP system designated as the show master for
        // Config's "Show Schedule Conflict Check" panel - not necessarily
        // one of the remotes[] entries above (the master isn't
        // automatically one you'd back up), so it's its own field rather
        // than reusing a remote selection. Purely advisory/read-only: this
        // is never consulted by run_backup.sh or any run guard, only by
        // that one Config panel when a human clicks "Check Schedule."
        'scheduleMasterAddress' => '',
        // Opt-in, default off: bind-mounts the primary destination drive's
        // content onto FPP's own fixed backups path while it's mounted AND
        // it's the saved destinationMount, so remotes/File Manager can see
        // current backups on it without unmounting first. See
        // rb_bindmount_backups_ensure() in lib_common.sh for the mechanics
        // and the safety invariant it depends on. Reconciled (bound/unbound
        // as needed) by bindmount_backups.sh, called after every settings
        // change that could affect it (saveSettings/useFailover/
        // useDestination below) and after every primary-drive mount/
        // unmount/format.
        'enableRestoreBindMount' => false,
        // Gates the first-run Config page walkthrough (config.php's tour
        // engine). Deliberately defaults to true HERE, not false - this
        // default is what array_merge() backfills into every EXISTING
        // settings.json missing the key (see rb_load_settings() below),
        // which on this plugin means every install predating this
        // feature. Defaulting true means an upgrade never surprises an
        // already-configured system with an unsolicited tour popup the
        // next time Config loads. fpp_install.sh's settings.json seed
        // overrides this to false explicitly for a genuinely fresh
        // install only - that's the one case the tour should actually
        // auto-show for. Set true by the 'markOnboardingSeen' action once
        // the tour is dismissed/completed, however it was started
        // (automatically, or by checking the box below).
        'onboardingSeen' => true,
        // Config's own checkbox - doubles as both a persisted on/off
        // switch (whether the tour is allowed to auto-show for a future
        // fresh install) AND the recall trigger itself: checking it starts
        // the tour immediately in config.php's JS, independent of whether
        // this saved value is true or false yet. The "?" next to it is
        // just an explanatory popover, not a separate control.
        'onboardingTourEnabled' => true
    ];
}

// rb_settings_backup_path: the in-data-dir mirror rb_save_settings() below
// keeps of every successful write, and the first place rb_load_settings()
// looks to recover a live file that's gone empty or corrupt.
function rb_settings_backup_path($SETTINGS_FILE) {
    return $SETTINGS_FILE . '.bak';
}

// rb_settings_external_backup_path: a SECOND mirror, deliberately kept
// outside data/ (and outside this plugin's directory entirely) at a fixed,
// hardcoded location on the FPP media tree - the same /home/fpp/media root
// RB_SDCARD_FALLBACK_DIR in lib_common.sh already trusts as a stable,
// always-present FPP path.
//
// Why a second copy in a different place, not just the one above: a real
// incident (data/settings.json.bak's own first version) proved the
// in-data-dir copy alone isn't independent protection. Two occurrences
// inside about an hour on a live system each showed the exact same
// signature - a multi-minute total gap in ajax.log (no requests at all,
// not just a slowdown), then the very next request finding settings.json
// empty. The SECOND occurrence also found settings.json.bak gone too,
// despite it having been freshly written less than an hour earlier -
// meaning whatever is doing this wipes (or replaces) the whole data/
// directory, not just the one file, so a backup living inside that same
// directory goes down with it. This second copy living entirely outside
// data/ - and outside the plugin directory FPP itself replaces wholesale
// on an update/reinstall - is what actually survives that failure mode.
function rb_settings_external_backup_path() {
    return '/home/fpp/media/.fpp-plugin-RemoteBackup-settings.bak';
}

function rb_load_settings($SETTINGS_FILE) {
    if (!file_exists($SETTINGS_FILE)) return rb_default_settings();
    $raw = @file_get_contents($SETTINGS_FILE);
    $d = json_decode((string)$raw, true);
    if (is_array($d)) {
        return array_merge(rb_default_settings(), $d);
    }

    // Live file exists but is empty/corrupt. Try the in-data-dir backup
    // first (handles a single bad write to the live file alone), then the
    // external one (handles data/ itself being wiped, backup included) -
    // see the two path functions above for why both exist.
    rb_log_line("WARN: settings.json unreadable or invalid, raw=" . substr((string)$raw, 0, 300));

    foreach ([rb_settings_backup_path($SETTINGS_FILE), rb_settings_external_backup_path()] as $backupFile) {
        $backupRaw = @file_get_contents($backupFile);
        $backupData = ($backupRaw !== false) ? json_decode($backupRaw, true) : null;
        if (is_array($backupData)) {
            rb_log_line("RECOVERED settings.json from $backupFile - restoring it as the live file");
            rb_save_settings($SETTINGS_FILE, $backupData);
            return array_merge(rb_default_settings(), $backupData);
        }
    }

    // No usable backup either place - fall back to defaults, but persist
    // them to disk too so the file stops being empty/broken and this
    // doesn't keep re-triggering (and re-logging this same warning) on
    // literally every request from here on.
    rb_log_line("WARN: no usable backup at " . rb_settings_backup_path($SETTINGS_FILE) . ' or ' .
        rb_settings_external_backup_path() . ' either - resetting settings.json to defaults');
    $defaults = rb_default_settings();
    rb_save_settings($SETTINGS_FILE, $defaults);
    return $defaults;
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

    // Best-effort mirrors, in-data-dir and external - see the two path
    // functions above. Never allowed to fail the save itself; they're a
    // safety net, not the primary write.
    foreach ([rb_settings_backup_path($SETTINGS_FILE), rb_settings_external_backup_path()] as $backupFile) {
        $backupTmp = $backupFile . '.tmp';
        if (@file_put_contents($backupTmp, $json) !== false) {
            @rename($backupTmp, $backupFile);
            @chmod($backupFile, 0666);
        }
    }

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

    // checkMasterSchedule: Config's "Show Schedule Conflict Check" panel -
    // read-only, purely advisory (see the panel's own Note and
    // docs/schedule-conflict-check.md). Address comes from the query
    // string rather than saved settings so the panel can be used to check
    // a candidate address before ever saving it as scheduleMasterAddress.
    case 'checkMasterSchedule': {
        $address = isset($_GET['address']) ? trim((string)$_GET['address']) : '';
        if ($address === '') rb_fail('address required');
        $data = rb_run_json("$SCRIPTS_DIR/check_master_schedule.sh", [$address], 15);
        if (!$data) $data = ['ok' => false, 'error' => 'No response from check_master_schedule.sh - see data/logs/ajax.log'];
        echo json_encode($data);
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
        // settingsFileExisted: whether settings.json was already on disk
        // BEFORE this load - the most reliable "has this install ever
        // been configured/seeded" signal there is, independent of
        // fpp_install.sh actually having run. rb_load_settings() returns
        // rb_default_settings() in memory without writing anything when
        // the file is missing (onboardingSeen defaults true there, since
        // that same in-memory default is also what an EXISTING install's
        // settings.json gets merged against for any key it predates - see
        // rb_default_settings()'s own comment). Config's JS uses this flag
        // to auto-show the walkthrough whenever the file never existed at
        // all, rather than trusting fpp_install.sh's fresh-install seed as
        // the only path to onboardingSeen ever being false - a real report
        // in the wild: a genuinely fresh SD card install where the seed
        // never got written (data/settings.json simply didn't exist), so
        // every load fell back to the upgrade-safe default and the tour
        // never fired for what should have shown it automatically.
        $settingsFileExisted = file_exists($SETTINGS_FILE);
        echo json_encode(['ok' => true, 'data' => rb_load_settings($SETTINGS_FILE), 'settingsFileExisted' => $settingsFileExisted]);
        break;
    }

    // Marks the Config page walkthrough seen/dismissed, independent of the
    // main Save Settings flow - the tour shouldn't reappear on the next
    // page load just because the user closed it without also saving
    // unrelated settings changes. Mirrors acknowledgePlayOutcome's pattern
    // (a small, immediate settings mutation outside saveSettings).
    // Always writes, unconditionally - deliberately not guarded behind
    // "only if not already true": when settings.json never existed, the
    // in-memory default already reports onboardingSeen as true, so a
    // guarded write would see nothing to do and never actually create a
    // real settings.json - leaving the tour to auto-fire again on every
    // future page load despite having just been dismissed.
    case 'markOnboardingSeen': {
        if ($method !== 'POST') rb_fail('POST required');
        $settings = rb_load_settings($SETTINGS_FILE);
        $settings['onboardingSeen'] = true;
        if (!rb_save_settings($SETTINGS_FILE, $settings)) {
            rb_fail('Could not write settings.json - check that ' . dirname($SETTINGS_FILE) . ' is writable by the web server user. See data/logs/ajax.log.', 500);
        }
        echo json_encode(['ok' => true]);
        break;
    }

    case 'saveSettings': {
        if ($method !== 'POST') rb_fail('POST required');
        $body = rb_json_body();
        $settings = rb_load_settings($SETTINGS_FILE);
        $prevDestinationMount = isset($settings['destinationMount']) ? $settings['destinationMount'] : '';

        foreach (['hostModeEnabled', 'deleteExtraneous', 'snapshotMode', 'includeSystemConfig', 'autoFailoverOnLowSpace', 'enableRestoreBindMount', 'onboardingTourEnabled'] as $k) {
            if (isset($body[$k])) $settings[$k] = (bool)$body[$k];
        }
        foreach (['destinationMount', 'destinationLabel', 'sshUser', 'sshKeyPath', 'sshPassword', 'scheduleMasterAddress'] as $k) {
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
        if (isset($body['remotePlayingPolicy']) && in_array($body['remotePlayingPolicy'], ['stop', 'skip'], true)) {
            $settings['remotePlayingPolicy'] = $body['remotePlayingPolicy'];
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

        // Picking (and saving) a different destination is itself the fix for
        // whatever "backups are halted" was raised over, so it clears the
        // flag - same auto-recovery idea as the 'status' poll below noticing
        // the original destination is mounted again, just for the "I picked
        // a new one instead" path.
        if (!empty($settings['haltedReason']) && isset($settings['destinationMount']) && $settings['destinationMount'] !== $prevDestinationMount) {
            unset($settings['haltedReason']);
        }
        // Same idea for a low-space refusal - picking a different
        // destination is itself the fix, so the "Backup Space Insufficient"
        // popup shouldn't keep reappearing for a destination that's no
        // longer even the active one.
        if (!empty($settings['lowSpaceReason']) && isset($settings['destinationMount']) && $settings['destinationMount'] !== $prevDestinationMount) {
            unset($settings['lowSpaceReason'], $settings['lowSpaceEstimatedBytes'], $settings['lowSpaceAvailableBytes']);
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

        // Reconcile the optional bind mount - destinationMount and/or
        // enableRestoreBindMount may have just changed, either of which can
        // mean binding, unbinding, or leaving it as-is. Best-effort - a
        // hiccup here should never fail the settings save itself.
        rb_run("$SCRIPTS_DIR/bindmount_backups.sh", ['reconcile'], 15);

        echo json_encode(['ok' => true, 'data' => $settings]);
        break;
    }

    // haltBackups / useFailover: the two choices offered by the Status/Config
    // page's "destination drive is missing" popup (see the 'status' case
    // below, which is what actually notices the drive is gone in the first
    // place). Neither requires the usual "Save Settings" step - both are
    // meant to take effect the instant the user picks one, since the whole
    // point is resolving an active problem rather than queuing up a change.
    case 'haltBackups': {
        if ($method !== 'POST') rb_fail('POST required');
        $body = rb_json_body();
        $reason = (isset($body['reason']) && $body['reason'] !== '') ? (string)$body['reason'] : 'destination drive not found';
        $settings = rb_load_settings($SETTINGS_FILE);
        $settings['haltedReason'] = $reason;
        if (!rb_save_settings($SETTINGS_FILE, $settings)) {
            rb_fail('Could not write settings.json - check that ' . dirname($SETTINGS_FILE) . ' is writable by the web server user. See data/logs/ajax.log.', 500);
        }
        rb_log_line("HALT requested: $reason");
        echo json_encode(['ok' => true, 'data' => $settings]);
        break;
    }

    case 'useFailover': {
        if ($method !== 'POST') rb_fail('POST required');
        $settings = rb_load_settings($SETTINGS_FILE);
        // "/" (SD Card/System Storage) is always available - it's the
        // filesystem root, so there's nothing to mount/format/detect first,
        // unlike a USB/NVMe/SSD destination. Backups land in a dedicated
        // /home/fpp/media/backups subfolder, never in "/" itself - see
        // rb_dest_root() in lib_common.sh.
        $settings['destinationMount'] = '/';
        unset($settings['haltedReason']);
        if (!rb_save_settings($SETTINGS_FILE, $settings)) {
            rb_fail('Could not write settings.json - check that ' . dirname($SETTINGS_FILE) . ' is writable by the web server user. See data/logs/ajax.log.', 500);
        }
        rb_log_line("FAILOVER activated: destinationMount switched to '/' (SD Card/System Storage)");
        // destinationMount just changed away from the drive (if it was on
        // it) - reconcile the optional bind mount so it doesn't linger and
        // silently misdirect SD-card-fallback backups onto the drive. See
        // rb_bindmount_backups_ensure() in lib_common.sh.
        rb_run("$SCRIPTS_DIR/bindmount_backups.sh", ['reconcile'], 15);
        echo json_encode(['ok' => true, 'data' => $settings]);
        break;
    }

    // useDestination: the "Replace Destination" choice on the "Backup Space
    // Insufficient" popup - switches to any OTHER currently-mounted drive
    // the user picks (not just "/", which useFailover above already
    // covers). Re-probes storage itself rather than trusting the client's
    // mountpoint blindly, so this can't be pointed at something that isn't
    // actually a real, currently-mounted destination.
    case 'useDestination': {
        if ($method !== 'POST') rb_fail('POST required');
        $body = rb_json_body();
        $mountpoint = isset($body['mountpoint']) ? (string)$body['mountpoint'] : '';
        if ($mountpoint === '') rb_fail('mountpoint required');

        if ($mountpoint !== '/' && !rb_is_mounted($mountpoint)) {
            rb_fail('That drive is not currently mounted - rescan storage and try again.', 409);
        }

        $settings = rb_load_settings($SETTINGS_FILE);
        $settings['destinationMount'] = $mountpoint;
        unset($settings['haltedReason'], $settings['lowSpaceReason'], $settings['lowSpaceEstimatedBytes'], $settings['lowSpaceAvailableBytes']);
        if (!rb_save_settings($SETTINGS_FILE, $settings)) {
            rb_fail('Could not write settings.json - check that ' . dirname($SETTINGS_FILE) . ' is writable by the web server user. See data/logs/ajax.log.', 500);
        }
        rb_log_line("DESTINATION REPLACED: destinationMount switched to '$mountpoint'");
        // destinationMount just changed - reconcile the optional bind mount
        // (see rb_bindmount_backups_ensure() in lib_common.sh) so it never
        // stays bound to a drive that's no longer the active destination.
        rb_run("$SCRIPTS_DIR/bindmount_backups.sh", ['reconcile'], 15);
        echo json_encode(['ok' => true, 'data' => $settings]);
        break;
    }

    // Dismisses the "here's what a scheduled run just did" popup driven by
    // lastScheduledPlayOutcome (see rb_default_settings()). Unlike
    // haltBackups/useFailover/useDestination above, this doesn't resolve
    // anything - it's a plain past-event notice, so dismissing it is just
    // acknowledging it was seen, not fixing a still-active problem.
    case 'acknowledgePlayOutcome': {
        if ($method !== 'POST') rb_fail('POST required');
        $settings = rb_load_settings($SETTINGS_FILE);
        if (!empty($settings['lastScheduledPlayOutcome']) && is_array($settings['lastScheduledPlayOutcome'])) {
            $settings['lastScheduledPlayOutcome']['acknowledged'] = true;
            if (!rb_save_settings($SETTINGS_FILE, $settings)) {
                rb_fail('Could not write settings.json - check that ' . dirname($SETTINGS_FILE) . ' is writable by the web server user. See data/logs/ajax.log.', 500);
            }
        }
        echo json_encode(['ok' => true]);
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

        // $redact: keep the SSH password out of ajax.log's "RUN cmd=..."
        // line - see rb_run()'s $redact parameter.
        $out = rb_run("$SCRIPTS_DIR/ssh_setup.sh", [$address, $user, (string)$port, $password], 20, [$password]);
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
        // Best-effort check so a real (non-dry) Start Backup refuses right
        // here with a clear toast instead of always claiming "started" and
        // only failing later in engine.log. run_backup.sh has the
        // authoritative check (it's also the only guard for a Scheduler-
        // triggered or manual/cron run that never goes through this
        // endpoint at all) - this is purely about giving an honest,
        // immediate answer from the UI. Dry Run is deliberately exempt,
        // same reasoning as run_backup.sh's own check.
        if (!$dryRun) {
            $hostSettings = rb_load_settings($SETTINGS_FILE);
            if (empty($hostSettings['hostModeEnabled'])) {
                rb_fail('This system does not have Host Mode enabled (Config > Backup Host Mode). Dry Run still works either way.', 409);
            }
        }

        // Set when the user already saw the "Backup Space Insufficient"
        // popup and explicitly chose "Start Anyway" - passed through to
        // run_backup.sh so its own pre-flight check doesn't just refuse
        // this attempt too. Acknowledging it is itself resolving it, same
        // as picking a new destination - clears the stale reason so the
        // popup doesn't linger for a run the user already chose to proceed
        // with anyway.
        $skipSpaceCheck = isset($body['skipSpaceCheck']) && $body['skipSpaceCheck'];
        if ($skipSpaceCheck) {
            $s = rb_load_settings($SETTINGS_FILE);
            if (!empty($s['lowSpaceReason'])) {
                unset($s['lowSpaceReason'], $s['lowSpaceEstimatedBytes'], $s['lowSpaceAvailableBytes']);
                rb_save_settings($SETTINGS_FILE, $s);
            }
        }

        if (rb_is_active("$DATA_DIR/run_active.json")) {
            rb_fail('A backup run is already in progress', 409);
        }
        if (rb_is_active("$DATA_DIR/clone_active.json")) {
            rb_fail('A backup clone to the secondary drive is currently in progress (it reads from this destination). Wait for it to finish before starting a backup.', 409);
        }

        // Pulling media off a device's SD card while fppd is actively
        // reading those same files for playback risks stutters/dropped
        // frames during a live show. remotePlayingPolicy controls what
        // happens: 'stop' (default) refuses the WHOLE run (not just the
        // busy remote); 'skip' lets the run proceed and leaves the busy
        // remote(s) out of it instead - $skippedPlaying below just carries
        // their names back for an immediate toast, since run_backup.sh
        // (not this synchronous pre-check) is what actually does the
        // filtering, and is the only guard that covers Scheduler-triggered
        // and manual/cron runs that never go through this endpoint at all.
        $skippedPlaying = [];
        $playCheckArgs = empty($ids) ? [] : ['--remotes', implode(',', array_map('rb_slugify', $ids))];
        $playCheck = rb_run_json("$SCRIPTS_DIR/check_remotes_playing.sh", $playCheckArgs, 20);
        if ($playCheck && !empty($playCheck['playing'])) {
            $playingNames = array_map(function ($r) { return $r['hostname']; }, $playCheck['playing']);
            $playingIds = array_map(function ($r) { return $r['id']; }, $playCheck['playing']);
            $policySettings = rb_load_settings($SETTINGS_FILE);
            $playPolicy = isset($policySettings['remotePlayingPolicy']) ? $policySettings['remotePlayingPolicy'] : 'stop';

            if ($playPolicy !== 'skip') {
                rb_fail('Refusing to start: currently playing a sequence - ' . implode(', ', $playingNames), 409);
            }

            // Under 'skip' policy there's usually still something left to
            // back up - but if every explicitly-requested remote turns out
            // to be one of the busy ones, skipping would leave nothing to
            // do, so that specific case still refuses outright.
            if (!empty($ids)) {
                $requestedIds = array_map('rb_slugify', $ids);
                $stillRunnable = array_diff($requestedIds, $playingIds);
                if (empty($stillRunnable)) {
                    rb_fail('Refusing to start: every selected remote is currently playing a sequence - ' . implode(', ', $playingNames), 409);
                }
            }
            $skippedPlaying = $playingNames;
        }

        // Clear stale per-remote status files from any previous run so
        // the UI table doesn't show leftovers from unrelated remotes.
        foreach (glob("$STATUS_DIR/*.json") as $f) { @unlink($f); }

        $args = $dryRun ? ' --dry-run' : '';
        if ($skipSpaceCheck) $args .= ' --skip-space-check';
        if (!empty($ids)) {
            $safeIds = array_map('rb_slugify', $ids);
            $args .= ' --remotes ' . escapeshellarg(implode(',', $safeIds));
        }
        $cmd = escapeshellcmd("$SCRIPTS_DIR/run_backup.sh") . $args . ' > ' .
            escapeshellarg("$LOG_DIR/last_start.log") . ' 2>&1 &';
        rb_log_line("START cmd=$cmd");
        shell_exec($cmd);

        echo json_encode(['ok' => true, 'started' => true, 'dryRun' => $dryRun, 'skippedPlaying' => $skippedPlaying]);
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
            // Same 500MB reserve run_backup.sh's own pre-flight check
            // applies for the SD Card/System Storage fallback (see
            // RB_SDCARD_MIN_FREE_BYTES above) - without it, this indicator
            // could say "sufficient" right up until the real run refuses.
            $margin = (isset($settings['destinationMount']) && $settings['destinationMount'] === '/') ? RB_SDCARD_MIN_FREE_BYTES : 0;
            $summary = [
                'estimatedTotalBytes' => $estTotal,
                'availableBytes' => $avail,
                'sufficient' => ($avail === null) ? null : ($avail > $estTotal + $margin)
            ];
        }

        // Missing-destination detection: a configured destination other than
        // "/" (SD Card/System Storage is always available by definition, so
        // it can never be "missing") that $destStorage above just failed to
        // find mounted. Surfaced so the Status/Config page - whichever is
        // open - can offer the "drive is missing: Halt backups or Use
        // failover" popup rather than a run just failing later with no
        // warning beforehand.
        $destinationMissing = !empty($settings['destinationMount']) && $settings['destinationMount'] !== '/' && $destStorage === null;

        // Auto-recovery: the destination that was missing is back (present
        // in $destStorage again) - clear a halt raised over it without
        // requiring the user to do anything else. Saving a *different*
        // destination clears it too, but that path is handled directly in
        // 'saveSettings' above, not here.
        if ($destStorage !== null && !empty($settings['haltedReason'])) {
            unset($settings['haltedReason']);
            rb_save_settings($SETTINGS_FILE, $settings);
        }

        echo json_encode([
            'ok' => true, 'active' => !empty($activeData['active']), 'remotes' => $remotes,
            'dryRunSummary' => $summary, 'destStorage' => $destStorage,
            'destinationMount' => isset($settings['destinationMount']) ? $settings['destinationMount'] : null,
            'destinationMissing' => $destinationMissing,
            // Whether the optional "see current backups without unmounting"
            // bind mount (see rb_bindmount_backups_ensure() in
            // lib_common.sh) is actually bound right now - not just whether
            // the setting is enabled, since it also depends on the drive
            // being mounted and being the saved destination. When true,
            // $destStorage above already describes the exact same drive
            // (RB_BIND_SOURCE is only ever bound while it IS
            // destinationMount).
            'bindMountActive' => rb_bindmount_is_active(),
            'haltedReason' => isset($settings['haltedReason']) ? $settings['haltedReason'] : null,
            'lowSpaceReason' => isset($settings['lowSpaceReason']) ? $settings['lowSpaceReason'] : null,
            'lowSpaceEstimatedBytes' => isset($settings['lowSpaceEstimatedBytes']) ? $settings['lowSpaceEstimatedBytes'] : null,
            'lowSpaceAvailableBytes' => isset($settings['lowSpaceAvailableBytes']) ? $settings['lowSpaceAvailableBytes'] : null,
            'lastScheduledPlayOutcome' => isset($settings['lastScheduledPlayOutcome']) ? $settings['lastScheduledPlayOutcome'] : null
        ]);
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
        // Every request should re-run this and reflect whatever's on disk right
        // now - this endpoint never starts a PHP session (the usual source of
        // an automatic no-store header), so without an explicit one here a
        // browser is free to cache this GET response and keep silently
        // replaying an old snapshot on later requests to the exact same URL.
        header('Cache-Control: no-store, no-cache, must-revalidate');
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
        // See the matching comment on downloadLog above - without this, a
        // browser can cache this GET response and keep replaying the same
        // stale archive on every later click instead of rebuilding it fresh.
        header('Cache-Control: no-store, no-cache, must-revalidate');
        readfile($path);
        @unlink($path); // was a temp file built solely for this response - clean it up now that it's sent
        exit;
    }

    default:
        rb_fail('Unknown action: ' . $action, 404);
}
