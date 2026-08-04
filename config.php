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
            <div class="alert alert-warning" style="border:1px solid #c99; background:#fff3cd; padding:8px; margin-bottom:8px;">
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
            <div id="rb-storageList" class="mt-2">Scanning...</div>
            <small>NVMe/SSD storage is recommended and listed first when found. If none is present,
            attach a USB flash drive, or fall back to remaining space on the SD card.</small>
        </div>
    </fieldset>

    <fieldset class="border rounded p-2 mt-2">
        <legend>Remote Systems to Back Up</legend>
        <div class="p-2">
            <button type="button" class="btn btn-secondary btn-sm" id="rb-refreshRemotes">Rescan MultiSync Remotes</button>
            <div id="rb-remoteList" class="mt-2">Scanning...</div>
            <hr>
            <b>Manually add a remote</b> (use this if it wasn't found by MultiSync scan):<br>
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
            <br>
            Max concurrent transfers:
            <input id="rb-maxConcurrent" type="number" min="1" max="8" style="width:4em">
            <small>(default 2: the first devices start immediately, each finished transfer lets the next queued remote start)</small><br>
            <br>
            SSH user: <input id="rb-sshUser" style="width:8em">
            SSH port: <input id="rb-sshPort" type="number" style="width:6em">
            Default SSH password: <input id="rb-sshPassword" type="password" style="width:10em" placeholder="falcon">
            <small>(used automatically when you select a remote - only needed if you've changed it fleet-wide from the FPP default)</small><br>
            <br>
            Exclude patterns (one per line, paths are relative to the remote's <code>/home/fpp/media</code>):<br>
            <textarea id="rb-excludes" rows="4" style="width:100%"></textarea>
        </div>
    </fieldset>

    <button type="button" class="btn btn-primary mt-2" id="rb-save">Save Settings</button>
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

    var state = { settings: null, storage: null, remotes: [] };

    function renderStorage() {
        var el = document.getElementById('rb-storageList');
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
                var checked = state.settings && state.settings.destinationMount === mp ? 'checked' : '';
                var id = 'rb-storage-' + mp.replace(/[^A-Za-z0-9]/g, '_');
                html += '<div><label><input type="radio" name="rb-storage-choice" value="' + mp + '" ' + checked + ' id="' + id + '"> ' +
                    (d.deviceLabel || mp) + ' &mdash; mounted at ' + mp + ' &mdash; ' + humanBytes(d.availBytes) + ' free</label>';
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
                if (!confirm('Unmount the backup destination drive from /mnt/Backups?\n\nBackups already on it are kept - this just detaches it from the system so it is safe to physically unplug. You will need to Mount it again (Config > Storage) before the next backup run.')) return;
                btn.disabled = true;
                btn.textContent = 'Unmounting...';
                api('unmountUsb', { body: {} }).then(function (res) {
                    if (res.ok) {
                        alert('Unmounted ' + res.mountpoint + (res.device ? ' (' + res.device + ')' : '') + '.' + (res.removedFstab ? ' Removed it from /etc/fstab so it will not block boot if left unplugged.' : '') + '\n\nIt is now safe to disconnect the drive.');
                        api('probeStorage').then(function (r2) {
                            if (r2.ok) { state.storage = r2.data; renderStorage(); }
                        });
                    } else {
                        alert('Unmount failed: ' + (res.error || 'unknown error'));
                        btn.disabled = false;
                        btn.textContent = 'Unmount';
                    }
                });
            });
        });

        Array.prototype.forEach.call(document.getElementsByClassName('rb-mount-usb'), function (btn) {
            btn.addEventListener('click', function () {
                var device = btn.getAttribute('data-device');
                btn.disabled = true;
                btn.textContent = 'Mounting...';
                api('mountUsb', { body: { device: device } }).then(function (res) {
                    if (res.ok) {
                        alert('Mounted ' + device + ' at ' + res.mountpoint + (res.addedFstab ? ' (added to /etc/fstab so it survives reboots)' : ''));
                        api('probeStorage').then(function (r2) {
                            if (r2.ok) { state.storage = r2.data; renderStorage(); }
                        });
                    } else {
                        alert('Mount failed: ' + (res.error || 'unknown error'));
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
            if (!confirm('This will ERASE ALL DATA on ' + device + ' (' + size + ').' + warnExtra + '\n\nThis cannot be undone. Continue?')) return;

            var fsAnswer = prompt(
                'Which filesystem?\n\n' +
                '  exfat - recommended if you want to read this drive on Windows, a Mac,\n' +
                '          or another Pi/computer directly. No 4GB single-file size limit\n' +
                '          (unlike FAT32), unlike ext4 it needs no extra driver on Windows/Mac.\n\n' +
                '  ext4  - Linux-only; plug it into Windows/Mac and it won\'t be readable\n' +
                '          without extra software. Slightly more standard on Linux.\n\n' +
                'Type exfat or ext4:',
                'exfat'
            );
            if (fsAnswer !== 'exfat' && fsAnswer !== 'ext4') { alert('Type exactly "exfat" or "ext4" - aborted, nothing was formatted.'); return; }
            var fstype = fsAnswer;

            var typed = prompt('Last check - type the device path exactly to confirm formatting ' + device + ' as ' + fstype + ':');
            if (typed !== device) { alert('Confirmation text did not match "' + device + '" - aborted, nothing was formatted.'); return; }

            btn.disabled = true;
            btn.textContent = 'Formatting...';
            api('formatUsb', {
                body: { device: device, fstype: fstype, confirm: 'I_UNDERSTAND_THIS_ERASES_THE_DRIVE' },
                timeoutMs: 120000
            }).then(function (res) {
                if (res.ok) {
                    alert('Formatted (' + fstype + ') and mounted ' + device + ' at ' + res.mountpoint + (res.addedFstab ? ' (added to /etc/fstab)' : '') + (res.clearedAllStatus ? '\n\nAll previous backup status on the Status page was cleared since this was your active destination drive.' : ''));
                    api('probeStorage').then(function (r2) {
                        if (r2.ok) { state.storage = r2.data; renderStorage(); }
                    });
                } else {
                    alert('Format failed: ' + (res.error || 'unknown error'));
                    btn.disabled = false;
                    btn.textContent = resetLabel;
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
    }

    function remoteRowId(r) { return 'rb-remote-' + (r.id || r.hostname).replace(/[^A-Za-z0-9]/g, '_'); }

    function keyStatusId(id) { return 'rb-keystatus-' + id.replace(/[^A-Za-z0-9]/g, '_'); }

    function setKeyStatus(id, text, color) {
        var el = document.getElementById(keyStatusId(id));
        if (el) { el.textContent = text; el.style.color = color || '#666'; }
    }

    // Shared by both the auto-push-on-select path and the manual button.
    // password === null means "don't prompt, just try the FPP default"
    // (used for auto-push, so selecting remotes stays a one-click action).
    function defaultSshPassword() {
        var el = document.getElementById('rb-sshPassword');
        return (el && el.value) || (state.settings && state.settings.sshPassword) || 'falcon';
    }

    function pushKeyFor(id, address, password, announce) {
        setKeyStatus(id, 'pushing key...', '#666');
        return api('pushSshKey', {
            body: {
                address: address,
                sshUser: document.getElementById('rb-sshUser').value || 'fpp',
                sshPort: document.getElementById('rb-sshPort').value || 22,
                password: password || defaultSshPassword()
            }
        }).then(function (res) {
            if (res.ok) {
                setKeyStatus(id, 'key installed', 'green');
            } else {
                setKeyStatus(id, 'key push failed - click "Push SSH Key" to retry with a password', 'red');
                if (announce) alert(res.message || res.error || 'Failed');
            }
            return res;
        });
    }

    function renderRemotes() {
        var el = document.getElementById('rb-remoteList');
        if (!state.remotes.length) { el.innerHTML = '<em>No remotes found yet. Rescan, or add one manually below.</em>'; return; }
        var html = '<table class="table table-sm"><tr><th></th><th>Hostname</th><th>Address</th><th>Source</th><th></th></tr>';
        state.remotes.forEach(function (r) {
            html += '<tr>' +
                '<td><input type="checkbox" class="rb-remote-check" data-id="' + r.id + '" data-addr="' + r.address + '" ' + (r.selected ? 'checked' : '') + '></td>' +
                '<td>' + r.hostname + '</td>' +
                '<td>' + r.address + '</td>' +
                '<td>' + (r.source || 'multisync') + '</td>' +
                '<td><button type="button" class="btn btn-outline-secondary btn-sm rb-push-key" data-id="' + r.id + '" data-addr="' + r.address + '">Push SSH Key</button> ' +
                '<small id="' + keyStatusId(r.id) + '" style="color:#666;"></small></td>' +
                '</tr>';
        });
        html += '</table>';
        el.innerHTML = html;

        Array.prototype.forEach.call(document.getElementsByClassName('rb-push-key'), function (btn) {
            btn.addEventListener('click', function () {
                var addr = btn.getAttribute('data-addr');
                var id = btn.getAttribute('data-id');
                var pw = prompt('SSH password for fpp@' + addr + ':', defaultSshPassword());
                pushKeyFor(id, addr, pw, true);
            });
        });

        // Auto-push the Host's SSH key the moment a remote is selected,
        // so checking the box is enough to make it backup-ready - no
        // separate manual step needed unless the default password fails.
        Array.prototype.forEach.call(document.getElementsByClassName('rb-remote-check'), function (chk) {
            chk.addEventListener('change', function () {
                var id = chk.getAttribute('data-id');
                var addr = chk.getAttribute('data-addr');
                var r = state.remotes.filter(function (x) { return x.id === id; })[0];
                if (r) r.selected = chk.checked;
                if (chk.checked) {
                    pushKeyFor(id, addr, null, false);
                } else {
                    setKeyStatus(id, '', '#666');
                }
            });
        });
    }

    function mergeRemoteLists(fromScan, existing) {
        var byId = {};
        (existing || []).forEach(function (r) { byId[r.id] = r; });
        var merged = (existing || []).slice();
        fromScan.forEach(function (r) {
            var id = (r.hostname || r.address).replace(/[^A-Za-z0-9._-]+/g, '_');
            if (!byId[id]) {
                var nr = { id: id, hostname: r.hostname, address: r.address, selected: false, source: 'multisync' };
                merged.push(nr);
                byId[id] = nr;
            } else {
                byId[id].address = r.address;
            }
        });
        return merged;
    }

    function loadAll() {
        api('loadSettings').then(function (res) {
            if (!res.ok) {
                document.getElementById('rb-storageList').innerHTML = 'Error loading settings: ' + res.error;
                document.getElementById('rb-remoteList').innerHTML = 'Error loading settings: ' + res.error;
                return;
            }
            state.settings = res.data;
            document.getElementById('rb-hostEnabled').checked = !!state.settings.hostModeEnabled;
            document.getElementById('rb-deleteExtra').checked = !!state.settings.deleteExtraneous;
            document.getElementById('rb-snapshotMode').checked = !!state.settings.snapshotMode;
            document.getElementById('rb-maxConcurrent').value = state.settings.maxConcurrent || 2;
            document.getElementById('rb-sshUser').value = state.settings.sshUser || 'fpp';
            document.getElementById('rb-sshPort').value = state.settings.sshPort || 22;
            document.getElementById('rb-sshPassword').value = state.settings.sshPassword || 'falcon';
            document.getElementById('rb-excludes').value = (state.settings.excludes || []).join('\n');
            state.remotes = state.settings.remotes || [];
            renderRemotes();
            renderStorage();
        });
        api('probeStorage').then(function (res) {
            if (res.ok) { state.storage = res.data; renderStorage(); }
            else { document.getElementById('rb-storageList').innerHTML = 'Error: ' + res.error; }
        });
        api('probeRemotes').then(function (res) {
            if (res.ok) {
                state.remotes = mergeRemoteLists(res.data.remotes || [], state.remotes);
                renderRemotes();
            } else {
                document.getElementById('rb-remoteList').innerHTML = 'Error: ' + res.error;
            }
        });
    }

    document.getElementById('rb-refreshStorage').addEventListener('click', function () {
        document.getElementById('rb-storageList').innerHTML = 'Scanning...';
        api('probeStorage').then(function (res) {
            if (res.ok) { state.storage = res.data; renderStorage(); }
            else { document.getElementById('rb-storageList').innerHTML = 'Error: ' + res.error; }
        });
    });

    document.getElementById('rb-refreshRemotes').addEventListener('click', function () {
        document.getElementById('rb-remoteList').innerHTML = 'Scanning...';
        api('probeRemotes').then(function (res) {
            if (res.ok) { state.remotes = mergeRemoteLists(res.data.remotes || [], state.remotes); renderRemotes(); }
            else { document.getElementById('rb-remoteList').innerHTML = 'Error: ' + res.error; }
        });
    });

    document.getElementById('rb-addManual').addEventListener('click', function () {
        var host = document.getElementById('rb-manualHost').value.trim();
        var addr = document.getElementById('rb-manualAddr').value.trim();
        if (!host || !addr) { alert('Hostname and IP address are both required.'); return; }
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
            return { id: r.id, hostname: r.hostname, address: r.address, selected: !!selectedIds[r.id], source: r.source };
        });

        var body = {
            hostModeEnabled: document.getElementById('rb-hostEnabled').checked,
            destinationMount: storageChoice ? storageChoice.value : (state.settings.destinationMount || ''),
            deleteExtraneous: document.getElementById('rb-deleteExtra').checked,
            snapshotMode: document.getElementById('rb-snapshotMode').checked,
            maxConcurrent: parseInt(document.getElementById('rb-maxConcurrent').value, 10) || 2,
            sshUser: document.getElementById('rb-sshUser').value || 'fpp',
            sshPort: parseInt(document.getElementById('rb-sshPort').value, 10) || 22,
            sshPassword: document.getElementById('rb-sshPassword').value || 'falcon',
            excludes: document.getElementById('rb-excludes').value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean),
            remotes: remotesOut
        };

        api('saveSettings', { body: body }).then(function (res) {
            var msg = document.getElementById('rb-saveMsg');
            if (res.ok) {
                state.settings = res.data;
                state.remotes = res.data.remotes;
                msg.textContent = 'Saved.';
                msg.style.color = 'green';
            } else {
                msg.textContent = 'Error: ' + (res.error || 'unknown');
                msg.style.color = 'red';
            }
            setTimeout(function () { msg.textContent = ''; }, 4000);
        });
    });

    loadAll();
})();
</script>
