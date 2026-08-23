<?php
// Don't rely on FPP's own $plugin/$pluginName globals here - depending on
// which code path included this file, that variable may be unset or even
// hold an unrelated leftover value (seen in the wild: boolean false, which
// json_encode()'s to the bare word `false` and breaks the JS PLUGIN var).
// The plugin's own directory name is always correct and unambiguous.
$rbPlugin = basename(__DIR__);
?>
<div class="mt-2" id="rb-status">
    <fieldset class="border rounded p-2">
        <legend>Remote Backup</legend>
        <div class="p-2" style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:8px;">
            <div>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="rb-dryrun">Dry Run (selected remotes)</button>
                <i class="fas fa-question-circle fpp-help-popover ms-1" data-help-content="rb-help-dryrun" data-help-title="Dry Run" style="font-size:0.8em; cursor:help;"></i>
                <button type="button" class="btn btn-primary btn-sm ms-1" id="rb-start">Start Backup</button>
                <i class="fas fa-question-circle fpp-help-popover ms-1" data-help-content="rb-help-start" data-help-title="Start Backup" style="font-size:0.8em; cursor:help;"></i>
                <button type="button" class="btn btn-danger btn-sm ms-1" id="rb-stop">Stop</button>
                <a class="btn btn-outline-secondary btn-sm ms-1" href="plugin.php?plugin=<?php echo urlencode($rbPlugin); ?>&page=config.php">Config</a>
                <i class="fas fa-question-circle fpp-help-popover ms-1" data-help-content="rb-help-config" data-help-title="Config" style="font-size:0.8em; cursor:help;"></i>
                <span id="rb-runMsg" class="ms-2"></span>

                <div id="rb-help-dryrun" class="d-none">
                    <div class="fpp-help-content">
                        <p class="mb-0">Simulates a backup for the selected remotes without changing anything - no
                            files are copied and no backup folder is created. Shows the estimated transfer size and
                            whether the destination has enough free space, so you can check before running for real.
                            Allow a few seconds after clicking before the Backup Status table shows anything - the
                            pre-flight space check runs sequentially, one remote at a time, before any remote shows
                            as queued/running, so the wait scales with how many remotes are selected.</p>
                    </div>
                </div>
                <div id="rb-help-start" class="d-none">
                    <div class="fpp-help-content">
                        <p class="mb-0">Runs a real backup: pulls files via rsync from every remote selected on the
                            Config page onto this Host's destination storage. Files are actually copied (and
                            mirrored/deleted if that option is enabled) - this is not a simulation.
                            Allow a few seconds after clicking before the Backup Status table shows anything - the
                            pre-flight space check runs sequentially, one remote at a time, before any remote shows
                            as queued/running, so the wait scales with how many remotes are selected.</p>
                    </div>
                </div>
                <div id="rb-help-config" class="d-none">
                    <div class="fpp-help-content">
                        <p class="mb-0">Opens the Config page - choose which remotes to back up, pick the
                            destination storage, and set backup options like delete-mirroring, snapshot mode, and
                            SSH settings.</p>
                    </div>
                </div>
            </div>
            <div style="text-align:right;">
                <label for="rb-backedup-select"><b>Backed Up</b></label><br>
                <select id="rb-backedup-select" style="min-width:220px;">
                    <option value="">(loading...)</option>
                </select>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="rb-backedup-refresh" title="Rescan storage">&#8635;</button>
            </div>
        </div>
        <div class="p-2 text-muted" id="rb-dest-storage" style="font-size:0.9em;">Host storage: (loading...)</div>
        <div class="p-2" id="rb-backedup-info" style="display:none; border-top:1px solid #ddd; margin-top:4px;"></div>
    </fieldset>

    <fieldset class="border rounded p-2 mt-2" id="rb-dryrun-panel" style="display:none;">
        <legend>Dry Run Result</legend>
        <div class="p-2" id="rb-dryrun-summary"></div>
    </fieldset>

    <fieldset class="border rounded p-2 mt-2">
        <legend>Backup Status</legend>
        <div class="p-2">
            <style>
                #rb-status-table th, #rb-status-table td { padding: 0.55rem 0.9rem; }
            </style>
            <table class="table table-sm" id="rb-status-table">
                <thead>
                    <tr>
                        <th>Remote</th>
                        <th>Address</th>
                        <th>State</th>
                        <th>Current File</th>
                        <th>Progress</th>
                        <th>Files</th>
                        <th>Backup Folder</th>
                    </tr>
                </thead>
                <tbody id="rb-status-body">
                    <tr><td colspan="7">No backup has been run yet.</td></tr>
                </tbody>
            </table>
        </div>
    </fieldset>

    <fieldset class="border rounded p-2 mt-2">
        <legend>Clone Backups to a Second Drive</legend>
        <div class="p-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="rb-clone-start">Start Clone</button>
            <button type="button" class="btn btn-danger btn-sm ms-1" id="rb-clone-stop">Stop</button>
            <span id="rb-clone-msg" class="ms-2"></span>
            <div class="p-1 text-muted" id="rb-clone-secondary-storage" style="font-size:0.9em;">Secondary drive: (loading...)</div>
            <div id="rb-clone-progress" style="display:none;">
                <div style="max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" id="rb-clone-current"></div>
                <div class="progress" style="height:1.2em;max-width:320px;">
                    <div class="progress-bar" role="progressbar" id="rb-clone-bar" style="width:0%;">0%</div>
                </div>
            </div>
            <div id="rb-clone-result" class="mt-1"></div>
            <small class="text-muted">Mirrors everything on the primary destination onto the secondary drive
                mounted at <code>/mnt/BackupsCopy</code> (<code>rsync --delete</code> - an exact copy, so a backup
                you deleted on the primary is removed from the clone too). Format/mount the secondary drive on the
                Config page first. Allow a few seconds after clicking Start Clone before this section updates -
                it's a real background process, not instant.</small>
        </div>
    </fieldset>

    <fieldset class="border rounded p-2 mt-2">
        <legend>Diagnostic Log</legend>
        <div class="p-2">
            <select id="rb-log-which">
                <option value="ajax">ajax.log (Config/Status page actions)</option>
                <option value="engine">engine.log (backup run engine)</option>
                <option value="clone">clone.log (backup clone to second drive)</option>
            </select>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="rb-log-refresh">Refresh Log</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="rb-log-download">Download</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="rb-log-download-all">Download All Logs</button>
            <label class="ms-2"><input type="checkbox" id="rb-log-autotail"> Tail Follow</label>
            <label class="ms-2"><input type="checkbox" id="rb-log-errors-only"> Errors/warnings only</label>
            <div><small id="rb-log-download-status" class="text-muted"></small></div>
            <div><small id="rb-log-path" class="text-muted"></small></div>
            <pre id="rb-log-content" class="bg-body-secondary border rounded" style="max-height:300px;overflow:auto;padding:6px;margin-top:6px;">(not loaded yet)</pre>
        </div>
    </fieldset>
