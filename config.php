<?php
// Don't rely on FPP's own $plugin/$pluginName globals here - depending on
// which code path included this file, that variable may be unset or even
// hold an unrelated leftover value (seen in the wild: boolean false, which
// json_encode()'s to the bare word `false` and breaks the JS PLUGIN var).
// The plugin's own directory name is always correct and unambiguous.
$rbPlugin = basename(__DIR__);
?>
<div class="mt-2" id="rb-config">
    <style>
        /* Config page walkthrough (see the "rb-tour" JS below). Only the
           spotlight/arrow need custom styling - the popup text box itself
           is plain Bootstrap (.card) so it follows FPP's own light/dark
           theme instead of a hardcoded color here. The highlight/arrow
           accent reads Bootstrap's own --bs-primary variable (falling
           back to its default blue only if that variable isn't defined at
           all) instead of a bare hex value, so it follows FPP's theme too
           rather than staying a fixed color if FPP's dark theme overrides
           its accent color. The dimming scrim's rgba(0,0,0,...) is a
           deliberate exception, not an oversight - a translucent black
           backdrop behind a spotlight is the same convention regardless of
           theme (it's what Bootstrap's own modal backdrop does too), not a
           color that needs to adapt to light/dark. */
        #rb-tour-highlight {
            position: fixed;
            z-index: 2000;
            border: 2px solid var(--bs-primary, #0d6efd);
            border-radius: 6px;
            box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.55);
            pointer-events: none;
            transition: top 0.2s ease, left 0.2s ease, width 0.2s ease, height 0.2s ease;
        }
        #rb-tour-popup {
            position: fixed;
            z-index: 2001;
            width: 320px;
            max-width: calc(100vw - 16px);
        }
        #rb-tour-arrow {
            position: fixed;
            z-index: 2001;
            width: 0;
            height: 0;
            border-left: 9px solid transparent;
            border-right: 9px solid transparent;
        }
        #rb-tour-arrow.rb-tour-arrow-above { border-top: 9px solid var(--bs-primary, #0d6efd); }
        #rb-tour-arrow.rb-tour-arrow-below { border-bottom: 9px solid var(--bs-primary, #0d6efd); }

        /* Floating Save bar - opt-out via its own "Keep floating while
           scrolling" checkbox (see rb-floatingSaveToggle in the JS below).
           bg-body/border-top/shadow-sm (Bootstrap classes on the element
           itself, not hardcoded here) keep it theme-correct in dark mode
           instead of a fixed color. */
        #rb-save-bar.rb-save-floating {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 1500;
            margin: 0;
            padding: 0.6em 1em;
        }
        /* Keeps the floating bar from covering the last fieldset's own
           content once you've scrolled all the way down - only applied
           while the bar is actually floating. */
        #rb-config.rb-save-floating-active {
            padding-bottom: 4em;
        }
    </style>

    <div class="d-flex justify-content-end align-items-center mb-2">
        <a href="#" id="rb-onboardingReplay" class="small me-2">Replay walkthrough</a>
        <label class="small text-muted mb-0">
            <input type="checkbox" id="rb-onboardingTourEnabled">
            Show the setup walkthrough
        </label>
        <i class="fas fa-question-circle fpp-help-popover ms-1" data-help-content="rb-help-onboarding"
            data-help-title="Show the setup walkthrough" style="font-size:0.8em; cursor:help;"></i>
        <div id="rb-help-onboarding" class="d-none">
            <div class="fpp-help-content">
                <p class="mb-0">"Replay walkthrough" steps through it again right now, regardless of this box's
                    state. The checkbox itself controls something different: whether the walkthrough keeps showing
                    automatically, once, the next time this plugin is installed fresh on a system that's never seen
                    it. Unchecking it doesn't stop anything already on screen - after you Save Settings, it just
                    means that automatic first-run popup won't happen on a future fresh install either.</p>
            </div>
        </div>
    </div>

    <fieldset class="border rounded p-2" id="rb-fieldset-hostmode">
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

    <fieldset class="border rounded p-2 mt-2" id="rb-fieldset-storage">
        <legend>Backup Destination Storage</legend>
        <div class="p-2">
            <button type="button" class="btn btn-secondary btn-sm" id="rb-refreshStorage">Rescan Storage Devices</button>
            <div id="rb-storageList" class="mt-2 fpp-backup-action-loading">Scanning...</div>
            <small>NVMe/SSD storage is recommended and listed first when found. If none is present,
            attach a USB flash drive, or fall back to remaining space on the SD card.</small>
            <hr>
            <label><input type="checkbox" id="rb-enableRestoreBindMount">
                Let remotes and FPP's own File Copy Backup/Restore see current backups on this drive
                <strong>without unmounting it first</strong></label>
            <div class="ms-3">
                <small class="text-muted">On by default. When on (and this drive is mounted and selected as the
                    destination above), its contents are made visible at FPP's normal backups path automatically -
                    no more choosing between "leave it mounted for backups" and "unmount it so restores can see it."
                    Turning this off (or switching the destination away from this drive) reverts to the previous
                    behavior immediately - unmount the drive here first if you want FPP's restore to see it.
                    <strong>Built-in safeguard:</strong> it's automatically paused for the duration of every backup
                    run and restored the moment the run finishes, so FPP's native restore can never read an
                    in-progress, partly-written backup off this drive - only ever a complete one from before the
                    current run started. On the rare occasion that pause itself can't complete (something else has
                    a file on this drive open right now), the Status page shows a clear warning rather than this
                    staying silent. See
                    <a href="https://github.com/bobreese/fpp-plugin-RemoteBackup/blob/master/docs/usb-drive-setup.md" target="_blank" rel="noopener">USB Drive Setup</a> for details.</small>
                <div id="rb-bindMountStatus" class="mt-1"></div>
            </div>
        </div>
    </fieldset>

    <fieldset class="border rounded p-2 mt-2" id="rb-fieldset-clone">
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

    <fieldset class="border rounded p-2 mt-2" id="rb-fieldset-remotes">
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

    <fieldset class="border rounded p-2 mt-2" id="rb-fieldset-options">
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
                &mdash; off by default, so a scheduled backup refuses (with a reason logged, and a popup here/on Status) rather than silently landing somewhere unexpected. A manual Start Backup always shows the popup either way, regardless of this setting.
                Landing on SD Card / System Storage this way is re-checked against its own free space too (reserving 500MB for
                system stability, same as any other run there - see <a href="https://github.com/bobreese/fpp-plugin-RemoteBackup/blob/master/docs/troubleshooting.md#backup-space-insufficient" target="_blank" rel="noopener">Backup Space Insufficient</a>)
                rather than just assumed to fit - it refuses instead of proceeding if it turns out not to.</label><br>
            <label><input type="checkbox" id="rb-verifyAfterRun">
                Verify backup integrity after each run - a second read-only rsync pass compares source and destination once
                more and flags anything still different, shown as a small badge on the Status page.
                &mdash; off by default, since it adds a second directory-listing pass over SSH to every remote's run. This
                checks the same thing rsync's own transfer already does (file size and modification time), not a byte-for-byte
                checksum, and a remote actively recording/playing between the backup and this check can show a false
                "differs" for content that's simply new since the backup, not actually missed.</label><br>
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

    <fieldset class="border rounded p-2 mt-2" id="rb-fieldset-email">
        <legend>Email Settings</legend>
        <div class="p-2">
            <label><input type="checkbox" id="rb-emailNotifyEnabled">
                Send an email status update after backup runs</label><br>
            <small>Reuses FPP's own outbound email (<code>FPP Settings &gt; Email</code>) - it needs a real SMTP
                server entered there and "Configure Email" clicked at least once, or there's nowhere for this to
                send to. This plugin doesn't manage email delivery itself.</small>
            <div id="rb-emailFppStatus" class="mt-1 small"></div>
            <div id="rb-emailSubOptions" class="mt-2" style="display:none">
                <strong>Send for:</strong><br>
                <label class="ms-3"><input type="radio" name="rb-emailScope-choice" id="rb-emailScope-scheduled" value="scheduled">
                    Scheduled runs only (default) - a manual Start Backup click is already being watched live on the Status page.</label><br>
                <label class="ms-3"><input type="radio" name="rb-emailScope-choice" id="rb-emailScope-all" value="all">
                    All backup runs, manual and scheduled.</label><br>
                <br>
                <strong>Send when:</strong><br>
                <label class="ms-3"><input type="radio" name="rb-emailOutcome-choice" id="rb-emailOutcome-completed" value="completed">
                    At least one remote completed.</label><br>
                <label class="ms-3"><input type="radio" name="rb-emailOutcome-choice" id="rb-emailOutcome-failed" value="failed">
                    At least one remote failed.</label><br>
                <label class="ms-3"><input type="radio" name="rb-emailOutcome-choice" id="rb-emailOutcome-skipped" value="skipped">
                    At least one remote was skipped (busy with a show when the run started).</label><br>
                <label class="ms-3"><input type="radio" name="rb-emailOutcome-choice" id="rb-emailOutcome-failed_or_skipped" value="failed_or_skipped">
                    At least one remote failed and/or was skipped (default) - the case most worth an alert.</label><br>
                <label class="ms-3"><input type="radio" name="rb-emailOutcome-choice" id="rb-emailOutcome-all" value="all">
                    Every included run, regardless of outcome.</label><br>
                <br>
                <small>A run's email lists every remote's own result. A run refused before any remote started
                    (halted, no destination, low space, etc.) counts as "failed" here and sends a short reason
                    instead of a per-remote list. Dry Runs never send email, and a run refused only because another
                    run was already in progress never does either - that's routine overlap, not a problem.</small>
            </div>
        </div>
    </fieldset>

    <fieldset class="border rounded p-2 mt-2" id="rb-fieldset-schedule">
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
            <!-- overflow-x-auto: renderScheduleResults() below builds a 7-column
                 bordered table (one per day of week) that forces the whole page
                 to scroll sideways on a phone screen without it - same missing-
                 wrapper bug as the Remote Systems table above and the Backup
                 Status table (see status.php's own comment on this). -->
            <div id="rb-scheduleResults" class="mt-2 overflow-x-auto"></div>
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

    <div id="rb-save-bar" class="mt-2 d-flex align-items-center flex-wrap" style="gap:0.5em;">
        <button type="button" class="btn btn-primary" id="rb-save">Save Settings</button>
        <a class="btn btn-outline-secondary" href="plugin.php?plugin=<?php echo urlencode($rbPlugin); ?>&page=status.php">Status Page</a>
        <span id="rb-saveMsg" class="ms-2"></span>
        <span id="rb-sdcard-purge-note" class="ms-2 small"></span>
        <label class="small text-muted mb-0 ms-auto" style="cursor:pointer; white-space:nowrap;">
            <input type="checkbox" id="rb-floatingSaveToggle"> Keep floating while scrolling
        </label>
    </div>
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

    // Shows which storage device (if any) the "see current backups without
    // unmounting" bind mount is actually live on right now - reads from the
    // most recent 'status' poll response (state.lastStatus) plus the
    // checkbox's own current value, so it reflects reality (drive mounted +
    // it's the saved destination + no run in progress) rather than just
    // whether the box is checked. Called after every status poll, after
    // settings load, and right after a successful Save Settings.
    function renderBindMountStatus() {
        var el = document.getElementById('rb-bindMountStatus');
        if (!el) return;
        var enabled = document.getElementById('rb-enableRestoreBindMount').checked;
        if (!enabled) { el.innerHTML = ''; return; }
        var res = state.lastStatus;
        if (!res || !res.ok) { el.innerHTML = ''; return; }
        if (res.bindMountActive && res.destStorage) {
            var d = res.destStorage;
            var labelHtml = d.label ? ' (volume label "' + d.label + '")' : '';
            el.innerHTML = '<span class="text-success">&#10003; Currently active on <code>' + d.mountpoint + '</code>' +
                labelHtml + ' &mdash; ' + humanBytes(d.freeBytes) + ' free of ' + humanBytes(d.totalBytes) + '</span>';
        } else if (res.active) {
            // Deliberately withdrawn for the duration of this run, not a
            // misconfiguration - see the safeguard note above the checkbox.
            el.innerHTML = '<span class="text-muted">Temporarily paused &mdash; a backup run is in progress. ' +
                'This is expected: it keeps FPP\'s native restore from reading an in-progress backup. ' +
                'Resumes automatically once the run finishes.</span>';
        } else {
            el.innerHTML = '<span class="text-muted">Not currently active - the drive at <code>/mnt/Backups</code> ' +
                'must be mounted and saved as the destination above for this to take effect.</span>';
        }
    }

    // Shows/hides the "Send for:"/"Send when:" sub-options based on the
    // master checkbox, and - only while it's checked - warns if FPP's own
    // Setting > Email has no destination address configured yet (read
    // once at page load via loadSettings' fppEmailToEmail, not re-checked
    // per keystroke - that setting lives on a completely different page).
    // A warning here is informational only: nothing below is disabled,
    // since saving now and configuring FPP's email afterward is a
    // perfectly normal order to do this in.
    function renderEmailFppStatus() {
        var sub = document.getElementById('rb-emailSubOptions');
        var status = document.getElementById('rb-emailFppStatus');
        var enabled = document.getElementById('rb-emailNotifyEnabled').checked;
        if (sub) sub.style.display = enabled ? '' : 'none';
        if (!status) return;
        if (!enabled) { status.innerHTML = ''; return; }
        if (state.fppEmailToEmail) {
            // Address kept out of sight by default - this box can be visible to
            // anyone glancing at the screen, and the destination address isn't
            // needed to confirm email is set up, only to double-check *which*
            // address. Click-to-reveal (not hover: this page has to work on a
            // phone, and hover isn't a thing there) rather than the
            // fpp-help-popover "?" icons used elsewhere on this page - those are
            // wired up once, early in page load, from icons already in the DOM
            // at that time, and this status line (and its icon) don't exist yet
            // at that point since it's only built once FPP's own settings have
            // loaded.
            status.innerHTML = '<span class="text-success">&#10003; FPP Setting &gt; Email is configured. ' +
                '<button type="button" class="btn btn-link btn-sm p-0 align-baseline" id="rb-emailAddrToggle" ' +
                'title="Show the address it sends to">(?)</button>' +
                '<span id="rb-emailAddrReveal" class="d-none"> Sends to <code>' + state.fppEmailToEmail + '</code>.</span></span>';
            var toggle = document.getElementById('rb-emailAddrToggle');
            var reveal = document.getElementById('rb-emailAddrReveal');
            if (toggle && reveal) {
                toggle.addEventListener('click', function () {
                    var wasHidden = reveal.classList.contains('d-none');
                    reveal.classList.toggle('d-none');
                    toggle.textContent = wasHidden ? '(hide)' : '(?)';
                });
            }
        } else {
            status.innerHTML = '<span class="text-warning">FPP Setting &gt; Email has no destination address configured yet - ' +
                'these settings will save, but nothing will actually be emailed until that\'s set up.</span>';
        }
    }

    // Offer to remove leftover SD Card/System Storage backups when
    // switching away from it as the destination. Scoped deliberately to
    // just this one transition (leaving "/") - a real external drive
    // being swapped out already physically leaves with its data either
    // way, so there's nothing to clean up there; the SD card fallback is
    // the one case where forgotten backups quietly eat into the Host's
    // own limited system storage indefinitely.
    //
    // null = no pending choice (either not applicable, or already reset
    // by a successful save); true = remove on save; false = leave on save.
    // Staged here rather than acted on immediately - nothing actually
    // deletes anything until Save Settings is clicked and the save
    // succeeds, consistent with "nothing takes effect until you Save
    // Settings" everywhere else on this page.
    var rbPendingSdCardPurge = null;

    function renderSdCardPurgeNote() {
        var el = document.getElementById('rb-sdcard-purge-note');
        if (!el) return;
        if (rbPendingSdCardPurge === true) {
            el.textContent = 'SD Card backups will be removed when you save.';
            el.className = 'ms-2 small text-danger';
        } else if (rbPendingSdCardPurge === false) {
            el.textContent = 'SD Card backups will be left in place.';
            el.className = 'ms-2 small text-muted';
        } else {
            el.textContent = '';
        }
    }

    function rbShowSdCardLeavePopup() {
        var modalId = 'rb-sdcard-leave-modal';
        DoModalDialog({
            id: modalId,
            title: 'Leaving SD Card / System Storage as Destination',
            class: 'modal-m',
            backdrop: true,
            body: 'Your existing backups under <code>/home/fpp/media/backups</code> will be left in place ' +
                'unless you choose to remove them. This only affects backups on the SD card - nothing on ' +
                'your new destination is touched either way.<br><br>' +
                'Nothing happens immediately either way - your choice takes effect when you click ' +
                '<b>Save Settings</b>, same as every other Config change.',
            buttons: {
                'Leave Them': {
                    class: 'btn-secondary',
                    click: function () {
                        rbPendingSdCardPurge = false;
                        CloseModalDialog(modalId);
                        renderSdCardPurgeNote();
                    }
                },
                'Remove Them Now': {
                    class: 'btn-danger',
                    click: function () {
                        rbPendingSdCardPurge = true;
                        CloseModalDialog(modalId);
                        renderSdCardPurgeNote();
                    }
                }
            }
        });
    }

    // Fired on every storage radio change - compares against the
    // SERVER-SAVED destination (state.settings), not whatever else may
    // have been clicked in between, so switching between two non-SD-card
    // drives after already answering once doesn't re-ask (the SD card
    // content in question hasn't changed), and clicking back to "/"
    // clears any pending choice since there'd then be nothing to remove.
    function rbCheckSdCardLeaveTransition(newMount) {
        var savedMount = state.settings && state.settings.destinationMount;
        if (newMount === '/') {
            rbPendingSdCardPurge = null;
            renderSdCardPurgeNote();
            return;
        }
        if (savedMount !== '/') return;
        if (rbPendingSdCardPurge !== null) return;
        rbShowSdCardLeavePopup();
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
            state.lastStatus = res;
            renderBindMountStatus();
            setTimeout(rbPollDestination, RB_DEST_POLL_MS);
        });
    }

    var state = { settings: null, storage: null, remotes: [], hostInfo: null, lastStatus: null };

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

        // Offer to clean up leftover SD Card/System Storage backups the
        // moment a different destination is picked - not deferred to Save
        // Settings, so it's never a surprise buried behind a click that
        // might happen much later (especially now that Save can float far
        // below where this radio list is). See rbCheckSdCardLeaveTransition
        // for the actual decision + rbPendingSdCardPurge for how the
        // choice is staged until Save Settings actually commits it.
        Array.prototype.forEach.call(document.getElementsByName('rb-storage-choice'), function (radio) {
            radio.addEventListener('change', function () { rbCheckSdCardLeaveTransition(radio.value); });
        });

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
                // table-layout:fixed - without it this table sizes its columns to fit the
                // Filesystem <select>'s own content ("exFAT (recommended - readable on
                // Windows/Mac/Linux)"), which is wider than the modal itself on a phone -
                // the modal's own overflow-x:hidden then silently CLIPS the dropdown
                // instead of the page scrolling (confirmed with real headless Chromium:
                // the select rendered ~375px wide inside a 320px modal, unreachable past
                // the edge). Fixing columns to the table's own (already modal-bounded)
                // width, combined with max-width:100% on each control below, keeps every
                // control within the modal instead.
                '<table class="table table-sm table-borderless mb-0" style="table-layout:fixed;width:100%">' +
                '<tr><td>Filesystem:</td><td>' +
                '<select id="rb-format-fstype" class="form-select form-select-sm d-inline-block w-auto" style="max-width:100%">' +
                '<option value="exfat" selected>exFAT (recommended - readable on Windows/Mac/Linux)</option>' +
                '<option value="ext4">ext4 (Linux only)</option>' +
                '</select></td></tr>' +
                '<tr><td>Volume label:</td><td>' +
                '<input type="text" id="rb-format-label" class="form-control form-control-sm d-inline-block w-auto" style="max-width:100%" maxlength="11" value="Backups" autocomplete="off"></td></tr>' +
                '<tr><td>Type <code>' + device + '</code> to confirm:</td><td>' +
                '<input type="text" id="rb-format-confirm" class="form-control form-control-sm d-inline-block w-auto" style="max-width:100%" autocomplete="off"></td></tr>' +
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
                // See runFormatFlow()'s identical table above for why table-layout:fixed
                // and max-width:100% are needed here - without them the Filesystem
                // <select> silently gets clipped by the modal's overflow-x:hidden on a
                // phone screen instead of the page scrolling.
                '<table class="table table-sm table-borderless mb-0" style="table-layout:fixed;width:100%">' +
                '<tr><td>Filesystem:</td><td>' +
                '<select id="rb-format2-fstype" class="form-select form-select-sm d-inline-block w-auto" style="max-width:100%">' +
                '<option value="exfat" selected>exFAT (recommended - readable on Windows/Mac/Linux)</option>' +
                '<option value="ext4">ext4 (Linux only)</option>' +
                '</select></td></tr>' +
                '<tr><td>Volume label:</td><td>' +
                '<input type="text" id="rb-format2-label" class="form-control form-control-sm d-inline-block w-auto" style="max-width:100%" maxlength="11" value="Backups" autocomplete="off"></td></tr>' +
                '<tr><td>Type <code>' + device + '</code> to confirm:</td><td>' +
                '<input type="text" id="rb-format2-confirm" class="form-control form-control-sm d-inline-block w-auto" style="max-width:100%" autocomplete="off"></td></tr>' +
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
        // defaultSshPassword() returns null when nothing's configured -
        // fine for the auto-push path (the server does its own 'falcon'
        // fallback), but showing this box truly empty here just looks
        // broken: there's no visual sign that leaving it blank still
        // works, so a user with no custom default configured sees an
        // empty field and either cancels or guesses instead of clicking
        // Ok. Mirror the server's own final fallback here so what's shown
        // (as dots) always matches what will actually be tried.
        var pw = defaultSshPassword() || 'falcon';
        var bodyHtml = '<div class="mb-2">SSH password for <code>fpp@' + addr + '</code>:</div>' +
            '<input type="password" id="rb-sshpw-input" class="form-control form-control-sm" value="' +
            pw.replace(/"/g, '&quot;') + '" autocomplete="off">' +
            '<small class="text-muted">Pre-filled with the configured default (or FPP\'s factory default,' +
            ' <code>falcon</code>, if none is set) - change it here if this remote uses a different one.</small>';
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

    // Reflects the row checkboxes' actual state onto the "Select All"
    // header checkbox - fully checked only when every row is, fully
    // unchecked only when none are, indeterminate (the usual dashed-box
    // look) for anything in between. Called after the table is (re)built
    // and after every individual row change, so the header always matches
    // reality instead of only ever reflecting whatever Select All itself
    // last set.
    function syncSelectAllCheckbox() {
        var selectAll = document.getElementById('rb-remote-selectall');
        if (!selectAll) return;
        var rowChecks = document.getElementsByClassName('rb-remote-check');
        var total = rowChecks.length;
        var checkedCount = 0;
        Array.prototype.forEach.call(rowChecks, function (chk) { if (chk.checked) checkedCount++; });
        selectAll.checked = total > 0 && checkedCount === total;
        selectAll.indeterminate = checkedCount > 0 && checkedCount < total;
    }

    function renderRemotes() {
        var el = document.getElementById('rb-remoteList');
        // overflow-x-auto: this table has 6 columns (checkbox, hostname,
        // address, source, a Push SSH Key button, a Remove button) and
        // forced the whole page to scroll sideways on a phone screen -
        // reported in the wild, same underlying bug as the Backup Status
        // table (see status.php's own comment on this - FPP's actual
        // shipped Bootstrap build doesn't define .table-responsive at all,
        // confirmed against FPP core source, so overflow-x-auto is used
        // instead: a class FPP's build does ship, giving the table its own
        // contained horizontal scrollbar rather than the page scrolling.
        el.className = 'mt-2 overflow-x-auto';
        if (!state.remotes.length) { el.innerHTML = '<em>No remotes found yet. Rescan, or add one manually below.</em>'; return; }
        var html = '<table class="table table-sm"><tr>' +
            '<th style="padding-right:1.5em;"><label class="d-flex align-items-center gap-1 mb-0" style="font-weight:normal; white-space:nowrap; cursor:pointer;" ' +
            'title="Select/deselect every remote below"><input type="checkbox" id="rb-remote-selectall"> All</label></th>' +
            '<th>Hostname</th><th>Address</th><th>Source</th><th></th><th></th></tr>';
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
        var rowChecks = document.getElementsByClassName('rb-remote-check');
        Array.prototype.forEach.call(rowChecks, function (chk) {
            chk.addEventListener('change', function () {
                var id = chk.getAttribute('data-id');
                var addr = chk.getAttribute('data-addr');
                var r = state.remotes.filter(function (x) { return x.id === id; })[0];
                if (r) r.selected = chk.checked;
                if (!r || !isHostRemote(r)) {
                    if (chk.checked) {
                        pushKeyFor(id, addr, null, false);
                    } else {
                        setKeyStatus(id, '', 'text-muted');
                    }
                }
                syncSelectAllCheckbox();
            });
        });

        // "Select All" header checkbox - a toggle in both directions (also
        // how you back a select-all click out: click it again to deselect
        // everyone), not a one-way action. Setting .checked on each row
        // programmatically here doesn't fire their own 'change' listener
        // above (browsers never fire 'change' for a script-driven
        // assignment), so the same selected/key-push/key-status handling
        // that listener does per row is repeated here explicitly - a naive
        // "just check every box" implementation would select every remote
        // without ever pushing their SSH keys, silently leaving the first
        // real backup after Select All to fail with "Permission denied
        // (publickey)" for all of them.
        var selectAll = document.getElementById('rb-remote-selectall');
        selectAll.addEventListener('change', function () {
            var checkedNow = selectAll.checked;
            Array.prototype.forEach.call(rowChecks, function (chk) {
                if (chk.checked === checkedNow) return;
                chk.checked = checkedNow;
                var id = chk.getAttribute('data-id');
                var addr = chk.getAttribute('data-addr');
                var r = state.remotes.filter(function (x) { return x.id === id; })[0];
                if (r) r.selected = checkedNow;
                if (!r || !isHostRemote(r)) {
                    if (checkedNow) {
                        pushKeyFor(id, addr, null, false);
                    } else {
                        setKeyStatus(id, '', 'text-muted');
                    }
                }
            });
        });
        syncSelectAllCheckbox();

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
            document.getElementById('rb-verifyAfterRun').checked = !!state.settings.verifyAfterRun;
            document.getElementById('rb-enableRestoreBindMount').checked = !!state.settings.enableRestoreBindMount;
            renderBindMountStatus();
            var playPolicy = state.settings.remotePlayingPolicy === 'skip' ? 'skip' : 'stop';
            document.getElementById('rb-playPolicy-' + playPolicy).checked = true;
            document.getElementById('rb-emailNotifyEnabled').checked = !!state.settings.emailNotifyEnabled;
            var emailScope = state.settings.emailNotifyScope === 'all' ? 'all' : 'scheduled';
            document.getElementById('rb-emailScope-' + emailScope).checked = true;
            var emailOutcomeIds = ['completed', 'failed', 'skipped', 'failed_or_skipped', 'all'];
            var emailOutcome = emailOutcomeIds.indexOf(state.settings.emailNotifyOutcome) !== -1 ? state.settings.emailNotifyOutcome : 'failed_or_skipped';
            document.getElementById('rb-emailOutcome-' + emailOutcome).checked = true;
            state.fppEmailToEmail = res.fppEmailToEmail || '';
            renderEmailFppStatus();
            document.getElementById('rb-maxConcurrent').value = state.settings.maxConcurrent || 2;
            document.getElementById('rb-logRetentionCount').value = state.settings.logRetentionCount || 15;
            document.getElementById('rb-sshUser').value = state.settings.sshUser || 'fpp';
            document.getElementById('rb-sshPort').value = state.settings.sshPort || 22;
            document.getElementById('rb-sshPassword').value = state.settings.sshPassword || '';
            document.getElementById('rb-excludes').value = (state.settings.excludes || []).join('\n');
            document.getElementById('rb-onboardingTourEnabled').checked = state.settings.onboardingTourEnabled !== false;
            state.remotes = state.settings.remotes || [];
            renderRemotes();
            renderStorage();
            renderScheduleMasterSelect();
            // Auto-show the walkthrough exactly once, only for an install
            // that hasn't seen/dismissed it yet and hasn't had it turned
            // off. Checking the box below always starts it regardless of
            // both - that's a deliberate click, not this automatic,
            // unsolicited popup.
            //
            // !res.settingsFileExisted also triggers it, independent of
            // onboardingSeen: that flag's in-memory default is true
            // (deliberately, to protect an upgrade - see rb_default_
            // settings()' comment), so a genuinely fresh install where
            // fpp_install.sh's seed never actually got written would
            // otherwise silently inherit that "already seen" default and
            // never show the tour at all. A missing settings.json is a
            // stronger, install-script-independent signal that this
            // install has never been configured.
            var neverSeeded = !res.settingsFileExisted;
            if ((neverSeeded || !state.settings.onboardingSeen) && state.settings.onboardingTourEnabled !== false) {
                rbTourStart();
            }
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

    // Live-updates the "currently active on ..." line the instant the box is
    // toggled - it still reads real mount/destination state from the last
    // status poll (not just this checkbox), so unchecking shows "not
    // currently active" right away, and checking it shows the true state
    // (which only actually changes once this is saved).
    document.getElementById('rb-enableRestoreBindMount').addEventListener('change', renderBindMountStatus);
    document.getElementById('rb-emailNotifyEnabled').addEventListener('change', renderEmailFppStatus);

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
            verifyAfterRun: document.getElementById('rb-verifyAfterRun').checked,
            enableRestoreBindMount: document.getElementById('rb-enableRestoreBindMount').checked,
            onboardingTourEnabled: document.getElementById('rb-onboardingTourEnabled').checked,
            purgeSdCardBackups: rbPendingSdCardPurge === true,
            remotePlayingPolicy: document.getElementById('rb-playPolicy-skip').checked ? 'skip' : 'stop',
            emailNotifyEnabled: document.getElementById('rb-emailNotifyEnabled').checked,
            emailNotifyScope: document.getElementById('rb-emailScope-all').checked ? 'all' : 'scheduled',
            emailNotifyOutcome: (document.querySelector('input[name="rb-emailOutcome-choice"]:checked') || {}).value || 'failed_or_skipped',
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
                // The purge (if any) already ran server-side as part of
                // this same saveSettings call - reset the staged choice
                // either way now that it's been acted on (or deliberately
                // not acted on), so a future transition asks fresh.
                if (typeof res.sdCardBackupsPurged === 'number' && res.sdCardBackupsPurged > 0) {
                    $.jGrowl('Removed ' + res.sdCardBackupsPurged + ' old backup folder(s) from the SD Card / System Storage.', { life: 6000, themeState: 'info' });
                }
                rbPendingSdCardPurge = null;
                renderSdCardPurgeNote();
                // Don't wait for the next 15s poll to reflect a just-saved
                // destinationMount/enableRestoreBindMount change - the
                // server already reconciled the bind mount as part of
                // saveSettings itself (see ajax.php), so this fetch picks
                // up the real result right away.
                api('status').then(function (sres) { state.lastStatus = sres; renderBindMountStatus(); });
            } else {
                msg.textContent = 'Error: ' + (res.error || 'unknown');
                msg.className = 'ms-2 text-danger';
                $.jGrowl('Failed to save Remote Backup settings: ' + (res.error || 'unknown'), { life: 6000, themeState: 'danger' });
            }
            setTimeout(function () { msg.textContent = ''; }, 4000);
        });
    });

    // --- First-run Config page walkthrough --------------------------------
    // Steps top to bottom through the page's own fieldsets, highlighting
    // each with a spotlight box + arrow and a short plain-language blurb.
    // Deliberately only targets elements that exist in the static page
    // markup - the storage/remote lists below are filled in later by
    // probeStorage/probeRemotes (see loadAllAfterHostInfo above), so
    // those three steps explicitly say so and point at the section as a
    // whole rather than pretending to show a specific drive/remote that
    // may not have been discovered yet, or may not exist at all on a
    // brand-new install.
    var RB_TOUR_STEPS = [
        {
            selector: '#rb-fieldset-hostmode',
            title: 'Backup Host Mode',
            text: 'Check this box on the ONE system that should pull backups from your others. ' +
                'Leave it unchecked on every other system - only one Host is supported at a time.'
        },
        {
            selector: '#rb-fieldset-storage',
            title: 'Backup Destination Storage',
            text: 'This is where backups get written - NVMe/SSD, USB, or the SD card as a fallback. ' +
                'The list of detected drives below fills in a moment after the page loads, so this tour ' +
                'can\'t show you what\'s there yet - come back and pick your destination here once scanning finishes.'
        },
        {
            selector: '#rb-fieldset-clone',
            title: 'Clone Backups to a Second Drive',
            text: 'Optional. Also fills in after a scan, same as Destination Storage above - review it ' +
                'later if you want a second, redundant copy of your backups on another drive. Manual only; ' +
                'nothing here runs on its own.'
        },
        {
            selector: '#rb-fieldset-remotes',
            title: 'Remote Systems to Back Up',
            text: 'The systems this Host backs up. Discovered remotes fill in above after a scan, same as ' +
                'the storage sections - review and select which ones to back up once that finishes. You can ' +
                'also add one manually right here any time, scan or no scan.'
        },
        {
            selector: '#rb-fieldset-options',
            title: 'Backup Options',
            text: 'Everything that controls how a backup actually runs - what happens if a remote is mid-show, ' +
                'how many run at once, log retention, SSH credentials, and exclude patterns. Each field has its ' +
                'own short description right below it.'
        },
        {
            selector: '#rb-fieldset-email',
            title: 'Email Settings',
            text: 'Optional. Sends a status email after backup runs, using FPP\'s own outbound email ' +
                '(FPP Setting > Email) - configure that separately if you want this to actually deliver ' +
                'anywhere.'
        },
        {
            selector: '#rb-fieldset-schedule',
            title: 'Show Schedule Conflict Check',
            text: 'Optional. Checks a designated show master\'s schedule so you can pick a backup time that ' +
                'won\'t land during a live show.'
        },
        {
            selector: '#rb-save',
            title: 'Save Settings',
            text: 'Nothing above takes effect until you click here - including which storage/remotes are ' +
                'actually used. That\'s it - you\'re done!'
        }
    ];
    var rbTourIndex = -1;
    var rbTourReposition = null;

    function rbTourBuildDom() {
        if (document.getElementById('rb-tour-popup')) return;
        var hl = document.createElement('div');
        hl.id = 'rb-tour-highlight';
        var arrow = document.createElement('div');
        arrow.id = 'rb-tour-arrow';
        var popup = document.createElement('div');
        popup.id = 'rb-tour-popup';
        popup.className = 'card shadow-lg border-primary';
        popup.innerHTML =
            '<div class="card-body">' +
            '<div class="small text-muted mb-1" id="rb-tour-step-of"></div>' +
            '<div class="fw-bold mb-1" id="rb-tour-title"></div>' +
            '<div class="mb-2" id="rb-tour-text"></div>' +
            '<div class="d-flex justify-content-between">' +
            '<button type="button" class="btn btn-sm btn-outline-secondary" id="rb-tour-back">Back</button>' +
            '<button type="button" class="btn btn-sm btn-link text-muted" id="rb-tour-skip">Skip Tour</button>' +
            '<button type="button" class="btn btn-sm btn-primary" id="rb-tour-next">Next</button>' +
            '</div></div>';
        document.body.appendChild(hl);
        document.body.appendChild(arrow);
        document.body.appendChild(popup);
        document.getElementById('rb-tour-back').addEventListener('click', function () { rbTourGo(rbTourIndex - 1); });
        document.getElementById('rb-tour-next').addEventListener('click', function () { rbTourGo(rbTourIndex + 1); });
        document.getElementById('rb-tour-skip').addEventListener('click', rbTourEnd);
    }

    function rbTourStart() {
        rbTourBuildDom();
        rbTourGo(0);
    }

    function rbTourGo(index) {
        if (index < 0) return;
        if (index >= RB_TOUR_STEPS.length) { rbTourEnd(); return; }
        rbTourIndex = index;
        var step = RB_TOUR_STEPS[index];
        var target = document.querySelector(step.selector);
        if (!target) { rbTourGo(index + 1); return; } // shouldn't happen for these static targets, but never get stuck
        document.getElementById('rb-tour-step-of').textContent = 'Step ' + (index + 1) + ' of ' + RB_TOUR_STEPS.length;
        document.getElementById('rb-tour-title').textContent = step.title;
        document.getElementById('rb-tour-text').textContent = step.text;
        document.getElementById('rb-tour-back').disabled = index === 0;
        document.getElementById('rb-tour-next').textContent = (index === RB_TOUR_STEPS.length - 1) ? 'Finish' : 'Next';

        // Deliberately no "click the highlighted setting to advance"
        // shortcut (an earlier version of this tour had one) - the
        // highlighted fieldset is often several checkboxes/inputs/radios
        // at once (e.g. Backup Options), and a listener on the whole
        // container fired on every click inside it, advancing the tour
        // the instant you tried to check a box or type into a field. Next/
        // Back/Skip Tour are the only way to navigate now, so making
        // several changes within one step before moving on actually works.

        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        clearTimeout(rbTourReposition);
        rbTourReposition = setTimeout(rbTourPosition, 260);
    }

    function rbTourPosition() {
        var step = RB_TOUR_STEPS[rbTourIndex];
        var target = step && document.querySelector(step.selector);
        if (!target) return;
        var rect = target.getBoundingClientRect();
        var pad = 6;
        var hl = document.getElementById('rb-tour-highlight');
        hl.style.top = (rect.top - pad) + 'px';
        hl.style.left = (rect.left - pad) + 'px';
        hl.style.width = (rect.width + pad * 2) + 'px';
        hl.style.height = (rect.height + pad * 2) + 'px';

        var popup = document.getElementById('rb-tour-popup');
        var arrow = document.getElementById('rb-tour-arrow');
        var popupW = popup.offsetWidth || 320;
        var spaceBelow = window.innerHeight - rect.bottom;
        var below = spaceBelow >= 170 || spaceBelow >= rect.top;
        var left = Math.max(8, Math.min(rect.left, window.innerWidth - popupW - 8));
        popup.style.left = left + 'px';
        if (below) {
            popup.style.top = (rect.bottom + pad + 12) + 'px';
            popup.style.bottom = '';
        } else {
            popup.style.bottom = (window.innerHeight - rect.top + pad + 12) + 'px';
            popup.style.top = '';
        }
        var arrowLeft = Math.max(left + 14, Math.min(rect.left + rect.width / 2 - 9, left + popupW - 26));
        arrow.style.left = arrowLeft + 'px';
        arrow.className = below ? 'rb-tour-arrow-above' : 'rb-tour-arrow-below';
        if (below) { arrow.style.top = (rect.bottom + pad) + 'px'; arrow.style.bottom = ''; }
        else { arrow.style.bottom = (window.innerHeight - rect.top + pad) + 'px'; arrow.style.top = ''; }
    }

    function rbTourEnd() {
        rbTourIndex = -1;
        ['rb-tour-highlight', 'rb-tour-arrow', 'rb-tour-popup'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.remove();
        });
        // state.settings can still be null here if the checkbox was
        // checked before the initial loadSettings call resolved - nothing
        // to mark yet in that case, and the next real load will fill it in
        // normally. Otherwise always persist, unconditionally - not
        // guarded behind "only if not already true": state.settings.
        // onboardingSeen can already read true from the in-memory default
        // even when this tour run was the neverSeeded auto-trigger (see
        // loadAllAfterHostInfo), so a guarded call here would skip writing
        // and leave settings.json still missing/unseeded, exactly the gap
        // this is meant to close. markOnboardingSeen itself is cheap and
        // idempotent either way.
        if (state.settings) {
            state.settings.onboardingSeen = true;
            api('markOnboardingSeen', { body: {} });
        }
    }

    window.addEventListener('resize', function () { if (rbTourIndex >= 0) rbTourPosition(); });
    window.addEventListener('scroll', function () { if (rbTourIndex >= 0) rbTourPosition(); }, true);

    // Checking the box also starts the tour immediately, every time it
    // transitions to checked, regardless of onboardingSeen (that flag only
    // gates the automatic first-run popup in loadAllAfterHostInfo above,
    // not this deliberate click). Setting .checked programmatically (the
    // loadAllAfterHostInfo init line) never fires 'change', so this only
    // ever runs from a real user click, never from the page just loading
    // with the box already checked.
    document.getElementById('rb-onboardingTourEnabled').addEventListener('change', function () {
        if (this.checked) rbTourStart();
    });

    // Explicit replay action, independent of the checkbox's checked state -
    // without this, replaying the tour while the box is already checked
    // (the common case, since it defaults to checked) needs an uncheck
    // click that does nothing followed by a re-check click that does,
    // since a checkbox only fires 'change' on an actual state transition.
    document.getElementById('rb-onboardingReplay').addEventListener('click', function (e) {
        e.preventDefault();
        rbTourStart();
    });

    // Same fpp-help-popover wiring status.php uses for its own "?" icons -
    // Bootstrap popover sourced from the matching hidden #rb-help-* div.
    // Scoped to #rb-config so this never touches an icon some other
    // plugin/page adds with the same class.
    document.querySelectorAll('#rb-config .fpp-help-popover').forEach(function (icon) {
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

    // Floating Save bar - a pure display preference (doesn't change what
    // Save Settings actually does), so it's kept in the browser's own
    // localStorage rather than settings.json: no reason to make every
    // future page load wait on a save/reload round-trip just to toggle
    // how the button is positioned, and it's private per browser anyway.
    // Defaults on; the checkbox is how you back this out if you'd rather
    // have the button back in its normal spot at the bottom of the page.
    (function () {
        var STORAGE_KEY = 'rb-floatingSaveEnabled';
        var bar = document.getElementById('rb-save-bar');
        var toggle = document.getElementById('rb-floatingSaveToggle');
        var pageEl = document.getElementById('rb-config');

        function readPref() {
            try { return localStorage.getItem(STORAGE_KEY) !== '0'; }
            catch (e) { return true; }
        }
        function writePref(enabled) {
            try { localStorage.setItem(STORAGE_KEY, enabled ? '1' : '0'); }
            catch (e) { /* private browsing / storage blocked - just won't persist across loads */ }
        }
        function apply(enabled) {
            bar.classList.toggle('rb-save-floating', enabled);
            bar.classList.toggle('bg-body', enabled);
            bar.classList.toggle('border-top', enabled);
            bar.classList.toggle('shadow-sm', enabled);
            pageEl.classList.toggle('rb-save-floating-active', enabled);
            toggle.checked = enabled;
        }

        apply(readPref());
        toggle.addEventListener('change', function () {
            writePref(toggle.checked);
            apply(toggle.checked);
        });
    })();

    loadAll();
    rbPollDestination();
})();
</script>
