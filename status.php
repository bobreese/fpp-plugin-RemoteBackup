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
                <button type="button" class="btn btn-primary btn-sm" id="rb-start">Start Backup</button>
                <button type="button" class="btn btn-danger btn-sm" id="rb-stop">Stop</button>
                <span id="rb-runMsg" class="ms-2"></span>
            </div>
            <div style="text-align:right;">
                <label for="rb-backedup-select"><b>Backed Up</b></label><br>
                <select id="rb-backedup-select" style="min-width:220px;">
                    <option value="">(loading...)</option>
                </select>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="rb-backedup-refresh" title="Rescan storage">&#8635;</button>
            </div>
        </div>
        <div class="p-2" id="rb-dest-storage" style="font-size:0.9em; color:#555;">Host storage: (loading...)</div>
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
        <legend>Diagnostic Log</legend>
        <div class="p-2">
            <select id="rb-log-which">
                <option value="ajax">ajax.log (Config/Status page actions)</option>
                <option value="engine">engine.log (backup run engine)</option>
            </select>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="rb-log-refresh">Refresh Log</button>
            <label class="ms-2"><input type="checkbox" id="rb-log-autotail" checked> Auto-tail</label>
            <div><small id="rb-log-path" style="color:#666;"></small></div>
            <pre id="rb-log-content" style="max-height:300px;overflow:auto;background:#f7f7f7;border:1px solid #ddd;padding:6px;margin-top:6px;">(not loaded yet)</pre>
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

    function humanBytes(n) {
        n = parseInt(n || 0, 10);
        if (!n) return '0 B';
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var i = 0;
        while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
        return n.toFixed(i === 0 ? 0 : 1) + ' ' + units[i];
    }

    var STATE_LABEL = {
        queued: 'Queued', running: 'Running', done: 'Done',
        'dry-run-complete': 'Dry Run Complete', error: 'Error'
    };

    function updateLogOptions(remotes) {
        var sel = document.getElementById('rb-log-which');
        var current = sel.value;
        var fixed = ['ajax', 'engine'];
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
        if (!remotes.length) {
            body.innerHTML = '<tr><td colspan="7">No backup has been run yet.</td></tr>';
        } else {
            remotes.sort(function (a, b) { return (a.hostname || '').localeCompare(b.hostname || ''); });
            body.innerHTML = remotes.map(function (r) {
                var label = STATE_LABEL[r.state] || r.state;
                var xfer = (r.filesTransferred != null && r.totalFiles != null) ? (r.filesTransferred + ' of ' + r.totalFiles + ' files') : '-';
                var fileCell = (r.state === 'error')
                    ? '<span style="color:#a00" title="' + (r.logFile || '') + '">' + (r.errorDetail || 'Unknown error - see data/logs/ajax.log or ' + (r.logFile || 'the run log')) + '</span>'
                    : (r.currentFile || '');
                return '<tr>' +
                    '<td>' + (r.hostname || r.id) + '</td>' +
                    '<td>' + (r.address || '') + '</td>' +
                    '<td>' + label + '</td>' +
                    '<td style="max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + ((r.currentFile || r.errorDetail || '')).replace(/"/g, '&quot;') + '">' + fileCell + '</td>' +
                    '<td>' + (r.percent != null ? r.percent + '%' : '') + '</td>' +
                    '<td>' + xfer + '</td>' +
                    '<td>' + (r.target || '') + '</td>' +
                    '</tr>';
            }).join('');
        }

        var destEl = document.getElementById('rb-dest-storage');
        if (data.destStorage) {
            var d = data.destStorage;
            var pct = d.totalBytes ? Math.round((d.usedBytes / d.totalBytes) * 100) : 0;
            destEl.textContent = 'Host storage (' + d.mountpoint + '): ' +
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
                : (s.sufficient ? '<span style="color:green">Sufficient space available</span>' : '<span style="color:red">NOT enough free space on destination</span>');
            document.getElementById('rb-dryrun-summary').innerHTML =
                'Estimated total transfer: <b>' + humanBytes(s.estimatedTotalBytes) + '</b><br>' +
                'Available on destination: <b>' + (s.availableBytes != null ? humanBytes(s.availableBytes) : 'unknown') + '</b><br>' +
                verdict;
        } else {
            panel.style.display = 'none';
        }
    }

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
            if (res.ok && lastActiveSeen && !res.active) {
                // A run just finished - refresh the Backed Up list so new/updated folders show up.
                if (typeof loadBackedUpList === 'function') loadBackedUpList(true);
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

    function getSelectedRemoteIds() {
        return api('loadSettings').then(function (res) {
            return (res.data.remotes || []).filter(function (r) { return r.selected; }).map(function (r) { return r.id; });
        });
    }

    document.getElementById('rb-start').addEventListener('click', function () {
        getSelectedRemoteIds().then(function (ids) {
            if (!ids.length) { alert('No remotes are selected. Go to Config and select at least one.'); return; }
            api('start', { body: { remotes: ids, dryRun: false } }).then(function (res) {
                var msg = document.getElementById('rb-runMsg');
                msg.textContent = res.ok ? 'Backup started.' : ('Error: ' + res.error);
                if (res.ok) { poll(); }
            });
        });
    });

    document.getElementById('rb-dryrun').addEventListener('click', function () {
        getSelectedRemoteIds().then(function (ids) {
            if (!ids.length) { alert('No remotes are selected. Go to Config and select at least one.'); return; }
            api('start', { body: { remotes: ids, dryRun: true } }).then(function (res) {
                var msg = document.getElementById('rb-runMsg');
                msg.textContent = res.ok ? 'Dry run started.' : ('Error: ' + res.error);
                if (res.ok) { poll(); }
            });
        });
    });

    document.getElementById('rb-stop').addEventListener('click', function () {
        api('stop', { body: {} }).then(function (res) {
            document.getElementById('rb-runMsg').textContent = 'Stopped.';
        });
    });

    var logTailTimer = null;

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
                document.getElementById('rb-log-path').textContent = data.file;
                pre.textContent = data.content || '(empty)';
                if (wasAtBottom || !silent) pre.scrollTop = pre.scrollHeight;
            } else {
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
    document.getElementById('rb-log-which').addEventListener('change', function () { loadLog(false); scheduleLogTail(); });
    document.getElementById('rb-log-autotail').addEventListener('change', scheduleLogTail);

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
                var name = path.split('/').filter(Boolean).pop();
                if (!confirm('Delete this backup?\n\n' + path + '\n(' + humanBytes(data.sizeBytes) + ')\n\nThis cannot be undone.')) return;
                var typed = prompt('Type the backup folder name exactly to confirm deletion: ' + name);
                if (typed !== name) { alert('Confirmation text did not match "' + name + '" - aborted, nothing was deleted.'); return; }

                var btn = document.getElementById('rb-delete-backup');
                btn.disabled = true;
                btn.textContent = 'Deleting...';
                fetch(AJAX + 'deleteBackup', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ path: path, confirm: 'I_UNDERSTAND_THIS_DELETES_THE_BACKUP' })
                }).then(function (r) { return r.text(); }).then(function (txt) {
                    var res;
                    try { res = JSON.parse(txt); } catch (e) { alert('Non-JSON response deleting backup.'); return; }
                    if (res.ok) {
                        panel.style.display = 'none';
                        document.getElementById('rb-backedup-select').value = '';
                        loadBackedUpList(false);
                        // Refresh the main Backup Status table right away too, so a
                        // remote whose backup we just deleted stops showing "Done"
                        // with a Backup Folder that no longer exists.
                        poll();
                    } else {
                        alert('Delete failed: ' + (res.error || 'unknown error'));
                        btn.disabled = false;
                        btn.textContent = 'Delete This Backup';
                    }
                }).catch(function (err) {
                    alert('Request failed: ' + err.message);
                    btn.disabled = false;
                    btn.textContent = 'Delete This Backup';
                });
            });
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

    poll();
})();
</script>
