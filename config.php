<?php
// Don't rely on FPP's own $plugin/$pluginName globals here - depending on
// which code path included this file, that variable may be unset or even
// hold an unrelated leftover value (seen in the wild: boolean false, which
// json_encode()'s to the bare word `false` and breaks the JS PLUGIN var).
// The plugin's own directory name is always correct and unambiguous.
$rbPlugin = basename(__DIR__);
?>
<div class="mt-2" id="rb-config">
    <fieldset class="border rounded p-2">
        <legend>Backup Host Mode</legend>
        <div class="p-2">
            <div class="callout callout-warning">
                <strong>Important:</strong> Only <u>one</u> FPP system on your show network should have
                Host Mode enabled. This system becomes the destination that pulls backups from the
                others. Enabling Host Mode on more than one system will cause duplicate/competing
                backups and is not supported.
            </div>
            <label><input type="checkbox" id="rb-hostEnabled"> Enable this system as the Remote Backup Host</label>
        </div>
    </fieldset>

    <fieldset class="border rounded p-2 mt-2">
        <legend>Backup Destination Storage</legend>
        <div class="p-2">
            <button type="button" class="btn btn-secondary btn-sm" id="rb-refreshStorage">Rescan Storage Devices</button>
            <div id="rb-storageList" class="mt-2 fpp-backup-action-loading">Scanning...</div>
            <small>NVMe/SSD storage is recommended and listed first when found. If none is present,
            attach a USB flash drive, or fall back to remaining space on the SD card.</small>
        </div>
    </fieldset>

    <fieldset class="border rounded p-2 mt-2">
        <legend>Clone Backups to a Second Drive</legend>
        <div class="p-2">
            <small class="text-muted">Optional - format/mount a second USB drive here, then use "Start Clone" on
                the Status page to mirror everything on the primary destination above onto it (e.g. for an
                occasional off-site or rotating spare copy). This is manual only - there's no Scheduler command
                for it, so it never runs unless you start it.</small><br><br>
            <button type="button" class="btn btn-secondary btn-sm" id="rb-refreshStorage2">Rescan Storage Devices</button>
            <div id="rb-storageList2" class="mt-2 fpp-backup-action-loading">Scanning...</div>
        </div>
    </fieldset>

    <fieldset class="border rounded p-2 mt-2">
        <legend>Remote Systems to Back Up</legend>
        <div class="p-2">
            <button type="button" class="btn btn-secondary btn-sm" id="rb-refreshRemotes">Rescan MultiSync Remotes</button>
            <div id="rb-remoteList" class="mt-2 fpp-backup-action-loading">Scanning...</div>
            <hr>
            <b>Manually add a remote</b> (use this if it wasn't found by MultiSync scan): click in the
            Hostname box on the left, type a name and IP address, then click Add.<br>
            <input id="rb-manualHost" placeholder="Hostname, e.g. Pi5" style="width:180px">
            <input id="rb-manualAddr" placeholder="IP Address" style="width:150px">
            <button type="button" class="btn btn-sm btn-secondary" id="rb-addManual">Add</button>
        </div>
    </fieldset>

    <fieldset class="border rounded p-2 mt-2">
        <legend>Backup Options</legend>
        <div class="p-2">
            <label><input type="checkbox" id="rb-deleteExtra">
                Delete files in the host backup that were removed on the remote (mirrors deletes, uses <code>rsync --delete</code>)</label><br>
            <label><input type="checkbox" id="rb-snapshotMode">
                Keep dated snapshot history per remote instead of one rolling "current" backup (space-efficient via <code>rsync --link-dest</code>)</label><br>
            <label><input type="checkbox" id="rb-includeSystemConfig">
                Also back up system/network config (<code>/etc/fpp</code>, hostname, WiFi, static IP) into a <code>system-config.tar.gz</code> archive alongside each remote's backup
                &mdash; <strong>includes WiFi passwords and other credentials in plain text on the destination drive.</strong> Pulled via sudo on the remote, so it needs the same passwordless-sudo access this plugin already relies on for SSH key setup.</label><br>
            <label><input type="checkbox" id="rb-autoFailoverOnLowSpace">
                If a <em>scheduled</em> run's destination doesn't have enough free space, switch automatically to SD Card / System Storage instead of refusing the run
                &mdash; off by default, so a scheduled backup refuses (with a reason logged, and a popup here/on Status) rather than silently landing somewhere unexpected. A manual Start Backup always shows the popup either way, regardless of this setting.</label><br>
            <br>
            <strong>If a selected remote is playing a sequence when a backup starts:</strong><br>
            <label class="ms-3"><input type="radio" name="rb-playPolicy-choice" id="rb-playPolicy-stop" value="stop">
                Stop the whole backup (default) - nothing runs until the show is over or you deselect that remote.</label><br>
            <label class="ms-3"><input type="radio" name="rb-playPolicy-choice" id="rb-playPolicy-skip" value="skip">
                Skip that remote and back up the others instead.</label><br>
            <div class="ms-3"><strong>Warning:</strong> the busy remote's own SD card is never read either way, but the
                <em>other</em> remotes' rsync transfers still run on the same network while its show is live, which
                can itself add contention/timing risk for a synced show even though nothing reads from that
                device directly.</div><br>
            <small>A scheduled run applies whichever of these is selected with nobody to ask; a manual Start
                Backup shows an immediate notice either way (a toast under Skip, an error under Stop).</small><br>
            <br>
            Max concurrent transfers:
            <input id="rb-maxConcurrent" type="number" min="1" max="8" style="width:4em">
            <small>(default 2: the first devices start immediately, each finished transfer lets the next queued remote start)</small><br>
            <br>
            Run logs to keep per remote:
            <input id="rb-logRetentionCount" type="number" min="1" max="500" style="width:5em">
            <small>(default 15 - older run logs for each remote are deleted automatically after every backup, and immediately
                when you change this number here)</small><br>
            <br>
            SSH user: <input id="rb-sshUser" style="width:8em">
            SSH port: <input id="rb-sshPort" type="number" style="width:6em">
            Default SSH password: <input id="rb-sshPassword" type="password" style="width:10em" placeholder="">
            <small>(used automatically when you select a remote - only needed if you've changed it fleet-wide from the FPP default)</small><br>
            <br>
            Exclude patterns (one per line, paths are relative to the remote's <code>/home/fpp/media</code>):<br>
            <textarea id="rb-excludes" rows="4" style="width:100%"></textarea>
        </div>
    </fieldset>

    <fieldset class="border rounded p-2 mt-2">
        <legend>Show Schedule Conflict Check</legend>
        <div class="p-2">
            <div class="callout callout-warning mb-2">
                <strong>Note:</strong> this is a possible recommendation, not a guarantee. It's built from the
                schedule the master reports right now, using FPP day-of-week codes and read the way this plugin
                understands them - not verified against every FPP version, and any <code>SunSet</code>/<code>SunRise</code>-anchored
                entry is shown as-is rather than resolved to an exact time (it shifts by season). If you use this
                to help pick a backup time, <strong>test that actual backup once before trusting it against a live
                show</strong> - watch it run start-to-finish on a night nothing is scheduled, confirm about how long
                it takes, and build in a safety margin before the next scheduled item rather than cutting it close.
            </div>
            Show master:
            <select id="rb-scheduleMasterSelect" style="max-width:20em"></select>
            <input type="text" id="rb-scheduleMasterCustom" placeholder="Custom address (hostname or IP)" style="display:none;max-width:16em">
            <button type="button" class="btn btn-sm btn-secondary" id="rb-checkSchedule">Check Schedule</button>
            <span id="rb-scheduleStatus" class="ms-2"></span>
            <div id="rb-scheduleResults" class="mt-2"></div>
            <div id="rb-scheduleCheckTimeWrap" class="mt-2" style="display:none">
                <b>Check a specific time:</b>
                <select id="rb-scheduleCheckDay">
                    <option value="Sun">Sunday</option><option value="Mon">Monday</option>
                    <option value="Tue">Tuesday</option><option value="Wed">Wednesday</option>
                    <option value="Thu">Thursday</option><option value="Fri">Friday</option>
                    <option value="Sat">Saturday</option>
                </select>
                <span id="rb-scheduleCheckTimeInputs"></span>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="rb-scheduleCheckBtn">Check</button>
                <span id="rb-scheduleCheckResult" class="ms-2"></span>
            </div>
        </div>
    </fieldset>

    <button type="button" class="btn btn-primary mt-2" id="rb-save">Save Settings</button>
    <a class="btn btn-outline-secondary mt-2" href="plugin.php?plugin=<?php echo urlencode($rbPlugin); ?>&page=status.php">Status</a>
    <span id="rb-saveMsg" class="ms-2"></span>
</div>

<script>
(function () {
    var PLUGIN = <?php echo json_encode($rbPlugin); ?>;
    var AJAX = 'plugin.php?plugin=' + encodeURIComponent(PLUGIN) + '&page=ajax.php&nopage=1&action=';

    function api(action, opts) {
        opts = opts || {};
        var controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        var timer = controller ? setTimeout(function () { controller.abort(); }, opts.timeoutMs || 20000) : null;
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

    // "Backup destination missing" popup - a self-contained mirror of the
    // same functions in status.php, since either page might be the one
    // open when a previously-mounted destination drive vanishes. Config
    // doesn't otherwise poll 'status' at all (it has no live run to show),
    // so a lightweight background poll is set up below just for this.
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
                            if (r.ok) { state.settings = r.data; renderStorage(); }
                            $.jGrowl(r.ok ? 'Failover activated - destination switched to SD Card / System Storage.' : ('Could not activate failover: ' + (r.error || 'unknown error')), { life: 6000, themeState: r.ok ? 'success' : 'danger' });
                        });
                    }
                }
            }
        });
    }

    // "Backup Space Insufficient" popup - self-contained mirror of the same
    // functions in status.php (see there for the full rationale). Config
    // has no visible Start Backup button, so "retry" here calls the same
    // 'start' action directly against state.remotes' current selection
    // rather than a click handler - the ajax action itself doesn't care
    // which page asked for it.
    var rbLowSpacePopupShown = false;

    function rbHandleLowSpaceStatus(res) {
        if (!res || !res.ok) return;
        if (!res.lowSpaceReason) { rbLowSpacePopupShown = false; return; }
        if (rbLowSpacePopupShown) return;
        rbLowSpacePopupShown = true;
        rbShowLowSpaceModal(res.lowSpaceReason, res.lowSpaceEstimatedBytes, res.lowSpaceAvailableBytes, res.destinationMount);
    }

    function rbRetryStartAfterLowSpace() {
        var ids = (state.remotes || []).filter(function (r) { return r.selected; }).map(function (r) { return r.id; });
        if (!ids.length) return;
        api('start', { body: { remotes: ids, dryRun: false, skipSpaceCheck: true } }).then(function (r) {
            if (!r.ok) $.jGrowl('Failed to start backup: ' + (r.error || 'unknown error'), { life: 6000, themeState: 'danger' });
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
                        // Same reasoning as status.php's copy of this modal:
                        // this refusal always happens before run_active.json
                        // is ever set true or any rsync starts, so there's
                        // normally nothing running - calling 'stop' anyway
                        // is a harmless no-op then, and a real safety net if
                        // an earlier run is still finishing in the
                        // background.
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
                            if (r.ok) {
                                state.settings = r.data; renderStorage();
                                $.jGrowl('Failover activated - retrying backup on SD Card / System Storage.', { life: 6000, themeState: 'success' });
                                rbRetryStartAfterLowSpace();
                            } else {
                                $.jGrowl('Could not activate failover: ' + (r.error || 'unknown error'), { life: 6000, themeState: 'danger' });
                            }
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
                    'below first, or use Failover instead.</div>';
            } else {
                bodyHtml = '<div class="mb-2">Pick a destination' +
                    (neededBytes ? ' with room for the estimated ~' + humanBytes(neededBytes) + ' transfer' : '') + ':</div>';
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
                                if (r.ok) {
                                    state.settings = r.data; renderStorage();
                                    $.jGrowl('Destination switched - retrying backup.', { life: 6000, themeState: 'success' });
                                    rbRetryStartAfterLowSpace();
                                } else {
                                    $.jGrowl('Could not switch destination: ' + (r.error || 'unknown error'), { life: 6000, themeState: 'danger' });
                                }
                            });
                        }
                    }
                } : { Close: function () { CloseModalDialog(modalId); } }
            });
        });
    }

    // "A scheduled backup skipped/refused something while nobody was
    // watching" popup - self-contained mirror of the same functions in
    // status.php (see there for the full rationale). Reports a past event
    // (a --scheduled run that already finished), not an ongoing condition,
    // so it does not reset itself the way the two popups above do - only
    // an explicit dismiss (acknowledgePlayOutcome) or a newer notice
    // replacing it clears it.
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
                new Date(o.timestamp).toLocaleString() + ' was refused - every remote it would have backed up ' +
                'was currently playing a sequence: <b>' + names + '</b>. Nothing was backed up.</div>';
        } else {
            bodyHtml = '<div class="callout callout-warning mb-2">A scheduled backup on ' +
                new Date(o.timestamp).toLocaleString() + ' completed, but skipped the following remote(s) because ' +
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

    // Slow background poll, just to catch a destination disappearing while
    // this page happens to be the one open - no live run state to show here,
    // so there's no reason to poll anywhere near status.php's active-run rate.
    var RB_DEST_POLL_MS = 15000;
    function rbPollDestination() {
        api('status').then(function (res) {
            rbHandleDestinationStatus(res);
            rbHandleLowSpaceStatus(res);
            rbHandlePlayOutcomeStatus(res);
            setTimeout(rbPollDestination, RB_DEST_POLL_MS);
        });
    }

    var state = { settings: null, storage: null, remotes: [], hostInfo: null };

    // isHostRemote: true if the given remote entry (from state.remotes) is
    // actually this Host itself - e.g. MultiSync's own system list can
    // include the Host, or someone adds it manually. Mirrors
    // rb_is_host_address() in lib_common.sh, which run_backup.sh uses to
    // back such an entry up as a local copy instead of an SSH pull.
    function isHostRemote(r) {
        if (!r) return false;
        var addr = r.address;
        if (addr === '127.0.0.1' || addr === '::1' || addr === 'localhost') return true;
        if (state.hostInfo && state.hostInfo.addresses && state.hostInfo.addresses.indexOf(addr) !== -1) return true;
        if (state.hostInfo && state.hostInfo.hostname && r.hostname &&
            r.hostname.toLowerCase() === state.hostInfo.hostname.toLowerCase()) return true;
        return false;
    }

    function renderStorage() {
        var el = document.getElementById('rb-storageList');
        el.className = 'mt-2';
        if (!state.storage) { el.innerHTML = 'No data.'; return; }
        var groups = [
            ['nvme', 'NVMe (preferred)'],
            ['ssd', 'SSD (preferred)'],
            ['usb', 'USB Flash Drive'],
            ['sdcard', 'SD Card / System Storage (fallback)']
        ];
        var html = '';
        var any = false;
        groups.forEach(function (g) {
            var list = state.storage[g[0]] || [];
            if (!list.length) return;
            any = true;
            html += '<div><b>' + g[1] + '</b></div>';
            list.forEach(function (d) {
                var mp = d.mountpoint;
                var labelHtml = (d.deviceLabel || mp) + (d.label ? ' &mdash; volume label "' + d.label + '"' : '') + ' &mdash; mounted at ' + mp + ' &mdash; ' + humanBytes(d.availBytes) + ' free';
                // The "SD Card / System Storage" group is bucketed by
                // physical disk (probe_storage.sh's is_rootdisk), not just
                // by mountpoint "/" - on a typical Pi image the boot
                // partition (e.g. /boot or /boot/firmware, labeled "bootfs")
                // lives as its own mounted partition on that SAME disk, so
                // it lands in this same group right alongside the real
                // fallback. Only "/" is ever an actual valid destination
                // (backups go into a dedicated subfolder under it - see
                // below); the boot partition is shown for visibility only,
                // with no activation control, since selecting it would mean
                // writing backups onto FPP's own tiny FAT32 boot partition.
                if (g[0] === 'sdcard' && mp !== '/') {
                    html += '<div>' + labelHtml + ' <small class="text-muted">(system boot partition - not a valid backup destination)</small></div>';
                    return;
                }
                var checked = state.settings && state.settings.destinationMount === mp ? 'checked' : '';
                var id = 'rb-storage-' + mp.replace(/[^A-Za-z0-9]/g, '_');
                html += '<div><label><input type="radio" name="rb-storage-choice" value="' + mp + '" ' + checked + ' id="' + id + '"> ' + labelHtml + '</label>';
                // The SD-card/system-storage fallback reports the true
                // filesystem root ("/") as its mountpoint - free space is
                // measured there, but backups themselves are written into
                // a dedicated writable subfolder (see rb_dest_root() in
                // lib_common.sh), never into "/" itself.
                if (mp === '/') {
                    html += ' <small class="text-muted">(backups stored under /home/fpp/media/backups)</small>';
                }
                // Only ever offered for the drive THIS plugin manages
                // (mounted at /mnt/Backups) - never for the SD card/
                // NVMe/SSD the OS itself might be running from.
                if (mp === '/mnt/Backups') {
                    html += ' <button type="button" class="btn btn-sm btn-outline-secondary rb-unmount-usb" data-device="' + (d.path || d.deviceLabel) + '">Unmount</button>';
                    html += ' <button type="button" class="btn btn-sm btn-outline-danger rb-reformat-usb" data-device="' + (d.path || d.deviceLabel) + '" data-size="' + humanBytes(d.sizeBytes) + '">Re-format...</button>';
                }
                html += '</div>';
            });
        });
        if (!any) html = '<em>No mounted storage devices detected.</em>';

        var unmounted = state.storage.usbUnmounted || [];
        if (unmounted.length) {
            html += '<div class="mt-2"><b>USB drive(s) detected but not mounted</b></div>';
            unmounted.forEach(function (d) {
                var desc = (d.label ? d.label + ' ' : '') + '(' + d.path + ')' +
                    (d.hasFilesystem ? ', ' + d.fstype : ', no filesystem - needs formatting first') +
                    ', ' + humanBytes(d.sizeBytes);
                html += '<div>' + desc + ' ' +
                    (d.hasFilesystem
                        ? '<button type="button" class="btn btn-sm btn-outline-primary rb-mount-usb" data-device="' + d.path + '">Mount as Backups</button>'
                        : '<button type="button" class="btn btn-sm btn-outline-danger rb-format-usb" data-device="' + d.path + '" data-size="' + humanBytes(d.sizeBytes) + '">Format &amp; Mount as Backups</button>') +
                    '</div>';
            });
        }

        el.innerHTML = html;

        Array.prototype.forEach.call(document.getElementsByClassName('rb-unmount-usb'), function (btn) {
            btn.addEventListener('click', function () {
                DisplayConfirmationDialog('rb-unmount-confirm', 'Unmount Backup Drive',
                    'Unmount the backup destination drive from <code>/mnt/Backups</code>?<br><br>' +
                    'Backups already on it are kept - this just detaches it from the system so it is safe to physically unplug. ' +
                    'You will need to Mount it again (Config &gt; Storage) before the next backup run.',
                    function () {
                        btn.disabled = true;
                        btn.textContent = 'Unmounting...';
                        // timeoutMs kept comfortably above mount_usb.sh's/
                        // unmount_usb.sh's own server-side rb_run() timeout
                        // (20-25s) - the fetch() default (20s) was aborting
                        // marginally BEFORE the server gave up, so a mount/
                        // unmount that was still genuinely in progress (and
                        // would have succeeded) got reported as "timed out"
                        // even though it actually completed a moment later.
                        api('unmountUsb', { body: {}, timeoutMs: 30000 }).then(function (res) {
                            if (res.ok) {
                                $.jGrowl('Unmounted ' + res.mountpoint + (res.device ? ' (' + res.device + ')' : '') + '.' + (res.removedFstab ? ' Removed it from /etc/fstab so it will not block boot if left unplugged.' : '') + ' It is now safe to disconnect the drive.', { life: 6000, themeState: 'success' });
                                api('probeStorage').then(function (r2) {
                                    if (r2.ok) { state.storage = r2.data; renderStorage(); }
                                });
                            } else {
                                $.jGrowl('Unmount failed: ' + (res.error || 'unknown error'), { life: 6000, themeState: 'danger' });
                                btn.disabled = false;
                                btn.textContent = 'Unmount';
                            }
                        });
                    });
            });
        });

        Array.prototype.forEach.call(document.getElementsByClassName('rb-mount-usb'), function (btn) {
            btn.addEventListener('click', function () {
                var device = btn.getAttribute('data-device');
                btn.disabled = true;
                btn.textContent = 'Mounting...';
                api('mountUsb', { body: { device: device }, timeoutMs: 35000 }).then(function (res) {
                    if (res.ok) {
                        $.jGrowl('Mounted ' + device + ' at ' + res.mountpoint + (res.addedFstab ? ' (added to /etc/fstab so it survives reboots)' : ''), { life: 6000, themeState: 'success' });
                        // Pre-select this drive as the destination - just fills in the
                        // radio button so it's ready to go, doesn't save anything on its
                        // own; "Save Settings" is still required, same as always.
                        if (state.settings) state.settings.destinationMount = res.mountpoint || '/mnt/Backups';
                        api('probeStorage').then(function (r2) {
                            if (r2.ok) { state.storage = r2.data; renderStorage(); }
                        });
                    } else {
                        $.jGrowl('Mount failed: ' + (res.error || 'unknown error'), { life: 6000, themeState: 'danger' });
                        btn.disabled = false;
                        btn.textContent = 'Mount as Backups';
                    }
                });
            });
        });

        // Shared by both "Format & Mount" (unmounted, new drive) and
        // "Re-format..." (already mounted at /mnt/Backups) - the backend
        // handles unmounting/removing the fstab entry first when needed.
        function runFormatFlow(btn, device, size, isReformat, resetLabel) {
            var warnExtra = isReformat ? ' It is currently your backup destination - existing backups on it will be gone too.' : '';
            var modalId = 'rb-format-modal';
            var bodyHtml =
                '<div class="callout callout-danger mb-2">This will <b>ERASE ALL DATA</b> on ' + device + ' (' + size + ').' + warnExtra + ' This cannot be undone.</div>' +
                '<table class="table table-sm table-borderless mb-0">' +
                '<tr><td>Filesystem:</td><td>' +
                '<select id="rb-format-fstype" class="form-select form-select-sm d-inline-block w-auto">' +
                '<option value="exfat" selected>exFAT (recommended - readable on Windows/Mac/Linux)</option>' +
                '<option value="ext4">ext4 (Linux only)</option>' +
                '</select></td></tr>' +
                '<tr><td>Volume label:</td><td>' +
                '<input type="text" id="rb-format-label" class="form-control form-control-sm d-inline-block w-auto" maxlength="11" value="Backups" autocomplete="off"></td></tr>' +
                '<tr><td>Type <code>' + device + '</code> to confirm:</td><td>' +
                '<input type="text" id="rb-format-confirm" class="form-control form-control-sm d-inline-block w-auto" autocomplete="off"></td></tr>' +
                '</table>';

            DoModalDialog({
                id: modalId,
                title: 'Format ' + device,
                class: 'modal-m',
                backdrop: true,
                body: bodyHtml,
                buttons: {
                    Cancel: function () { CloseModalDialog(modalId); },
                    Format: {
                        class: 'btn-danger',
                        click: function () {
                            var fstype = document.getElementById('rb-format-fstype').value;
                            var label = document.getElementById('rb-format-label').value;
                            var typed = document.getElementById('rb-format-confirm').value;
                            if (typed !== device) {
                                $.jGrowl('Confirmation text did not match "' + device + '" - aborted, nothing was formatted.', { life: 6000, themeState: 'danger' });
                                return;
                            }
                            CloseModalDialog(modalId);

                            btn.disabled = true;
                            btn.textContent = 'Formatting...';
                            api('formatUsb', {
                                body: { device: device, fstype: fstype, confirm: 'I_UNDERSTAND_THIS_ERASES_THE_DRIVE', label: label },
                                timeoutMs: 120000
                            }).then(function (res) {
                                if (res.ok) {
                                    $.jGrowl('Formatted (' + fstype + ') and mounted ' + device + ' at ' + res.mountpoint + (res.addedFstab ? ' (added to /etc/fstab)' : '') + (res.clearedAllStatus ? '. All previous backup status on the Status page was cleared since this was your active destination drive.' : ''), { life: 6000, themeState: 'success' });
                                    // Same pre-select as the plain Mount flow above - a
                                    // no-op for Re-format (already the active destination).
                                    if (state.settings) state.settings.destinationMount = res.mountpoint || '/mnt/Backups';
                                    api('probeStorage').then(function (r2) {
                                        if (r2.ok) { state.storage = r2.data; renderStorage(); }
                                    });
                                } else {
                                    $.jGrowl('Format failed: ' + (res.error || 'unknown error'), { life: 6000, themeState: 'danger' });
                                    btn.disabled = false;
                                    btn.textContent = resetLabel;
                                }
                            });
                        }
                    }
                }
            });
        }

        Array.prototype.forEach.call(document.getElementsByClassName('rb-format-usb'), function (btn) {
            btn.addEventListener('click', function () {
                runFormatFlow(btn, btn.getAttribute('data-device'), btn.getAttribute('data-size'), false, 'Format & Mount as Backups');
            });
        });

        Array.prototype.forEach.call(document.getElementsByClassName('rb-reformat-usb'), function (btn) {
            btn.addEventListener('click', function () {
                runFormatFlow(btn, btn.getAttribute('data-device'), btn.getAttribute('data-size'), true, 'Re-format...');
            });
        });

        renderStorage2();
    }

    // Secondary drive ("Clone Backups to a Second Drive") - a smaller,
    // self-contained mirror of renderStorage() above, scoped to the
    // fixed /mnt/BackupsCopy mountpoint and the mountSecondary/
    // formatSecondary/unmountSecondary actions instead of the primary
    // ones. Reuses state.storage (already fetched for the primary
    // section above - same lsblk scan, so this never needs its own
    // probeStorage call) but shows only the one device actually mounted
    // there, if any, plus the same "not mounted yet" USB list the
    // primary section shows (a drive can be claimed for either
    // mountpoint from whichever section's button you click).
    function renderStorage2() {
        var el = document.getElementById('rb-storageList2');
        el.className = 'mt-2';
        if (!state.storage) { el.innerHTML = 'No data.'; return; }

        var mounted = null;
        ['nvme', 'ssd', 'usb', 'sdcard'].forEach(function (g) {
            (state.storage[g] || []).forEach(function (d) {
                if (d.mountpoint === '/mnt/BackupsCopy') mounted = d;
            });
        });

        var html = '';
        if (mounted) {
            html += '<div><label>' + (mounted.deviceLabel || '/mnt/BackupsCopy') + (mounted.label ? ' &mdash; volume label "' + mounted.label + '"' : '') + ' &mdash; mounted at /mnt/BackupsCopy &mdash; ' +
                humanBytes(mounted.availBytes) + ' free</label>' +
                ' <button type="button" class="btn btn-sm btn-outline-secondary rb-unmount-usb2" data-device="' + (mounted.path || mounted.deviceLabel) + '">Unmount</button>' +
                ' <button type="button" class="btn btn-sm btn-outline-danger rb-reformat-usb2" data-device="' + (mounted.path || mounted.deviceLabel) + '" data-size="' + humanBytes(mounted.sizeBytes) + '">Re-format...</button>' +
                '</div>';
        } else {
            html += '<em>No drive currently mounted at /mnt/BackupsCopy.</em>';
        }

        var unmounted = state.storage.usbUnmounted || [];
        if (unmounted.length) {
            html += '<div class="mt-2"><b>USB drive(s) detected but not mounted</b></div>';
            unmounted.forEach(function (d) {
                var desc = (d.label ? d.label + ' ' : '') + '(' + d.path + ')' +
                    (d.hasFilesystem ? ', ' + d.fstype : ', no filesystem - needs formatting first') +
                    ', ' + humanBytes(d.sizeBytes);
                html += '<div>' + desc + ' ' +
                    (d.hasFilesystem
                        ? '<button type="button" class="btn btn-sm btn-outline-primary rb-mount-usb2" data-device="' + d.path + '">Mount as Clone Drive</button>'
                        : '<button type="button" class="btn btn-sm btn-outline-danger rb-format-usb2" data-device="' + d.path + '" data-size="' + humanBytes(d.sizeBytes) + '">Format &amp; Mount as Clone Drive</button>') +
                    '</div>';
            });
        }

        el.innerHTML = html;

        Array.prototype.forEach.call(document.getElementsByClassName('rb-unmount-usb2'), function (btn) {
            btn.addEventListener('click', function () {
                DisplayConfirmationDialog('rb-unmount2-confirm', 'Unmount Clone Drive',
                    'Unmount the secondary clone drive from <code>/mnt/BackupsCopy</code>?<br><br>' +
                    'Backups already on it are kept - this just detaches it so it is safe to physically unplug.',
                    function () {
                        btn.disabled = true;
                        btn.textContent = 'Unmounting...';
                        // See the primary Unmount handler above for why
                        // timeoutMs is set explicitly here.
                        api('unmountSecondary', { body: {}, timeoutMs: 30000 }).then(function (res) {
                            if (res.ok) {
                                $.jGrowl('Unmounted ' + res.mountpoint + (res.device ? ' (' + res.device + ')' : '') + '.' + (res.removedFstab ? ' Removed it from /etc/fstab so it will not block boot if left unplugged.' : '') + ' It is now safe to disconnect the drive.', { life: 6000, themeState: 'success' });
                                api('probeStorage').then(function (r2) {
                                    if (r2.ok) { state.storage = r2.data; renderStorage(); }
                                });
                            } else {
                                $.jGrowl('Unmount failed: ' + (res.error || 'unknown error'), { life: 6000, themeState: 'danger' });
                                btn.disabled = false;
                                btn.textContent = 'Unmount';
                            }
                        });
                    });
            });
        });

        Array.prototype.forEach.call(document.getElementsByClassName('rb-mount-usb2'), function (btn) {
            btn.addEventListener('click', function () {
                var device = btn.getAttribute('data-device');
                btn.disabled = true;
                btn.textContent = 'Mounting...';
                // See the primary Mount handler above for why timeoutMs is
                // set explicitly here - this is exactly the "Mount as clone
                // drive failed: timed out" bug (the mount had actually
                // succeeded; a Rescan afterward showed it mounted).
                api('mountSecondary', { body: { device: device }, timeoutMs: 35000 }).then(function (res) {
                    if (res.ok) {
                        $.jGrowl('Mounted ' + device + ' at ' + res.mountpoint + (res.addedFstab ? ' (added to /etc/fstab so it survives reboots)' : ''), { life: 6000, themeState: 'success' });
                        api('probeStorage').then(function (r2) {
                            if (r2.ok) { state.storage = r2.data; renderStorage(); }
                        });
                    } else {
                        $.jGrowl('Mount failed: ' + (res.error || 'unknown error'), { life: 6000, themeState: 'danger' });
                        btn.disabled = false;
                        btn.textContent = 'Mount as Clone Drive';
                    }
                });
            });
        });

        function runFormatFlow2(btn, device, size, isReformat, resetLabel) {
            var warnExtra = isReformat ? ' It is currently your clone drive - existing cloned backups on it will be gone too.' : '';
            var modalId = 'rb-format2-modal';
            var bodyHtml =
                '<div class="callout callout-danger mb-2">This will <b>ERASE ALL DATA</b> on ' + device + ' (' + size + ').' + warnExtra + ' This cannot be undone.</div>' +
                '<table class="table table-sm table-borderless mb-0">' +
                '<tr><td>Filesystem:</td><td>' +
                '<select id="rb-format2-fstype" class="form-select form-select-sm d-inline-block w-auto">' +
                '<option value="exfat" selected>exFAT (recommended - readable on Windows/Mac/Linux)</option>' +
                '<option value="ext4">ext4 (Linux only)</option>' +
                '</select></td></tr>' +
                '<tr><td>Volume label:</td><td>' +
                '<input type="text" id="rb-format2-label" class="form-control form-control-sm d-inline-block w-auto" maxlength="11" value="Backups" autocomplete="off"></td></tr>' +
                '<tr><td>Type <code>' + device + '</code> to confirm:</td><td>' +
                '<input type="text" id="rb-format2-confirm" class="form-control form-control-sm d-inline-block w-auto" autocomplete="off"></td></tr>' +
                '</table>';

            DoModalDialog({
                id: modalId,
                title: 'Format ' + device,
                class: 'modal-m',
                backdrop: true,
                body: bodyHtml,
                buttons: {
                    Cancel: function () { CloseModalDialog(modalId); },
                    Format: {
                        class: 'btn-danger',
                        click: function () {
                            var fstype = document.getElementById('rb-format2-fstype').value;
                            var label = document.getElementById('rb-format2-label').value;
                            var typed = document.getElementById('rb-format2-confirm').value;
                            if (typed !== device) {
                                $.jGrowl('Confirmation text did not match "' + device + '" - aborted, nothing was formatted.', { life: 6000, themeState: 'danger' });
                                return;
                            }
                            CloseModalDialog(modalId);

                            btn.disabled = true;
                            btn.textContent = 'Formatting...';
                            api('formatSecondary', {
                                body: { device: device, fstype: fstype, confirm: 'I_UNDERSTAND_THIS_ERASES_THE_DRIVE', label: label },
                                timeoutMs: 120000
                            }).then(function (res) {
                                if (res.ok) {
                                    $.jGrowl('Formatted (' + fstype + ') and mounted ' + device + ' at ' + res.mountpoint + (res.addedFstab ? ' (added to /etc/fstab)' : ''), { life: 6000, themeState: 'success' });
                                    api('probeStorage').then(function (r2) {
                                        if (r2.ok) { state.storage = r2.data; renderStorage(); }
                                    });
                                } else {
                                    $.jGrowl('Format failed: ' + (res.error || 'unknown error'), { life: 6000, themeState: 'danger' });
                                    btn.disabled = false;
                                    btn.textContent = resetLabel;
                                }
                            });
                        }
                    }
                }
            });
        }

        Array.prototype.forEach.call(document.getElementsByClassName('rb-format-usb2'), function (btn) {
            btn.addEventListener('click', function () {
                runFormatFlow2(btn, btn.getAttribute('data-device'), btn.getAttribute('data-size'), false, 'Format & Mount as Clone Drive');
            });
        });

        Array.prototype.forEach.call(document.getElementsByClassName('rb-reformat-usb2'), function (btn) {
            btn.addEventListener('click', function () {
                runFormatFlow2(btn, btn.getAttribute('data-device'), btn.getAttribute('data-size'), true, 'Re-format...');
            });
        });
    }

    function remoteRowId(r) { return 'rb-remote-' + (r.id || r.hostname).replace(/[^A-Za-z0-9]/g, '_'); }

    function keyStatusId(id) { return 'rb-keystatus-' + id.replace(/[^A-Za-z0-9]/g, '_'); }

    function setKeyStatus(id, text, cls) {
        var el = document.getElementById(keyStatusId(id));
        if (el) { el.textContent = text; el.className = cls || 'text-muted'; }
    }

    // Shared by both the auto-push-on-select path and the manual button.
    // Returns the explicit password (if entered), or the stored plugin-wide
    // password (if set), or null.  null means "don't prompt, just try the
    // FPP default" (used for auto-push so selecting remotes stays a
    // one-click action); the server will finally fall back to 'falcon'.
    function defaultSshPassword() {
        var el = document.getElementById('rb-sshPassword');
        if (el && el.value !== '') return el.value;
        if (state.settings && state.settings.sshPassword) return state.settings.sshPassword;
        return null;
    }

    function promptSshPassword(addr, cb) {
        var modalId = 'rb-sshpw-modal';
        var bodyHtml = '<div class="mb-2">SSH password for <code>fpp@' + addr + '</code>:</div>' +
            '<input type="password" id="rb-sshpw-input" class="form-control form-control-sm" value="' +
            (defaultSshPassword() || '').replace(/"/g, '&quot;') + '" autocomplete="off">';
        DoModalDialog({
            id: modalId,
            title: 'SSH Password',
            class: 'modal-m',
            backdrop: true,
            body: bodyHtml,
            focus: 'rb-sshpw-input',
            buttons: {
                Cancel: function () { CloseModalDialog(modalId); },
                Ok: function () {
                    var pw = document.getElementById('rb-sshpw-input').value;
                    CloseModalDialog(modalId);
                    cb(pw);
                }
            }
        });
    }

    function pushKeyFor(id, address, password, announce) {
        setKeyStatus(id, 'pushing key...', 'text-muted');
        // timeoutMs kept above ssh_setup.sh's own worst-case runtime
        // (its internal `timeout --kill-after=5 20` allows up to 25s) -
        // see the primary Mount/Unmount handlers above for the same
        // "client aborted before the server actually finished" issue
        // this was otherwise exposed to.
        return api('pushSshKey', {
            body: {
                address: address,
                sshUser: document.getElementById('rb-sshUser').value || 'fpp',
                sshPort: document.getElementById('rb-sshPort').value || 22,
                password: password || defaultSshPassword()
            },
            timeoutMs: 30000
        }).then(function (res) {
            if (res.ok) {
                setKeyStatus(id, 'key installed', 'text-success');
            } else {
                setKeyStatus(id, 'key push failed - click "Push SSH Key" to retry with a password', 'text-danger');
                if (announce) $.jGrowl(res.message || res.error || 'Failed', { life: 6000, themeState: 'danger' });
            }
            return res;
        });
    }

    // Multisync-sourced entries only - manually-added ones never get a
    // lastSeenAt at all (they're expected to be absent from a MultiSync
    // scan by design, that's the whole reason they were added manually),
    // so they're never flagged. 24h threshold: since a rescan only ever
    // happens when someone has the Config page open (there's no scheduled
    // background scan), this reflects "more than 24h has passed since the
    // last scan that saw it," not "continuously absent for 24h" - a long
    // gap between Config visits can make this fire on the very next
    // rescan even for a remote that was online the whole time. Flags
    // only - never auto-removes, so a flagged remote's selection/backups
    // are never silently affected; Remove (below) is the actual cleanup
    // action, left entirely to the user.
    function staleRemoteBadge(r) {
        if (r.source !== 'multisync' || !r.lastSeenAt) return '';
        var seenMs = new Date(r.lastSeenAt).getTime();
        if (isNaN(seenMs)) return '';
        var ageMs = Date.now() - seenMs;
        if (ageMs < 24 * 60 * 60 * 1000) return '';
        var days = Math.floor(ageMs / (24 * 60 * 60 * 1000));
        var label = days >= 1 ? (days + (days === 1 ? ' day' : ' days')) : 'over 24 hours';
        return ' <span class="badge text-bg-warning" title="Has not appeared in a MultiSync scan since ' +
            new Date(r.lastSeenAt).toLocaleString() +
            ' - could be offline, decommissioned, or just not announcing right now. Remove it below if it\'s gone for good.">' +
            'Not seen in ' + label + '</span>';
    }

    function renderRemotes() {
        var el = document.getElementById('rb-remoteList');
        el.className = 'mt-2';
        if (!state.remotes.length) { el.innerHTML = '<em>No remotes found yet. Rescan, or add one manually below.</em>'; return; }
        var html = '<table class="table table-sm"><tr><th></th><th>Hostname</th><th>Address</th><th>Source</th><th></th><th></th></tr>';
        state.remotes.forEach(function (r) {
            var isHost = isHostRemote(r);
            var hostTag = isHost ?
                ' <span class="badge text-bg-secondary" title="This is the Host itself - backed up locally, not over SSH">Host</span>' : '';
            var actionCell = isHost ?
                '<small class="text-muted">Local backup - no SSH key needed</small>' :
                '<button type="button" class="btn btn-outline-secondary btn-sm rb-push-key" data-id="' + r.id + '" data-addr="' + r.address + '">Push SSH Key</button> ' +
                '<small id="' + keyStatusId(r.id) + '" class="text-muted"></small>';
            html += '<tr>' +
                '<td><input type="checkbox" class="rb-remote-check" data-id="' + r.id + '" data-addr="' + r.address + '" ' + (r.selected ? 'checked' : '') + '></td>' +
                '<td>' + r.hostname + hostTag + staleRemoteBadge(r) + '</td>' +
                '<td>' + r.address + '</td>' +
                '<td>' + (r.source || 'multisync') + '</td>' +
                '<td>' + actionCell + '</td>' +
                '<td><button type="button" class="btn btn-outline-danger btn-sm rb-remote-remove" data-id="' + r.id + '" ' +
                'title="Removes it from this list (e.g. a stale duplicate left over from before a System Name change was renamed in place - or any remote you no longer want tracked). A rescan will re-discover it if it\'s still on the network. Doesn\'t take effect until Save Settings.">Remove</button></td>' +
                '</tr>';
        });
        html += '</table>';
        el.innerHTML = html;

        Array.prototype.forEach.call(document.getElementsByClassName('rb-push-key'), function (btn) {
            btn.addEventListener('click', function () {
                var addr = btn.getAttribute('data-addr');
                var id = btn.getAttribute('data-id');
                promptSshPassword(addr, function (pw) {
                    pushKeyFor(id, addr, pw, true);
                });
            });
        });

        // Auto-push this backup Host's own SSH key to a remote the moment
        // it's selected, so checking the box is enough to make it
        // backup-ready - no separate manual step needed unless the
        // default password fails. Skipped for the Host itself (the "Host"
        // badge) - it's backed up as a local copy, not over SSH, so it
        // has no key to push.
        Array.prototype.forEach.call(document.getElementsByClassName('rb-remote-check'), function (chk) {
            chk.addEventListener('change', function () {
                var id = chk.getAttribute('data-id');
                var addr = chk.getAttribute('data-addr');
                var r = state.remotes.filter(function (x) { return x.id === id; })[0];
                if (r) r.selected = chk.checked;
                if (r && isHostRemote(r)) return;
                if (chk.checked) {
                    pushKeyFor(id, addr, null, false);
                } else {
                    setKeyStatus(id, '', 'text-muted');
                }
            });
        });

        Array.prototype.forEach.call(document.getElementsByClassName('rb-remote-remove'), function (btn) {
            btn.addEventListener('click', function () {
                var id = btn.getAttribute('data-id');
                state.remotes = state.remotes.filter(function (r) { return r.id !== id; });
                renderRemotes();
            });
        });
    }

    // "Show Schedule Conflict Check" panel - purely advisory, read-only
    // (see the panel's own Note). Populates the master-address picker from
    // state.remotes (the master isn't necessarily one of them, so there's
    // always a "Custom address..." fallback), fetches/classifies the
    // master's /api/schedule via the checkMasterSchedule ajax action, and
    // renders the result as a day-of-week table plus a quick "does this
    // time conflict" mini-checker against the same already-fetched data.
    var scheduleData = null; // last successful {days:{...}} response, reused by the time-checker

    function renderScheduleMasterSelect() {
        var sel = document.getElementById('rb-scheduleMasterSelect');
        var current = sel.value || (state.settings && state.settings.scheduleMasterAddress) || '';
        var html = '';
        state.remotes.forEach(function (r) {
            html += '<option value="' + r.address + '">' + r.hostname + ' (' + r.address + ')</option>';
        });
        html += '<option value="__custom__">Custom address...</option>';
        sel.innerHTML = html;

        var knownAddr = state.remotes.some(function (r) { return r.address === current; });
        if (current && knownAddr) {
            sel.value = current;
            document.getElementById('rb-scheduleMasterCustom').style.display = 'none';
        } else if (current) {
            sel.value = '__custom__';
            document.getElementById('rb-scheduleMasterCustom').value = current;
            document.getElementById('rb-scheduleMasterCustom').style.display = '';
        }
    }

    document.getElementById('rb-scheduleMasterSelect').addEventListener('change', function () {
        var custom = document.getElementById('rb-scheduleMasterCustom');
        custom.style.display = (this.value === '__custom__') ? '' : 'none';
    });

    function currentScheduleMasterAddress() {
        var sel = document.getElementById('rb-scheduleMasterSelect');
        if (sel.value === '__custom__') {
            return document.getElementById('rb-scheduleMasterCustom').value.trim();
        }
        return sel.value;
    }

    var DAY_LABELS = { Sun: 'Sunday', Mon: 'Monday', Tue: 'Tuesday', Wed: 'Wednesday', Thu: 'Thursday', Fri: 'Friday', Sat: 'Saturday' };
    var DAY_ORDER = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    // Whether to render times 12-hour AM/PM or 24-hour - set from the same
    // master's own Settings > Localization > Time Format
    // (checkMasterSchedule's timeFormat field, read server-side from
    // GET /api/settings/TimeFormat) each time "Check Schedule" succeeds, so
    // this panel matches what that system is actually configured to show
    // rather than a hardcoded choice or the browser's own locale guess.
    // Defaults true (FPP's own factory default) until a real check has run.
    var scheduleUse12h = true;

    // Only ever applied to a literal HH:MM:SS clock time - a sun-relative
    // label ("SunSet+30m") or an unparsed one ("? garbage") is returned
    // completely unchanged, since neither is really a time to reformat.
    function formatTimeForDisplay(t) {
        if (!/^\d{2}:\d{2}:\d{2}$/.test(t)) return t;
        var h = parseInt(t.slice(0, 2), 10);
        var m = t.slice(3, 5);
        // FPP's own 24-hour display is zero-padded HH:MM with no seconds
        // (its option is literally labeled "HH:MM (23:40)").
        if (!scheduleUse12h) return (h < 10 ? '0' : '') + h + ':' + m;
        var ampm = h >= 12 ? 'PM' : 'AM';
        var h12 = h % 12; if (h12 === 0) h12 = 12;
        return h12 + ':' + m + ' ' + ampm;
    }

    // (Re)builds the "Check a specific time" input to match scheduleUse12h -
    // deliberately not a plain <input type="time">, since that control's
    // displayed format follows the browser/OS locale, not anything this
    // page can set, which could easily disagree with what the master
    // itself is configured to show. getCheckTime24() below always reads
    // whichever of these is currently in the DOM back out as HH:MM:SS.
    function buildScheduleCheckTimeInputs() {
        var wrap = document.getElementById('rb-scheduleCheckTimeInputs');
        var html;
        if (scheduleUse12h) {
            html = '<select id="rb-scheduleCheckHour">';
            for (var h = 1; h <= 12; h++) html += '<option value="' + h + '"' + (h === 9 ? ' selected' : '') + '>' + h + '</option>';
            html += '</select>:<select id="rb-scheduleCheckMinute">';
            for (var m = 0; m < 60; m++) { var mm = (m < 10 ? '0' : '') + m; html += '<option value="' + mm + '">' + mm + '</option>'; }
            html += '</select> <select id="rb-scheduleCheckAmPm"><option value="AM" selected>AM</option><option value="PM">PM</option></select>';
        } else {
            html = '<select id="rb-scheduleCheckHour24">';
            for (var h2 = 0; h2 < 24; h2++) { var hh = (h2 < 10 ? '0' : '') + h2; html += '<option value="' + hh + '"' + (h2 === 9 ? ' selected' : '') + '>' + hh + '</option>'; }
            html += '</select>:<select id="rb-scheduleCheckMinute24">';
            for (var m2 = 0; m2 < 60; m2++) { var mm2 = (m2 < 10 ? '0' : '') + m2; html += '<option value="' + mm2 + '">' + mm2 + '</option>'; }
            html += '</select>';
        }
        wrap.innerHTML = html;
    }

    function getCheckTime24() {
        if (scheduleUse12h) {
            var h = parseInt(document.getElementById('rb-scheduleCheckHour').value, 10) % 12;
            if (document.getElementById('rb-scheduleCheckAmPm').value === 'PM') h += 12;
            var m = document.getElementById('rb-scheduleCheckMinute').value;
            return (h < 10 ? '0' : '') + h + ':' + m + ':00';
        }
        var h2 = document.getElementById('rb-scheduleCheckHour24').value;
        var m2 = document.getElementById('rb-scheduleCheckMinute24').value;
        return h2 + ':' + m2 + ':00';
    }

    // One tag/class per entry - unparsed (can't understand the time at
    // all) beats sun-relative (real but unresolved to a clock time) beats
    // dateParity (real clock time, but only runs on alternating calendar
    // days so which weekday it lands on isn't fixed) - each is a
    // different reason the same "verify manually" caution applies.
    function scheduleEntryFlag(e) {
        if (e.unparsed) return { cls: 'text-danger', tag: ' (unrecognized time - verify manually)' };
        if (e.sunRelative) return { cls: 'text-warning', tag: ' (approximate - sun-relative)' };
        if (e.dateParity) return { cls: 'text-info', tag: ' (' + e.dateParity + ' calendar days only - verify manually)' };
        return { cls: '', tag: '' };
    }

    function renderScheduleResults(days) {
        // table-sm's default cell padding is tight (0.3rem) - fine with a
        // bare "Clear", but multiple stacked "H:MM AM/PM-H:MM AM/PM" times
        // butt right up against the 1px table-bordered column dividers with
        // 7 columns side by side. Explicit px-3 widens that horizontal gutter
        // regardless of table-sm's own padding.
        var html = '<table class="table table-sm table-bordered"><tr>';
        DAY_ORDER.forEach(function (d) { html += '<th class="px-3">' + DAY_LABELS[d] + '</th>'; });
        html += '</tr><tr>';
        DAY_ORDER.forEach(function (d) {
            var entries = days[d] || [];
            if (!entries.length) {
                html += '<td class="table-success align-top px-3"><small>Clear</small></td>';
                return;
            }
            var cell = entries.map(function (e) {
                var flag = scheduleEntryFlag(e);
                return '<div class="' + flag.cls + ' mb-2 pb-2 border-bottom"><small>' +
                    formatTimeForDisplay(e.start) + '–' + formatTimeForDisplay(e.end) + '<br>' +
                    e.label.replace(/</g, '&lt;') + flag.tag + '</small></div>';
            }).join('');
            html += '<td class="table-warning align-top py-2 px-3">' + cell + '</td>';
        });
        html += '</tr></table>';
        document.getElementById('rb-scheduleResults').innerHTML = html;
        document.getElementById('rb-scheduleCheckTimeWrap').style.display = '';
    }

    document.getElementById('rb-checkSchedule').addEventListener('click', function () {
        var addr = currentScheduleMasterAddress();
        var statusEl = document.getElementById('rb-scheduleStatus');
        if (!addr) { $.jGrowl('Enter or pick a master address first.', { life: 6000, themeState: 'danger' }); return; }
        statusEl.textContent = 'Checking...';
        document.getElementById('rb-scheduleResults').innerHTML = '';
        api('checkMasterSchedule&address=' + encodeURIComponent(addr)).then(function (res) {
            if (!res.ok) {
                statusEl.textContent = '';
                scheduleData = null;
                document.getElementById('rb-scheduleCheckTimeWrap').style.display = 'none';
                $.jGrowl('Could not check schedule: ' + (res.error || 'unknown error'), { life: 6000, themeState: 'danger' });
                return;
            }
            statusEl.textContent = '';
            scheduleUse12h = res.timeFormat !== '24';
            buildScheduleCheckTimeInputs();
            scheduleData = res.days;
            renderScheduleResults(res.days);
        });
    });

    document.getElementById('rb-scheduleCheckBtn').addEventListener('click', function () {
        var resultEl = document.getElementById('rb-scheduleCheckResult');
        if (!scheduleData) { resultEl.textContent = ''; return; }
        var day = document.getElementById('rb-scheduleCheckDay').value;
        var checkTime = getCheckTime24();
        var entries = scheduleData[day] || [];
        var hit = null, parityHit = null, approximate = false;
        entries.forEach(function (e) {
            if (e.sunRelative || e.unparsed) { approximate = true; return; }
            // Literal HH:MM:SS strings compare correctly as plain strings -
            // true for a dateParity entry too, its start/end are real clock
            // times, just not tied to a fixed weekday.
            var inRange = (checkTime >= e.start && checkTime < e.end);
            if (e.dateParity) { if (inRange) parityHit = e; return; }
            if (inRange) hit = e;
        });
        if (hit) {
            resultEl.className = 'ms-2 text-danger';
            resultEl.textContent = 'Conflicts with "' + hit.label + '" (' + formatTimeForDisplay(hit.start) + '–' + formatTimeForDisplay(hit.end) + ')';
        } else if (parityHit) {
            resultEl.className = 'ms-2 text-warning';
            resultEl.textContent = 'Would conflict with "' + parityHit.label + '" (' + formatTimeForDisplay(parityHit.start) + '–' + formatTimeForDisplay(parityHit.end) + ') on ' + parityHit.dateParity + ' calendar days - verify manually.';
        } else if (approximate) {
            resultEl.className = 'ms-2 text-warning';
            resultEl.textContent = 'No exact conflict, but ' + DAY_LABELS[day] + ' has a sun-relative or unrecognized entry - verify manually.';
        } else {
            resultEl.className = 'ms-2 text-success';
            resultEl.textContent = 'Clear.';
        }
    });

    // fromScan entries are keyed by hostname first (id = sanitized
    // hostname), falling back to address only when a matching hostname
    // isn't already known - but a device renamed in FPP (same IP, new
    // System Name) computes a different id than its old entry, so a naive
    // hostname-only match saw that as a brand-new remote and left the old,
    // now-stale entry behind untouched: same physical device duplicated
    // under both names forever, each independently selectable and each
    // getting its own destination folder if both were ever selected.
    // Matching by address as a second pass (only when the hostname isn't
    // an exact match already) catches that case and renames the existing
    // entry in place instead - keeping its selected/source state, no
    // duplicate row. onRename(oldHostname, newHostname, address), if
    // given, fires once per rename detected this way. Also stamps
    // lastSeenAt on every multisync-sourced entry actually seen in this
    // scan (renderRemotes() uses it to flag one that hasn't shown up in
    // a while) - manually-added entries never get one, since they're
    // expected to not appear in a MultiSync scan by design.
    function mergeRemoteLists(fromScan, existing, onRename) {
        var byId = {};
        var byAddress = {};
        var nowIso = new Date().toISOString();
        (existing || []).forEach(function (r) {
            byId[r.id] = r;
            if (r.address) byAddress[r.address] = r;
        });
        var merged = (existing || []).slice();
        fromScan.forEach(function (r) {
            var id = (r.hostname || r.address).replace(/[^A-Za-z0-9._-]+/g, '_');
            var byAddr = r.address ? byAddress[r.address] : null;
            if (byId[id]) {
                byId[id].address = r.address;
                byId[id].lastSeenAt = nowIso;
            } else if (byAddr && byAddr.hostname !== r.hostname) {
                var oldId = byAddr.id;
                var oldHostname = byAddr.hostname;
                byAddr.hostname = r.hostname;
                byAddr.id = id;
                byAddr.lastSeenAt = nowIso;
                delete byId[oldId];
                byId[id] = byAddr;
                byAddress[r.address] = byAddr;
                if (onRename) onRename(oldHostname, r.hostname, r.address);
            } else {
                var nr = { id: id, hostname: r.hostname, address: r.address, selected: false, source: 'multisync', lastSeenAt: nowIso };
                merged.push(nr);
                byId[id] = nr;
                if (r.address) byAddress[r.address] = nr;
            }
        });
        return merged;
    }

    // Shows/clears the spinner FPP's own loading states use (see
    // .fpp-backup-action-loading in fpp.css) on a list container while a
    // scan is in flight, matching the file-copy page's loading idiom.
    function setScanning(elId) {
        var el = document.getElementById(elId);
        el.className = 'mt-2 fpp-backup-action-loading';
        el.innerHTML = 'Scanning...';
    }
    function setScanError(elId, msg) {
        var el = document.getElementById(elId);
        el.className = 'mt-2';
        el.innerHTML = 'Error: ' + msg;
    }

    function loadAll() {
        // Fetched first (and awaited) rather than in parallel with the
        // rest below: it's a fast, purely-local lookup (no network scan),
        // and renderRemotes() needs state.hostInfo already populated the
        // very first time it runs, or the "Host" badge would only appear
        // after some later re-render.
        api('hostInfo').then(function (res) {
            if (res.ok) { state.hostInfo = res.data; }
            loadAllAfterHostInfo();
        });
    }

    function loadAllAfterHostInfo() {
        api('loadSettings').then(function (res) {
            if (!res.ok) {
                setScanError('rb-storageList', res.error);
                setScanError('rb-remoteList', res.error);
                return;
            }
            state.settings = res.data;
            document.getElementById('rb-hostEnabled').checked = !!state.settings.hostModeEnabled;
            document.getElementById('rb-deleteExtra').checked = !!state.settings.deleteExtraneous;
            document.getElementById('rb-snapshotMode').checked = !!state.settings.snapshotMode;
            document.getElementById('rb-includeSystemConfig').checked = state.settings.includeSystemConfig !== false;
            document.getElementById('rb-autoFailoverOnLowSpace').checked = !!state.settings.autoFailoverOnLowSpace;
            var playPolicy = state.settings.remotePlayingPolicy === 'skip' ? 'skip' : 'stop';
            document.getElementById('rb-playPolicy-' + playPolicy).checked = true;
            document.getElementById('rb-maxConcurrent').value = state.settings.maxConcurrent || 2;
            document.getElementById('rb-logRetentionCount').value = state.settings.logRetentionCount || 15;
            document.getElementById('rb-sshUser').value = state.settings.sshUser || 'fpp';
            document.getElementById('rb-sshPort').value = state.settings.sshPort || 22;
            document.getElementById('rb-sshPassword').value = state.settings.sshPassword || '';
            document.getElementById('rb-excludes').value = (state.settings.excludes || []).join('\n');
            state.remotes = state.settings.remotes || [];
            renderRemotes();
            renderStorage();
            renderScheduleMasterSelect();
        });
        api('probeStorage').then(function (res) {
            if (res.ok) { state.storage = res.data; renderStorage(); }
            else { setScanError('rb-storageList', res.error); }
        });
        api('probeRemotes').then(function (res) {
            if (res.ok) {
                state.remotes = mergeRemoteLists(res.data.remotes || [], state.remotes);
                renderRemotes();
                renderScheduleMasterSelect();
            } else {
                setScanError('rb-remoteList', res.error);
            }
        });
    }

    document.getElementById('rb-refreshStorage').addEventListener('click', function () {
        setScanning('rb-storageList');
        api('probeStorage').then(function (res) {
            if (res.ok) { state.storage = res.data; renderStorage(); }
            else { setScanError('rb-storageList', res.error); }
        });
    });

    // Same probeStorage data as the primary Rescan button above - a
    // second button here just so the secondary-drive section can be
    // refreshed without scrolling up, and both stay in sync either way
    // since renderStorage() always calls renderStorage2() too.
    document.getElementById('rb-refreshStorage2').addEventListener('click', function () {
        setScanning('rb-storageList2');
        api('probeStorage').then(function (res) {
            if (res.ok) { state.storage = res.data; renderStorage(); }
            else { setScanError('rb-storageList2', res.error); }
        });
    });

    document.getElementById('rb-refreshRemotes').addEventListener('click', function () {
        setScanning('rb-remoteList');
        api('probeRemotes').then(function (res) {
            if (res.ok) {
                var renamed = [];
                state.remotes = mergeRemoteLists(res.data.remotes || [], state.remotes, function (oldName, newName) {
                    renamed.push(oldName + ' → ' + newName);
                });
                renderRemotes();
                if (renamed.length) {
                    $.jGrowl('Detected a System Name change on the same address: ' + renamed.join(', ') +
                        '. Updated in place (selection kept) instead of adding a duplicate - click "Save Settings" to keep it.',
                        { life: 6000, themeState: 'info' });
                }
            } else {
                setScanError('rb-remoteList', res.error);
            }
        });
    });

    document.getElementById('rb-addManual').addEventListener('click', function () {
        var host = document.getElementById('rb-manualHost').value.trim();
        var addr = document.getElementById('rb-manualAddr').value.trim();
        if (!host || !addr) { $.jGrowl('Hostname and IP address are both required.', { life: 6000, themeState: 'danger' }); return; }
        var id = host.replace(/[^A-Za-z0-9._-]+/g, '_');
        state.remotes.push({ id: id, hostname: host, address: addr, selected: true, source: 'manual' });
        document.getElementById('rb-manualHost').value = '';
        document.getElementById('rb-manualAddr').value = '';
        renderRemotes();
        // Added pre-selected, so push the key right away same as checking a box.
        pushKeyFor(id, addr, null, false);
    });

    document.getElementById('rb-save').addEventListener('click', function () {
        var storageChoice = document.querySelector('input[name="rb-storage-choice"]:checked');
        var checks = document.getElementsByClassName('rb-remote-check');
        var selectedIds = {};
        Array.prototype.forEach.call(checks, function (c) { selectedIds[c.getAttribute('data-id')] = c.checked; });
        var remotesOut = state.remotes.map(function (r) {
            return { id: r.id, hostname: r.hostname, address: r.address, selected: !!selectedIds[r.id], source: r.source, lastSeenAt: r.lastSeenAt || null };
        });

        var body = {
            hostModeEnabled: document.getElementById('rb-hostEnabled').checked,
            destinationMount: storageChoice ? storageChoice.value : (state.settings.destinationMount || ''),
            deleteExtraneous: document.getElementById('rb-deleteExtra').checked,
            snapshotMode: document.getElementById('rb-snapshotMode').checked,
            includeSystemConfig: document.getElementById('rb-includeSystemConfig').checked,
            autoFailoverOnLowSpace: document.getElementById('rb-autoFailoverOnLowSpace').checked,
            remotePlayingPolicy: document.getElementById('rb-playPolicy-skip').checked ? 'skip' : 'stop',
            scheduleMasterAddress: currentScheduleMasterAddress(),
            maxConcurrent: parseInt(document.getElementById('rb-maxConcurrent').value, 10) || 2,
            logRetentionCount: parseInt(document.getElementById('rb-logRetentionCount').value, 10) || 15,
            sshUser: document.getElementById('rb-sshUser').value || 'fpp',
            sshPort: parseInt(document.getElementById('rb-sshPort').value, 10) || 22,
            sshPassword: document.getElementById('rb-sshPassword').value,
            excludes: document.getElementById('rb-excludes').value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean),
            remotes: remotesOut
        };

        api('saveSettings', { body: body }).then(function (res) {
            var msg = document.getElementById('rb-saveMsg');
            if (res.ok) {
                state.settings = res.data;
                state.remotes = res.data.remotes;
                // A saved destination is a fresh episode as far as the missing-drive
                // popup is concerned - reset so a still-bad pick gets its own popup
                // on the next poll instead of staying suppressed by an earlier one.
                rbDestMissingPopupShown = false;
                msg.textContent = 'Saved.';
                msg.className = 'ms-2 text-success';
                $.jGrowl('Remote Backup settings saved.', { life: 6000, themeState: 'success' });
            } else {
                msg.textContent = 'Error: ' + (res.error || 'unknown');
                msg.className = 'ms-2 text-danger';
                $.jGrowl('Failed to save Remote Backup settings: ' + (res.error || 'unknown'), { life: 6000, themeState: 'danger' });
            }
            setTimeout(function () { msg.textContent = ''; }, 4000);
        });
    });

    loadAll();
    rbPollDestination();
})();
</script>