</div>

<script>
(function () {
    var PLUGIN = <?php echo json_encode($rbPlugin); ?>;
    var AJAX = 'plugin.php?plugin=' + encodeURIComponent(PLUGIN) + '&page=ajax.php&nopage=1&action=';
    var pollTimer = null;

    function api(action, opts) {
        opts = opts || {};
        var controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        var timer = controller ? setTimeout(function () { controller.abort(); }, 20000) : null;
        var init = { method: opts.method || 'GET' };
        if (controller) init.signal = controller.signal;
        if (opts.body) {
            init.method = 'POST';
            init.headers = { 'Content-Type': 'application/json' };
            init.body = JSON.stringify(opts.body);
        }
        // Never lets a caller's .then() hang forever: network errors, aborts,
        // and non-JSON responses (e.g. a stray PHP warning corrupting the
        // body) all resolve to an {ok:false, error:...} object instead of
        // rejecting silently.
        return fetch(AJAX + action, init).then(function (r) {
            return r.text().then(function (txt) {
                var data;
                try {
                    data = JSON.parse(txt);
                } catch (e) {
                    console.error('Remote Backup: non-JSON response for action=' + action, txt);
                    return { ok: false, error: 'Server returned a non-JSON response (see browser console and data/logs/ajax.log on the Host).' };
                }
                return data;
            });
        }).catch(function (err) {
            console.error('Remote Backup: request failed for action=' + action, err);
            var msg = (err && err.name === 'AbortError') ? 'Request timed out after 20s' : ('Network error: ' + (err && err.message ? err.message : err));
            return { ok: false, error: msg };
        }).then(function (result) {
            if (timer) clearTimeout(timer);
            return result;
        });
    }

    // "Backup destination missing" popup - the 'status' poll below (and
    // config.php's own copy of this, since either page might be the one
    // open when a mounted drive vanishes) surfaces destinationMissing once
    // a configured destination other than "/" stops being found mounted.
    // Shown once per "episode": resets the moment the drive is no longer
    // reported missing, and stays quiet on every subsequent poll once
    // haltedReason shows the user (or a prior page load) already picked
    // Halt for this same episode.
    var rbDestMissingPopupShown = false;

    function rbHandleDestinationStatus(res) {
        if (!res || !res.ok) return;
        if (!res.destinationMissing) { rbDestMissingPopupShown = false; return; }
        if (res.haltedReason) { rbDestMissingPopupShown = true; return; }
        if (rbDestMissingPopupShown) return;
        rbDestMissingPopupShown = true;
        rbShowDestinationMissingModal(res.destinationMount);
    }

    function rbShowDestinationMissingModal(mountpoint) {
        var modalId = 'rb-dest-missing-modal';
        var mp = mountpoint || 'the configured destination';
        var bodyHtml =
            '<div class="callout callout-danger mb-2">The backup destination drive (<code>' + mp + '</code>) ' +
            'is not currently mounted - it may have been unplugged, powered off, or failed.</div>' +
            'Manual and scheduled backups will fail until this is resolved. Choose how to proceed:<br><br>' +
            '<b>Halt Backups</b> - refuses any backup run (manual or scheduled) with a clear reason in the log, ' +
            'until the drive reappears or a new destination is saved.<br>' +
            '<b>Use Failover</b> - immediately switches the destination to SD Card / System Storage (always ' +
            'available, no drive required) so scheduled backups keep running.';
        DoModalDialog({
            id: modalId,
            title: 'Backup Destination Missing',
            class: 'modal-m',
            backdrop: true,
            body: bodyHtml,
            buttons: {
                'Halt Backups': {
                    class: 'btn-danger',
                    click: function () {
                        CloseModalDialog(modalId);
                        api('haltBackups', { body: { reason: 'destination drive (' + mp + ') not found' } }).then(function (r) {
                            $.jGrowl(r.ok ? 'Backups halted until the destination is resolved.' : ('Could not halt backups: ' + (r.error || 'unknown error')), { life: 6000, themeState: r.ok ? 'info' : 'danger' });
                        });
                    }
                },
                'Use Failover': {
                    class: 'btn-primary',
                    click: function () {
                        CloseModalDialog(modalId);
                        api('useFailover', { body: {} }).then(function (r) {
                            $.jGrowl(r.ok ? 'Failover activated - destination switched to SD Card / System Storage.' : ('Could not activate failover: ' + (r.error || 'unknown error')), { life: 6000, themeState: r.ok ? 'success' : 'danger' });
                        });
                    }
                }
            }
        });
    }

    // "Backup Space Insufficient" popup - unlike the missing-destination
    // case above, this only ever appears AFTER a real run attempt (manual
    // or scheduled) was actually refused by run_backup.sh's own pre-flight
    // check, surfaced via lowSpaceReason on the same 'status' poll. Shown
    // once per "episode", same show-once state machine as
    // rbDestMissingPopupShown above.
    var rbLowSpacePopupShown = false;

    function rbHandleLowSpaceStatus(res) {
        if (!res || !res.ok) return;
        if (!res.lowSpaceReason) { rbLowSpacePopupShown = false; return; }
        if (rbLowSpacePopupShown) return;
        rbLowSpacePopupShown = true;
        rbShowLowSpaceModal(res.lowSpaceReason, res.lowSpaceEstimatedBytes, res.lowSpaceAvailableBytes, res.destinationMount);
    }

    // Re-attempts a real Start Backup with --skip-space-check, against
    // whatever is currently selected on the Config page - used after the
    // user picks a way to resolve a space-insufficient refusal, since that
    // resolution is pointless without actually retrying the run it was for.
    function rbRetryStartAfterLowSpace() {
        getSelectedRemoteIds().then(function (ids) {
            if (!ids.length) return;
            api('start', { body: { remotes: ids, dryRun: false, skipSpaceCheck: true } }).then(function (r) {
                if (r.ok) { pendingRunButtonId = 'rb-start'; markButtonActive('rb-start'); poll(); }
            });
        });
    }

    function rbShowLowSpaceModal(reason, estBytes, availBytes, currentDest) {
        var modalId = 'rb-lowspace-modal';
        var bodyHtml =
            '<div class="callout callout-danger mb-2">' + reason + '</div>' +
            'The last backup attempt was refused before copying anything. Choose how to proceed:<br><br>' +
            '<b>Start Anyway</b> - proceed despite the warning; the transfer may only partially complete if it ' +
            'truly doesn\'t fit.<br>' +
            '<b>Replace Destination</b> - pick a different currently-mounted drive with enough room.<br>' +
            '<b>Use Failover</b> - switch to SD Card / System Storage (always available).';
        DoModalDialog({
            id: modalId,
            title: 'Backup Space Insufficient',
            class: 'modal-m',
            backdrop: true,
            body: bodyHtml,
            buttons: {
                'Stop Backup': {
                    class: 'btn-outline-danger',
                    click: function () {
                        CloseModalDialog(modalId);
                        // This refusal always happens in run_backup.sh's
                        // pre-flight check, before run_active.json is ever
                        // set true or any rsync starts (see the comment
                        // above rbShowLowSpaceModal) - so in the normal
                        // case there's nothing actually running. Calling
                        // the same 'stop' action the Status page's own Stop
                        // button uses anyway (kills any tracked per-remote
                        // PIDs, clears run_active.json) is a harmless no-op
                        // then, and a real safety net if this warning is
                        // ever reached while an earlier run is still
                        // finishing in the background.
                        api('stop', { body: {} }).then(function (res) {
                            $.jGrowl(res.ok ? 'Backup stopped.' : ('Could not stop backup: ' + (res.error || 'unknown error')), { life: 6000, themeState: res.ok ? 'info' : 'danger' });
                        });
                    }
                },
                'Start Anyway': {
                    class: 'btn-danger',
                    click: function () {
                        CloseModalDialog(modalId);
                        $.jGrowl('Starting backup despite the space warning...', { life: 6000, themeState: 'info' });
                        rbRetryStartAfterLowSpace();
                    }
                },
                'Replace Destination': {
                    class: 'btn-secondary',
                    click: function () {
                        CloseModalDialog(modalId);
                        rbShowReplaceDestinationPicker(estBytes, currentDest);
                    }
                },
                'Use Failover': {
                    class: 'btn-primary',
                    click: function () {
                        CloseModalDialog(modalId);
                        api('useFailover', { body: {} }).then(function (r) {
                            if (r.ok) { $.jGrowl('Failover activated - retrying backup on SD Card / System Storage.', { life: 6000, themeState: 'success' }); rbRetryStartAfterLowSpace(); }
                            else $.jGrowl('Could not activate failover: ' + (r.error || 'unknown error'), { life: 6000, themeState: 'danger' });
                        });
                    }
                }
            }
        });
    }

    function rbShowReplaceDestinationPicker(neededBytes, currentDest) {
        api('probeStorage').then(function (res) {
            if (!res.ok) { $.jGrowl('Could not list storage: ' + (res.error || 'unknown error'), { life: 6000, themeState: 'danger' }); return; }
            var candidates = [];
            ['nvme', 'ssd', 'usb', 'sdcard'].forEach(function (g) {
                (res.data[g] || []).forEach(function (d) {
                    if (d.mountpoint !== currentDest) candidates.push(d);
                });
            });

            var modalId = 'rb-replace-dest-modal';
            var bodyHtml;
            if (!candidates.length) {
                bodyHtml = '<div class="callout callout-warning">No other mounted storage found. Mount a drive ' +
                    'on the Config page first, or use Failover instead.</div>';
            } else {
                bodyHtml = '<div class="mb-2">Pick a destination' +
                    (neededBytes ? ' with room for the estimated ~' + humanBytesMB(neededBytes) + ' transfer' : '') + ':</div>';
                candidates.forEach(function (d, i) {
                    var enough = neededBytes ? (d.availBytes >= neededBytes) : true;
                    bodyHtml += '<div class="mb-1"><label><input type="radio" name="rb-replace-dest-choice" value="' +
                        d.mountpoint + '" ' + (i === 0 ? 'checked' : '') + '> ' +
                        (d.deviceLabel || d.mountpoint) + (d.label ? ' - volume label "' + d.label + '"' : '') +
                        ' - ' + d.mountpoint + ' - ' + humanBytes(d.availBytes) + ' free' +
                        (enough ? '' : ' <span class="text-danger">(likely still not enough)</span>') + '</label></div>';
                });
            }

            DoModalDialog({
                id: modalId,
                title: 'Replace Destination',
                class: 'modal-m',
                backdrop: true,
                body: bodyHtml,
                buttons: candidates.length ? {
                    Cancel: function () { CloseModalDialog(modalId); },
                    Use: {
                        class: 'btn-primary',
                        click: function () {
                            var chosen = document.querySelector('input[name="rb-replace-dest-choice"]:checked');
                            if (!chosen) return;
                            CloseModalDialog(modalId);
                            api('useDestination', { body: { mountpoint: chosen.value } }).then(function (r) {
                                if (r.ok) { $.jGrowl('Destination switched - retrying backup.', { life: 6000, themeState: 'success' }); rbRetryStartAfterLowSpace(); }
                                else $.jGrowl('Could not switch destination: ' + (r.error || 'unknown error'), { life: 6000, themeState: 'danger' });
                            });
                        }
                    }
                } : { Close: function () { CloseModalDialog(modalId); } }
            });
        });
    }

    // "A scheduled backup skipped/refused something while nobody was
    // watching" popup - driven by lastScheduledPlayOutcome on the same
    // 'status' poll. Unlike the destination-missing/low-space popups
    // above, this reports a past event (a --scheduled run that already
    // finished), not an ongoing condition, so it does NOT reset itself
    // just because the field goes away on its own - the only way it
    // clears is res.lastScheduledPlayOutcome.acknowledged coming back
    // true, which only happens once the user dismisses it (or a newer
    // notice replaces it, itself unacknowledged again).
    var rbPlayOutcomePopupShown = false;

    function rbHandlePlayOutcomeStatus(res) {
        if (!res || !res.ok) return;
        var o = res.lastScheduledPlayOutcome;
        if (!o || o.acknowledged) { rbPlayOutcomePopupShown = false; return; }
        if (rbPlayOutcomePopupShown) return;
        rbPlayOutcomePopupShown = true;
        rbShowPlayOutcomeModal(o);
    }

    function rbShowPlayOutcomeModal(o) {
        var modalId = 'rb-play-outcome-modal';
        var names = (o.remotes || []).join(', ');
        var bodyHtml;
        if (o.refused) {
            bodyHtml = '<div class="callout callout-danger mb-2">A scheduled backup on ' +
                formatLocalTime(o.timestamp) + ' was refused - every remote it would have backed up ' +
                'was currently playing a sequence: <b>' + names + '</b>. Nothing was backed up.</div>';
        } else {
            bodyHtml = '<div class="callout callout-warning mb-2">A scheduled backup on ' +
                formatLocalTime(o.timestamp) + ' completed, but skipped the following remote(s) because ' +
                'they were currently playing a sequence: <b>' + names + '</b>. Everything else selected was ' +
                'backed up normally.</div>';
        }
        DoModalDialog({
            id: modalId,
            title: 'Scheduled Backup - Remote(s) Playing',
            class: 'modal-m',
            backdrop: true,
            body: bodyHtml,
            buttons: {
                OK: {
                    class: 'btn-primary',
                    click: function () {
                        CloseModalDialog(modalId);
                        api('acknowledgePlayOutcome', { body: {} });
                    }
                }
            }
        });
    }

    function humanBytes(n) {
        n = parseInt(n || 0, 10);
        if (!n) return '0 B';
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var i = 0;
        while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
        return n.toFixed(i === 0 ? 0 : 1) + ' ' + units[i];
    }

    // Like humanBytes(), but floors the unit at MB instead of dropping to
    // B/KB for small values - used for the dry-run summary, where B/KB
    // readings (e.g. "762 B" for a near-empty incremental diff) read like
    // something is broken even though they're technically correct.
    function humanBytesMB(n) {
        n = parseInt(n || 0, 10);
        var mb = n / (1024 * 1024);
        if (mb >= 1024) {
            return (mb / 1024).toFixed(2) + ' GB';
        }
        return mb.toFixed(2) + ' MB';
    }

    // Backend timestamps (finishedAt etc.) are always UTC ("Z" suffix,
    // e.g. rb_now_iso() in lib_common.sh) - deliberately timezone-neutral
    // since remotes and the Host can be configured with different system
    // timezones. Showing that raw string as-is reads as flat-out wrong to
    // whoever's looking at it locally (e.g. "15:03:27Z" looks nothing
    // like 10:03:27 AM Central, even though that IS the correct
    // conversion) - convert to the browser's own local time for display.
    function formatLocalTime(iso) {
        if (!iso) return '';
        var d = new Date(iso);
        if (isNaN(d.getTime())) return iso;
        return d.toLocaleString();
    }

    var STATE_LABEL = {
        queued: 'Queued', running: 'Running', done: 'Done',
        'dry-run-complete': 'Dry Run Complete', error: 'Error', skipped: 'Skipped (playing)',
        'done-with-warnings': 'Done (warnings)'
    };

    function updateLogOptions(remotes) {
        var sel = document.getElementById('rb-log-which');
        var current = sel.value;
        var fixed = ['ajax', 'engine', 'clone'];
        // Drop stale remote: options, then re-add one per remote currently known.
        Array.prototype.slice.call(sel.options).forEach(function (opt) {
            if (fixed.indexOf(opt.value) === -1) sel.removeChild(opt);
        });
        remotes.forEach(function (r) {
            var opt = document.createElement('option');
            opt.value = 'remote:' + r.id;
            opt.textContent = (r.hostname || r.id) + ' rsync log';
            sel.appendChild(opt);
        });
        if (Array.prototype.some.call(sel.options, function (o) { return o.value === current; })) {
            sel.value = current;
        }
    }

    function renderStatus(data) {
        var body = document.getElementById('rb-status-body');
        var remotes = data.remotes || [];
        updateLogOptions(remotes);

        // "Backup started."/"Dry run started."/"Stopped." (set by the
        // button click handlers below) is only ever meant as immediate
        // feedback for the moment right after clicking - once a run is no
        // longer active, that leftover text has nothing to do with the
        // current state and was otherwise never cleared, so it just sat
        // there forever, same issue the Clone section had.
        if (!data.active) document.getElementById('rb-runMsg').textContent = '';
        if (!remotes.length) {
            body.innerHTML = '<tr><td colspan="7">No backup has been run yet.</td></tr>';
        } else {
            remotes.sort(function (a, b) { return (a.hostname || '').localeCompare(b.hostname || ''); });
            body.innerHTML = remotes.map(function (r) {
                var label = STATE_LABEL[r.state] || r.state;
                var xfer = (r.filesTransferred != null && r.totalFiles != null) ? (r.filesTransferred + ' of ' + r.totalFiles + ' files') : '-';
                var fileCell = (r.state === 'error' || r.state === 'skipped' || r.state === 'done-with-warnings')
                    ? '<span class="' + (r.state === 'error' ? 'text-danger' : 'text-warning') + '" title="' + (r.logFile || '') + '">' + (r.errorDetail || 'Unknown error - see data/logs/ajax.log or ' + (r.logFile || 'the run log')) + '</span>'
                    : (r.currentFile || '');
                var progressCell = '';
                if (r.percent != null) {
                    var barClass = 'progress-bar';
                    if (r.state === 'running') barClass += ' progress-bar-striped progress-bar-animated';
                    else if (r.state === 'error') barClass += ' bg-danger';
                    else if (r.state === 'done-with-warnings') barClass += ' bg-warning';
                    else if (r.state === 'done' || r.state === 'dry-run-complete') barClass += ' bg-success';
                    progressCell = '<div class="progress" style="height:1.2em;min-width:90px;">' +
                        '<div class="' + barClass + '" role="progressbar" style="width:' + r.percent + '%;" ' +
                        'aria-valuenow="' + r.percent + '" aria-valuemin="0" aria-valuemax="100">' + r.percent + '%</div></div>';
                }
                return '<tr>' +
                    '<td>' + (r.hostname || r.id) + '</td>' +
                    '<td>' + (r.address || '') + '</td>' +
                    '<td>' + label + '</td>' +
                    '<td style="max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + ((r.currentFile || r.errorDetail || '')).replace(/"/g, '&quot;') + '">' + fileCell + '</td>' +
                    '<td>' + progressCell + '</td>' +
                    '<td>' + xfer + '</td>' +
                    '<td>' + (r.target || '') + '</td>' +
                    '</tr>';
            }).join('');
        }

        var destEl = document.getElementById('rb-dest-storage');
        if (data.destStorage) {
            var d = data.destStorage;
            var pct = d.totalBytes ? Math.round((d.usedBytes / d.totalBytes) * 100) : 0;
            destEl.textContent = 'Host storage (' + d.mountpoint + ')' + (d.label ? ' [' + d.label + ']' : '') + ': ' +
                humanBytes(d.usedBytes) + ' used / ' + humanBytes(d.freeBytes) + ' free of ' + humanBytes(d.totalBytes) +
                ' (' + pct + '% used)';
        } else {
            destEl.textContent = 'Host storage: not mounted or not configured - check Config > Storage.';
        }

        var panel = document.getElementById('rb-dryrun-panel');
        if (data.dryRunSummary) {
            panel.style.display = '';
            var s = data.dryRunSummary;
            var verdict = s.sufficient === null ? 'Unknown (destination not mounted?)'
                : (s.sufficient ? '<span class="text-success">Sufficient space available</span>' : '<span class="text-danger">NOT enough free space on destination</span>');
            document.getElementById('rb-dryrun-summary').innerHTML =
                'Estimated total transfer: <b>' + humanBytesMB(s.estimatedTotalBytes) + '</b><br>' +
                'Available on destination: <b>' + (s.availableBytes != null ? humanBytesMB(s.availableBytes) : 'unknown') + '</b><br>' +
                verdict;
        } else {
            panel.style.display = 'none';
        }
    }

    // Turns Dry Run/Start Backup/Start Clone green the instant they're
    // clicked and back to their normal color once the run they started
    // actually finishes - purely a "yes, that click registered" signal,
    // not a state indicator (the label/progress bar/State column already
    // cover that). Exact original classNames hardcoded here rather than
    // computed from the DOM at click time, since these three buttons'
    // classes are otherwise static (nothing else in this file rewrites
    // their className - only .disabled, a different property, is ever
    // toggled on rb-clone-start).
    var BTN_STATE = {
        'rb-dryrun': { normal: 'btn btn-outline-secondary btn-sm', active: 'btn btn-success btn-sm' },
        'rb-start': { normal: 'btn btn-primary btn-sm ms-1', active: 'btn btn-success btn-sm ms-1' },
        'rb-clone-start': { normal: 'btn btn-outline-secondary btn-sm', active: 'btn btn-success btn-sm' }
    };
    var BTN_GREEN_TIMEOUT_MS = 60000; // safety net - see markButtonActive
    var btnGreenTimers = {};

    function markButtonActive(id) {
        var btn = document.getElementById(id);
        var s = BTN_STATE[id];
        if (!btn || !s) return;
        btn.className = s.active;
        // Safety net: if the run this click started finishes so fast the
        // polling loop's active->inactive transition is missed entirely
        // (e.g. a dry run against one small/unreachable remote, faster
        // than the 2s active-poll interval), or the poll loop's own
        // detection logic has a bug, this guarantees the button doesn't
        // stay green forever - clearButtonActive() below cancels this
        // timer on the normal path, so it never fires in the common case.
        if (btnGreenTimers[id]) clearTimeout(btnGreenTimers[id]);
        btnGreenTimers[id] = setTimeout(function () { clearButtonActive(id); }, BTN_GREEN_TIMEOUT_MS);
    }

    function clearButtonActive(id) {
        var btn = id && document.getElementById(id);
        var s = id && BTN_STATE[id];
        if (!btn || !s) return;
        btn.className = s.normal;
        if (btnGreenTimers[id]) { clearTimeout(btnGreenTimers[id]); delete btnGreenTimers[id]; }
    }

    // Dry Run and Start Backup share the one primary run/active flag
    // (status.php's poll() below), so which button to revert once that
    // run finishes has to be tracked separately from the flag itself.
    var pendingRunButtonId = null;

    var lastActiveSeen = false;
    var POLL_ACTIVE_MS = 2000;   // fast refresh while a backup is actually running
    var POLL_IDLE_MS = 7000;     // slower background refresh otherwise, so the page
                                  // stays live (e.g. a scheduled/Command-triggered run
                                  // starting elsewhere) without needing a manual reload

    // Runs continuously for as long as the page is open - never stops
    // rescheduling itself, just changes how often depending on whether
    // a run is active.
    function poll() {
        api('status').then(function (res) {
            if (res.ok) renderStatus(res);
            if (res.ok) rbHandleDestinationStatus(res);
            if (res.ok) rbHandleLowSpaceStatus(res);
            if (res.ok) rbHandlePlayOutcomeStatus(res);
            if (res.ok && lastActiveSeen && !res.active) {
                // A run just finished - refresh the Backed Up list so new/updated folders show up.
                if (typeof loadBackedUpList === 'function') loadBackedUpList(true);
                clearButtonActive(pendingRunButtonId);
                pendingRunButtonId = null;
            }
            if (res.ok) lastActiveSeen = !!res.active;
            if (pollTimer) clearTimeout(pollTimer);
            pollTimer = setTimeout(poll, res.active ? POLL_ACTIVE_MS : POLL_IDLE_MS);
        }).catch(function () {
            // Network hiccup - keep trying rather than going silent.
            if (pollTimer) clearTimeout(pollTimer);
            pollTimer = setTimeout(poll, POLL_IDLE_MS);
        });
    }

    // Separate polling loop from the primary backup one above - a clone
    // to the second drive has its own active/status file
    // (data/clone_active.json, data/clone_status.json) precisely so it
    // never gets confused with a primary backup run in the main Backup
    // Status table or the "active" flag the page's title/polling cadence
    // above reacts to.
    var clonePollTimer = null;
    var lastCloneActiveSeen = false;

    function renderCloneStatus(res) {
        var secEl = document.getElementById('rb-clone-secondary-storage');
        var mounted = !!res.secondaryStorage;
        if (mounted) {
            var d = res.secondaryStorage;
            var pct = d.totalBytes ? Math.round((d.usedBytes / d.totalBytes) * 100) : 0;
            secEl.className = 'p-1';
            secEl.innerHTML = 'Secondary drive (' + d.mountpoint + ')' + (d.label ? ' [' + d.label + ']' : '') + ': ' +
                humanBytes(d.usedBytes) + ' used / ' + humanBytes(d.freeBytes) + ' free of ' + humanBytes(d.totalBytes) +
                ' (' + pct + '% used)';
        } else {
            // Visually distinct from the normal muted status line, not just
            // present in the DOM - easy to skim past as plain gray text
            // otherwise, and this is exactly the state where clicking
            // "Start Clone" would previously do nothing useful.
            secEl.className = 'p-1 text-danger';
            secEl.innerHTML = '<b>Secondary drive not mounted</b> - format/mount it on the Config page first.';
        }

        document.getElementById('rb-clone-start').disabled = !!res.active || !mounted;

        var c = res.clone;
        var progress = document.getElementById('rb-clone-progress');
        var resultEl = document.getElementById('rb-clone-result');
        if (res.active && c && c.state === 'running') {
            progress.style.display = '';
            document.getElementById('rb-clone-current').textContent = c.currentFile || '';
            var bar = document.getElementById('rb-clone-bar');
            var pct2 = c.percent || 0;
            bar.style.width = pct2 + '%';
            bar.textContent = pct2 + '%';
            bar.className = 'progress-bar progress-bar-striped progress-bar-animated';
            resultEl.textContent = '';
        } else {
            progress.style.display = 'none';
            // "Clone started."/"Stopped." (set by the button click handlers
            // below) is only ever meant as immediate feedback for the
            // moment right after clicking - once a poll comes back with a
            // real, definitive state to show, that leftover text has
            // nothing to do with the CURRENT state and was otherwise never
            // cleared, so it just sat there forever looking like the page
            // was stuck on "Clone started." even after the clone actually
            // finished (or failed) and the line below correctly updated.
            document.getElementById('rb-clone-msg').textContent = '';
            if (c && c.state === 'done') {
                resultEl.innerHTML = '<span class="text-success">Last clone finished ' + formatLocalTime(c.finishedAt) + ' - ' + humanBytes(c.transferredBytes) + ' transferred.</span>';
            } else if (c && c.state === 'error') {
                resultEl.innerHTML = '<span class="text-danger">Last clone failed: ' + (c.errorDetail || 'unknown error') + '</span>';
            } else {
                resultEl.textContent = '';
            }
        }
    }

    function pollClone() {
        api('cloneStatus').then(function (res) {
            if (res.ok) renderCloneStatus(res);
            if (res.ok && lastCloneActiveSeen && !res.active) {
                clearButtonActive('rb-clone-start');
            }
            if (res.ok) lastCloneActiveSeen = !!res.active;
            if (clonePollTimer) clearTimeout(clonePollTimer);
            clonePollTimer = setTimeout(pollClone, (res.ok && res.active) ? POLL_ACTIVE_MS : POLL_IDLE_MS);
        }).catch(function () {
            if (clonePollTimer) clearTimeout(clonePollTimer);
            clonePollTimer = setTimeout(pollClone, POLL_IDLE_MS);
        });
    }

    document.getElementById('rb-clone-start').addEventListener('click', function () {
        api('startClone', { body: {} }).then(function (res) {
            var msg = document.getElementById('rb-clone-msg');
            msg.textContent = res.ok ? 'Clone started.' : ('Error: ' + res.error);
            msg.className = res.ok ? 'ms-2 text-success' : 'ms-2 text-danger';
            if (res.ok) { markButtonActive('rb-clone-start'); pollClone(); } else { $.jGrowl('Failed to start clone: ' + res.error, { life: 6000, themeState: 'danger' }); }
        });
    });

    document.getElementById('rb-clone-stop').addEventListener('click', function () {
        api('stopClone', { body: {} }).then(function () {
            document.getElementById('rb-clone-msg').textContent = 'Stopped.';
            pollClone();
        });
    });

    function getSelectedRemoteIds() {
        return api('loadSettings').then(function (res) {
            return (res.data.remotes || []).filter(function (r) { return r.selected; }).map(function (r) { return r.id; });
        });
    }

    document.getElementById('rb-start').addEventListener('click', function () {
        getSelectedRemoteIds().then(function (ids) {
            if (!ids.length) { $.jGrowl('No remotes are selected. Go to Config and select at least one.', { life: 6000, themeState: 'danger' }); return; }
            api('start', { body: { remotes: ids, dryRun: false } }).then(function (res) {
                var msg = document.getElementById('rb-runMsg');
                msg.textContent = res.ok ? 'Backup started.' : ('Error: ' + res.error);
                msg.className = res.ok ? 'ms-2 text-success' : 'ms-2 text-danger';
                if (res.ok) {
                    pendingRunButtonId = 'rb-start'; markButtonActive('rb-start'); poll();
                    if (res.skippedPlaying && res.skippedPlaying.length) {
                        $.jGrowl('Skipping ' + res.skippedPlaying.join(', ') + ' - currently playing. Continuing with the rest.', { life: 6000, themeState: 'warning' });
                    }
                } else { $.jGrowl('Failed to start backup: ' + res.error, { life: 6000, themeState: 'danger' }); }
            });
        });
    });

    document.getElementById('rb-dryrun').addEventListener('click', function () {
        getSelectedRemoteIds().then(function (ids) {
            if (!ids.length) { $.jGrowl('No remotes are selected. Go to Config and select at least one.', { life: 6000, themeState: 'danger' }); return; }
            api('start', { body: { remotes: ids, dryRun: true } }).then(function (res) {
                var msg = document.getElementById('rb-runMsg');
                msg.textContent = res.ok ? 'Dry run started.' : ('Error: ' + res.error);
                msg.className = res.ok ? 'ms-2 text-success' : 'ms-2 text-danger';
                if (res.ok) {
                    pendingRunButtonId = 'rb-dryrun'; markButtonActive('rb-dryrun'); poll();
                    if (res.skippedPlaying && res.skippedPlaying.length) {
                        $.jGrowl('Skipping ' + res.skippedPlaying.join(', ') + ' - currently playing. Continuing with the rest.', { life: 6000, themeState: 'warning' });
                    }
                } else { $.jGrowl('Failed to start dry run: ' + res.error, { life: 6000, themeState: 'danger' }); }
            });
        });
    });

    document.getElementById('rb-stop').addEventListener('click', function () {
        api('stop', { body: {} }).then(function (res) {
            document.getElementById('rb-runMsg').textContent = 'Stopped.';
        });
    });

    var logTailTimer = null;
    var AUTOTAIL_STORAGE_KEY = 'rb-log-autotail';
    var ERRORS_ONLY_STORAGE_KEY = 'rb-log-errors-only';

    // Tail Follow used to always be checked on page load, polling the log
    // every 3s whether or not anyone was watching it. Persist the user's
    // choice instead (localStorage, may be unavailable in some contexts -
    // fails silently and just falls back to "off" every load) so leaving
    // the Status page open doesn't churn the ajax.log endpoint by default.
    function loadAutotailPref() {
        try {
            return localStorage.getItem(AUTOTAIL_STORAGE_KEY) === '1';
        } catch (e) {
            return false;
        }
    }
    function saveAutotailPref(on) {
        try {
            localStorage.setItem(AUTOTAIL_STORAGE_KEY, on ? '1' : '0');
        } catch (e) {
            // ignore - just won't persist this session
        }
    }
    function loadErrorsOnlyPref() {
        try {
            return localStorage.getItem(ERRORS_ONLY_STORAGE_KEY) === '1';
        } catch (e) {
            return false;
        }
    }
    function saveErrorsOnlyPref(on) {
        try {
            localStorage.setItem(ERRORS_ONLY_STORAGE_KEY, on ? '1' : '0');
        } catch (e) {
            // ignore - just won't persist this session
        }
    }

    // Matches this plugin's own log-line prefixes (ABORT/ERROR/WARN/FAIL,
    // LOW SPACE, RECOVERED - see engine.log/ajax.log) plus the rsync/ssh
    // failure text that shows up in a per-remote rsync log (rsync itself
    // doesn't use any of those prefixes), and a non-zero "rc=" from a
    // "finished rsync ..." line - covers a real failure (rc=1, 255, etc.)
    // and the "done, but rsync flagged something" rc=24 case alike, without
    // needing every log's format to agree on one convention. Client-side
    // only: filters whatever was already fetched, so toggling this doesn't
    // need a fresh request.
    var LOG_PROBLEM_RE = /abort|error|warn|fail|vanished|low space|recovered|connection refused|no route to host|permission denied|host key verification failed|rc=(?!0\b)\d+/i;

    var lastLogContent = '';

    function renderLogContent() {
        var pre = document.getElementById('rb-log-content');
        if (!document.getElementById('rb-log-errors-only').checked) {
            pre.textContent = lastLogContent || '(empty)';
            return;
        }
        var lines = lastLogContent.split('\n').filter(function (l) { return LOG_PROBLEM_RE.test(l); });
        pre.textContent = lines.length ? lines.join('\n') : '(no errors/warnings found in this log)';
    }

    function loadLog(silent) {
        var which = document.getElementById('rb-log-which').value;
        var pre = document.getElementById('rb-log-content');
        if (!silent) pre.textContent = 'Loading...';
        // Only auto-scroll to the new bottom if the user was already
        // reading near the bottom - don't yank their view while they're
        // scrolled up looking at earlier lines.
        var wasAtBottom = (pre.scrollTop + pre.clientHeight) >= (pre.scrollHeight - 20);
        fetch(AJAX + 'getLog&which=' + encodeURIComponent(which)).then(function (r) { return r.text(); }).then(function (txt) {
            var data;
            try { data = JSON.parse(txt); } catch (e) { pre.textContent = 'Non-JSON response, raw output:\n' + txt; return; }
            if (data.ok) {
                var pathText = data.file;
                if (data.truncated) {
                    pathText += '  (showing last ' + data.shownLines + ' of ' + data.totalLines + ' lines)';
                }
                document.getElementById('rb-log-path').textContent = pathText;
                lastLogContent = data.content || '';
                renderLogContent();
                if (wasAtBottom || !silent) pre.scrollTop = pre.scrollHeight;
            } else {
                lastLogContent = '';
                pre.textContent = 'Error: ' + data.error;
            }
        }).catch(function (err) {
            if (!silent) pre.textContent = 'Request failed: ' + err.message;
        });
    }

    function scheduleLogTail() {
        if (logTailTimer) { clearTimeout(logTailTimer); logTailTimer = null; }
        if (!document.getElementById('rb-log-autotail').checked) return;
        logTailTimer = setTimeout(function () {
            loadLog(true);
            scheduleLogTail();
        }, 3000);
    }

    document.getElementById('rb-log-refresh').addEventListener('click', function () { loadLog(false); });

    // Shared by both Download buttons below - the endpoint streams a raw
    // file (text/plain or application/zip) on success, but still reports
    // failures (missing log, zip not installed, etc.) as a normal JSON
    // error body, same as every other action on this page. Checking the
    // response's own Content-Type is how this tells the two apart, since
    // both come back as an HTTP 200/4xx/5xx from the same fetch() either
    // way. Live status text in #rb-log-download-status covers the gap
    // between "clicked" and "browser actually has the file," which for
    // Download All in particular isn't always instant - it has to zip
    // data/logs/ server-side first.
    function downloadLogFile(url, btn, preparingText) {
        var statusEl = document.getElementById('rb-log-download-status');
        var resetLabel = btn.textContent;
        btn.disabled = true;
        btn.textContent = preparingText;
        statusEl.textContent = preparingText;
        // no-store: this URL never varies (no cache-busting query param), so
        // without this a browser can serve a cached response from an earlier
        // click instead of hitting the server again - belt-and-suspenders
        // alongside ajax.php's own Cache-Control: no-store on these responses.
        fetch(url, { cache: 'no-store' }).then(function (r) {
            var ct = r.headers.get('Content-Type') || '';
            if (ct.indexOf('application/json') !== -1) {
                return r.json().then(function (data) {
                    throw new Error(data.error || 'Download failed');
                });
            }
            var cd = r.headers.get('Content-Disposition') || '';
            var m = /filename="([^"]+)"/.exec(cd);
            var filename = m ? m[1] : 'RemoteBackup-download';
            return r.blob().then(function (blob) { return { blob: blob, filename: filename }; });
        }).then(function (res) {
            var objUrl = URL.createObjectURL(res.blob);
            var a = document.createElement('a');
            a.href = objUrl;
            a.download = res.filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(objUrl);
            statusEl.textContent = 'Downloaded ' + res.filename + ' (' + humanBytes(res.blob.size) + ').';
            btn.disabled = false;
            btn.textContent = resetLabel;
        }).catch(function (err) {
            statusEl.textContent = 'Download failed: ' + err.message;
            $.jGrowl('Download failed: ' + err.message, { life: 6000, themeState: 'danger' });
            btn.disabled = false;
            btn.textContent = resetLabel;
        });
    }

    document.getElementById('rb-log-download').addEventListener('click', function () {
        var which = document.getElementById('rb-log-which').value;
        downloadLogFile(AJAX + 'downloadLog&which=' + encodeURIComponent(which), this, 'Preparing download...');
    });

    document.getElementById('rb-log-download-all').addEventListener('click', function () {
        downloadLogFile(AJAX + 'downloadAllLogs', this, 'Preparing archive...');
    });
    document.getElementById('rb-log-which').addEventListener('change', function () { loadLog(false); scheduleLogTail(); });
    document.getElementById('rb-log-autotail').addEventListener('change', function (e) {
        saveAutotailPref(e.target.checked);
        scheduleLogTail();
    });
    document.getElementById('rb-log-errors-only').addEventListener('change', function (e) {
        saveErrorsOnlyPref(e.target.checked);
        renderLogContent();
    });

    document.getElementById('rb-log-autotail').checked = loadAutotailPref();
    document.getElementById('rb-log-errors-only').checked = loadErrorsOnlyPref();
    loadLog(false);
    scheduleLogTail();

    function formatDate(yyyymmdd) {
        if (!/^[0-9]{8}$/.test(yyyymmdd)) return yyyymmdd;
        return yyyymmdd.slice(0, 4) + '-' + yyyymmdd.slice(4, 6) + '-' + yyyymmdd.slice(6, 8);
    }

    function loadBackedUpList(keepSelection) {
        var sel = document.getElementById('rb-backedup-select');
        var prev = keepSelection ? sel.value : '';
        fetch(AJAX + 'listBackups').then(function (r) { return r.text(); }).then(function (txt) {
            var data;
            try { data = JSON.parse(txt); } catch (e) { sel.innerHTML = '<option value="">(error loading list)</option>'; return; }
            if (!data.ok) { sel.innerHTML = '<option value="">(' + (data.error || 'error') + ')</option>'; return; }
            var backups = data.backups || [];
            if (!backups.length) {
                sel.innerHTML = '<option value="">(no backups yet)</option>';
                document.getElementById('rb-backedup-info').style.display = 'none';
                return;
            }
            sel.innerHTML = '<option value="">Select a backup...</option>' + backups.map(function (b) {
                return '<option value="' + b.path.replace(/"/g, '&quot;') + '">' + b.id + ' - ' + formatDate(b.date) + '</option>';
            }).join('');
            if (prev && Array.prototype.some.call(sel.options, function (o) { return o.value === prev; })) {
                sel.value = prev;
            }
        }).catch(function () {
            sel.innerHTML = '<option value="">(request failed)</option>';
        });
    }

    function showBackupInfo(path) {
        var panel = document.getElementById('rb-backedup-info');
        if (!path) { panel.style.display = 'none'; return; }
        panel.style.display = '';
        panel.innerHTML = 'Loading...';
        fetch(AJAX + 'getBackupInfo&path=' + encodeURIComponent(path)).then(function (r) { return r.text(); }).then(function (txt) {
            var data;
            try { data = JSON.parse(txt); } catch (e) { panel.textContent = 'Error reading backup info.'; return; }
            if (!data.ok) { panel.textContent = 'Error: ' + data.error; return; }
            var html = '<div style="display:flex; justify-content:space-between; align-items:center;">' +
                '<b>' + data.path + '</b>' +
                '<button type="button" class="btn btn-outline-danger btn-sm" id="rb-delete-backup">Delete This Backup</button>' +
                '</div>';
            html += '<div>' + humanBytes(data.sizeBytes) + ' across ' + data.fileCount + ' file(s)</div>';
            if (data.entries && data.entries.length) {
                html += '<table class="table table-sm mt-1"><tr><th>Name</th><th>Size</th></tr>';
                data.entries.forEach(function (e) {
                    html += '<tr><td>' + e.name + (e.isDir ? '/' : '') + '</td><td>' + humanBytes(e.sizeBytes) + '</td></tr>';
                });
                html += '</table>';
            }
            panel.innerHTML = html;

            document.getElementById('rb-delete-backup').addEventListener('click', function () {
                var modalId = 'rb-delete-backup-modal';
                // The plugin already auto-fills/knows the exact folder being
                // deleted (shown right above), so typing it back is just
                // busywork, not a real extra safety check - a checkbox that
                // requires reading the folder name shown above and
                // deliberately ticking it before Delete accepts serves the
                // same purpose without that.
                var bodyHtml =
                    '<div class="callout callout-danger mb-2">Delete this backup? This cannot be undone.<br>' +
                    '<code>' + path.replace(/</g, '&lt;') + '</code><br>' + humanBytes(data.sizeBytes) + '</div>' +
                    '<div class="form-check">' +
                    '<input type="checkbox" id="rb-delete-confirm" class="form-check-input">' +
                    '<label class="form-check-label" for="rb-delete-confirm">Confirm the backup folder being deleted</label>' +
                    '</div>';

                DoModalDialog({
                    id: modalId,
                    title: 'Delete Backup',
                    class: 'modal-m',
                    backdrop: true,
                    body: bodyHtml,
                    buttons: {
                        Cancel: function () { CloseModalDialog(modalId); },
                        Delete: {
                            class: 'btn-danger',
                            click: function () {
                                if (!document.getElementById('rb-delete-confirm').checked) {
                                    $.jGrowl('Confirm the backup folder being deleted first - aborted, nothing was deleted.', { life: 6000, themeState: 'danger' });
                                    return;
                                }
                                CloseModalDialog(modalId);
                                doDeleteBackup(path);
                            }
                        }
                    }
                });
            });

            function doDeleteBackup(path) {
                var btn = document.getElementById('rb-delete-backup');
                btn.disabled = true;
                btn.textContent = 'Deleting...';
                fetch(AJAX + 'deleteBackup', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ path: path, confirm: 'I_UNDERSTAND_THIS_DELETES_THE_BACKUP' })
                }).then(function (r) { return r.text(); }).then(function (txt) {
                    var res;
                    try { res = JSON.parse(txt); } catch (e) { $.jGrowl('Non-JSON response deleting backup.', { life: 6000, themeState: 'danger' }); return; }
                    if (res.ok) {
                        $.jGrowl('Backup deleted.', { life: 6000, themeState: 'success' });
                        panel.style.display = 'none';
                        document.getElementById('rb-backedup-select').value = '';
                        loadBackedUpList(false);
                        // Refresh the main Backup Status table right away too, so a
                        // remote whose backup we just deleted stops showing "Done"
                        // with a Backup Folder that no longer exists.
                        poll();
                    } else {
                        $.jGrowl('Delete failed: ' + (res.error || 'unknown error'), { life: 6000, themeState: 'danger' });
                        btn.disabled = false;
                        btn.textContent = 'Delete This Backup';
                    }
                }).catch(function (err) {
                    $.jGrowl('Request failed: ' + err.message, { life: 6000, themeState: 'danger' });
                    btn.disabled = false;
                    btn.textContent = 'Delete This Backup';
                });
            }
        }).catch(function () {
            panel.textContent = 'Request failed.';
        });
    }

    document.getElementById('rb-backedup-select').addEventListener('change', function (e) {
        showBackupInfo(e.target.value);
    });
    document.getElementById('rb-backedup-refresh').addEventListener('click', function () {
        loadBackedUpList(true);
    });

    loadBackedUpList(false);

    // "?" in circle help popovers on the Dry Run / Start Backup / Config
    // buttons, matching the fpp-help-popover pattern FPP's own
    // system-stats.php page uses (fa-question-circle icon + a hidden
    // #<id> div holding the popover body, wired up as a Bootstrap
    // popover). Scoped to #rb-status so this never touches icons any
    // other plugin/page might add with the same class.
    document.querySelectorAll('#rb-status .fpp-help-popover').forEach(function (icon) {
        var contentEl = document.getElementById(icon.dataset.helpContent);
        if (contentEl && typeof bootstrap !== 'undefined' && bootstrap.Popover) {
            new bootstrap.Popover(icon, {
                title: icon.dataset.helpTitle || '',
                content: contentEl.innerHTML,
                html: true,
                trigger: 'hover focus',
                placement: 'bottom',
                sanitize: false
            });
        }
    });

    // Browsers throttle setTimeout/setInterval heavily in a backgrounded
    // or minimized tab - easy to hit here since a clone of the whole
    // backup set can run for many minutes, well past when someone tabs
    // away to do something else. The poll chains eventually still fire on
    // their own, but "eventually" can be a very long wait once throttled,
    // which reads as the page having silently stopped updating even
    // though the clone (or backup) actually finished normally. Re-poll
    // immediately the moment the tab becomes visible again instead of
    // waiting on whatever throttled timer was still pending - both poll()
    // and pollClone() already clear their own pending timer before
    // rescheduling, so this can't create a duplicate polling chain.
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            poll();
            pollClone();
        }
    });

    poll();
    pollClone();
})();
</script>
