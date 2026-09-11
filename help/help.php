<?php
// This page lives one directory down from the plugin root (help/help.php),
// unlike config.php/status.php which set $rbPlugin from their own __DIR__ -
// dirname() here walks back up to the plugin's own folder name so the
// Status Page link below builds the same plugin.php?plugin=...&page=...
// URL those pages already use to link to each other.
$rbPlugin = basename(dirname(__DIR__));
?>
<style>
    /* FPP's plugin page frame has a sticky top nav bar, so jumping straight
       to a #rb-help-* anchor otherwise lands with that section's <legend>
       tucked underneath it - scroll-margin-top leaves headroom above the
       target so the title actually ends up visible after the jump. */
    [id^="rb-help-"] { scroll-margin-top: 4rem; }
</style>
<div class="mt-2">
    <div class="mb-2">
        <a href="https://github.com/bobreese/fpp-plugin-RemoteBackup/blob/master/README.md"
           target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">
            Full documentation on GitHub (README) &#8599;
        </a>
        <a href="plugin.php?plugin=<?php echo urlencode($rbPlugin); ?>&page=status.php"
           class="btn btn-sm btn-outline-primary">
            Status Page
        </a>
    </div>

    <fieldset class="border rounded p-2">
        <legend>Categories</legend>
        <div class="p-2">
            <nav class="nav nav-pills flex-wrap gap-1">
                <a class="nav-link btn btn-sm btn-outline-secondary" href="#rb-help-how-it-works">How Remote Backup Works</a>
                <a class="nav-link btn btn-sm btn-outline-secondary" href="#rb-help-backup-layout">Backup Layout</a>
                <a class="nav-link btn btn-sm btn-outline-secondary" href="#rb-help-usb-drive">USB Backup Drive</a>
                <a class="nav-link btn btn-sm btn-outline-secondary" href="#rb-help-cloning">Cloning Backups to a Second Drive</a>
                <a class="nav-link btn btn-sm btn-outline-secondary" href="#rb-help-restoring">Restoring a Backup</a>
                <a class="nav-link btn btn-sm btn-outline-secondary" href="#rb-help-rescue">Rescuing a Failed Device</a>
                <a class="nav-link btn btn-sm btn-outline-secondary" href="#rb-help-delete-handling">Delete Handling</a>
                <a class="nav-link btn btn-sm btn-outline-secondary" href="#rb-help-scheduling">Scheduling</a>
                <a class="nav-link btn btn-sm btn-outline-secondary" href="#rb-help-email-updates">Email Status Updates</a>
                <a class="nav-link btn btn-sm btn-outline-secondary" href="#rb-help-log-files">Log Files</a>
                <a class="nav-link btn btn-sm btn-outline-secondary" href="#rb-help-about">About</a>
            </nav>
        </div>
    </fieldset>

    <fieldset class="border rounded p-2 mt-2" id="rb-help-how-it-works">
        <legend>How Remote Backup Works</legend>
        <div class="p-2">
            <ol>
                <li><b>Pick exactly one Host.</b> On the single FPP system that will store backups,
                    open <i>Remote Backup - Config</i>, enable <b>Host Mode</b>, and choose a
                    destination storage device. NVMe/SSD is preferred; a USB flash drive or free
                    space on the SD card can be used if no NVMe/SSD is present.</li>
                <li><b>Select remotes.</b> The Config page scans FPP's MultiSync system list for
                    candidate remotes. Check the ones you want backed up, or add one manually by
                    hostname/IP if it isn't discovered automatically.</li>
                <li><b>Click "Save Settings" at the bottom of the Config page.</b> Nothing above is
                    applied until you do - Host Mode, the destination device, selected remotes, and
                    every option on this page only take effect once saved.</li>
                <li><b>Authenticate (usually automatic).</b> Checking a remote's box immediately
                    pushes the Host's dedicated backup SSH key (generated automatically on plugin
                    install) to it in the background, using the stored/default password - no extra
                    step needed in the common case. If that silent push fails, the remote's row shows
                    "key push failed" - click the "Push SSH Key" button next to it to retry with a
                    password you enter yourself, or copy
                    <code>~fpp/.ssh/id_rsa_remotebackup.pub</code> to the remote's
                    <code>~fpp/.ssh/authorized_keys</code> manually.</li>
                <li><b>Dry run first.</b> Use <i>Remote Backup - Status</i> &rarr; "Dry Run" to see
                    the estimated transfer size for all selected remotes compared against free space
                    on the Host's destination storage, with no files copied.</li>
                <li><b>Start Backup.</b> Runs <code>rsync</code> pulls of each remote's
                    <code>/home/fpp/media</code> from the Host, up to 2 remotes at a time by default;
                    as each finishes, the next queued remote starts automatically.</li>
            </ol>
        </div>
    </fieldset>

    <fieldset class="border rounded p-2 mt-2" id="rb-help-backup-layout">
        <legend>Backup Layout</legend>
        <div class="p-2">
            Each remote gets its own folder on the destination storage named after its
            hostname and the date of the most recent backup, e.g. <code>Pi5-20260803</code>,
            so each remote's backups are kept separate and never mixed together. By default this
            folder is a single rolling "current" backup: on the next run it is simply renamed to
            the new date and updated in place. Enable <b>"Keep dated snapshot history"</b> in
            Config to instead keep every run as its own dated folder (space-efficient via
            <code>rsync --link-dest</code>, which hard-links unchanged files instead of copying
            them again).
        </div>
    </fieldset>

    <fieldset class="border rounded p-2 mt-2" id="rb-help-usb-drive">
        <legend>USB Backup Drive</legend>
        <div class="p-2">
            All of this is on the Config page, under <b>Backup Destination Storage</b>.
            <ol>
                <li><b>Rescan Storage Devices</b> after plugging the drive in. An unformatted or
                    previously-used-elsewhere drive shows up under "USB drive(s) detected but not
                    mounted."</li>
                <li><b>Format &amp; Mount as Backups</b> (skip if it's already formatted the way you
                    want). Choose a filesystem in the dialog:
                    <ul>
                        <li><b>exFAT</b> (selected by default) - readable on Windows, Mac, <i>and</i>
                            Linux. Pick this if you ever want to plug the drive into a laptop and
                            browse backups directly.</li>
                        <li><b>ext4</b> - Linux only. A Windows or Mac machine can't read the drive
                            at all without extra third-party software.</li>
                    </ul>
                    Type the device path shown (e.g. <code>/dev/sda</code>) into the confirm box to
                    enable the Format button - this erases everything already on the drive, so it's
                    a deliberate safety check, not a formality.</li>
                <li><b>Mount as Backups</b> instead of formatting, if the drive already has a
                    filesystem you want to keep. Either way the drive ends up mounted at
                    <code>/mnt/Backups</code> and added to <code>/etc/fstab</code> so it survives a
                    reboot.</li>
                <li><b>Activate it as the destination.</b> Once mounted, select its radio button in
                    the storage list, then click <b>"Save Settings"</b> at the bottom of the page -
                    nothing here takes effect, including which drive backups actually go to, until
                    you save.</li>
            </ol>
            <b>Unmount</b> before physically unplugging the drive (removes it from
            <code>/etc/fstab</code>, backups untouched) - also do this first if you want to use the
            drive from FPP's own File Copy Backup/Restore, since FPP's device pickers never list a
            drive this plugin still has mounted. <b>Re-format...</b> replaces
            "Format &amp; Mount as Backups" once a drive is already mounted, for starting over.
        </div>
    </fieldset>

    <fieldset class="border rounded p-2 mt-2" id="rb-help-cloning">
        <legend>Cloning Backups to a Second Drive</legend>
        <div class="p-2">
            Optional, entirely separate from the primary destination, and manual only - there's no
            Scheduler command for it.
            <ol>
                <li>Format/mount a second drive on the Config page, under <b>"Clone Backups to a
                    Second Drive"</b> - same Format/Mount flow as the primary destination, just fixed
                    to a different mountpoint (<code>/mnt/BackupsCopy</code>) so it's always a
                    distinct drive.</li>
                <li>Click <b>"Start Clone"</b> on the Status page, under the same-named section. Runs
                    <code>rsync --delete</code> from the whole primary destination to the secondary
                    drive in one pass - an exact mirror, so a backup you deleted from the primary is
                    removed from the clone too. Progress shows live the same way a backup run does.</li>
                <li><b>Stop</b> cancels an in-progress clone like Stop cancels a backup run - whatever
                    already copied stays; just start it again later to finish catching up.</li>
            </ol>
            A clone refuses to run at the same time as a backup run or a primary-drive format (it
            reads from the same destination those write to) - and the reverse is also true, a backup
            or a primary-drive format/unmount is blocked while a clone is running. It also refuses
            outright if the primary and secondary turn out to be the same drive, or one is nested
            inside the other, since mirroring a directory into itself could corrupt or wipe every
            backup on the primary.
        </div>
    </fieldset>

    <fieldset class="border rounded p-2 mt-2" id="rb-help-restoring">
        <legend>Restoring a Backup</legend>
        <div class="p-2">
            This plugin only pulls backups down - restoring uses FPP's own built-in
            <b>File Copy Backup/Restore</b> page (under Content Setup).
            <div class="callout callout-warning mb-2 mt-2">
                <b>Don't restore <code>/</code> or <code>System Volume Information</code></b> if you
                see them in the device browser - neither is a backup. Always browse into the specific
                remote's own <code>&lt;Hostname&gt;-&lt;YYYYMMDD&gt;</code> folder first.
            </div>
            <p class="mb-1"><b>Option A - Over the network</b> (drive stays on the Host)</p>
            <ol>
                <li>On the system you're restoring <i>to</i>, open its own File Copy Backup/Restore
                    page.</li>
                <li>Set the "Remote Storage" source to the Host.</li>
                <li>Browse into the remote's own <code>&lt;Hostname&gt;-&lt;YYYYMMDD&gt;</code>
                    folder (or <code>&lt;Hostname&gt;/&lt;YYYYMMDD&gt;</code> if Snapshot mode was
                    used) and restore from there.</li>
            </ol>
            <p class="mb-1"><b>Option B - Move the drive directly</b> (useful with no network access
                yet, e.g. a fresh SD card rebuild)</p>
            <ol>
                <li>On the Host's Config page, click <b>Unmount</b> first - never unplug the drive
                    while it's still mounted.</li>
                <li>Move the drive to the system you're restoring, and plug it into one of <i>that
                    system's</i> own USB ports.</li>
                <li>Open <i>that system's</i> own File Copy Backup/Restore page - the drive shows up
                    in its device picker automatically.</li>
                <li>Browse into that remote's own <code>&lt;Hostname&gt;-&lt;YYYYMMDD&gt;</code>
                    folder and restore.</li>
                <li>When done, move the drive back to the Host, <b>Mount</b> it again on the Config
                    page, and re-select it as the destination before the next scheduled backup.</li>
            </ol>
            <p class="mb-1"><b>A few notes:</b></p>
            <ul>
                <li>You can restore to a <i>different</i> system than the backup came from - just
                    pick that remote's own folder.</li>
                <li>Rolling mode only keeps each remote's most recent backup; restoring an older date
                    needs Snapshot mode to have been on at the time.</li>
                <li>The <code>system-config.tar.gz</code>/<code>system-logs.tar.gz</code> archives
                    (if "Include system config" was enabled) aren't part of File Copy Restore -
                    extract those yourself with <code>tar xzf</code> over SSH if you need them.</li>
            </ul>
            <div class="callout callout-info mb-0">
                <b>Rebuilding onto a fresh SD card?</b> Flash the <b>latest official release</b>
                (not a nightly build - those boot in Master mode). Update FPP itself first, then
                check the Plugin Manager separately, before restoring your backup. See
                <a href="https://github.com/bobreese/fpp-plugin-RemoteBackup/blob/master/docs/restoring-a-backup.md#after-a-fresh-sd-card-a-from-scratch-rebuild" target="_blank" rel="noopener">Restoring a Backup</a>
                for the full version.
            </div>
        </div>
    </fieldset>

    <fieldset class="border rounded p-2 mt-2" id="rb-help-rescue">
        <legend>Rescuing a Failed Device</legend>
        <div class="p-2">
            If a remote's <code>fppd</code> has failed and won't recover, the usual advice is to
            re-image its SD card - but FPP's own File Copy Backup only offers a config-only backup
            at that point, not your actual show content (sequences, media, effects, etc.). If the
            device still boots and its network/SSH still work, this plugin can pull a complete
            backup anyway - even from a system where you've never installed or used it before.
            <div class="callout callout-info mb-2 mt-2">
                This only works if the failed device's OS and SSH are still up. It can't rescue a
                device that won't boot at all, or one with a corrupted SD card at the filesystem
                level.
            </div>
            <ol>
                <li>Install this plugin on <b>any other</b> healthy FPP system on your network -
                    it doesn't have to be one you've already set up for backups.</li>
                <li>On its Config page, enable Host Mode and pick a destination for the backup.</li>
                <li>Under Remote Systems to Back Up, use "Manually add a remote" to add the failed
                    device by hostname/IP - it won't appear in the automatic scan once its own
                    <code>fppd</code> has stopped announcing itself.</li>
                <li>Check the box next to it - this pushes the SSH key automatically.</li>
                <li>Click "Save Settings."</li>
                <li>On the Status page, click <b>Dry Run</b> first to confirm it can actually
                    reach the device.</li>
                <li>If the dry run succeeds, click <b>Start Backup</b>.</li>
            </ol>
            You now have a complete backup of the failed device's show content - not just its
            config - ready to restore from once you've re-imaged or replaced its SD card.
        </div>
    </fieldset>

    <fieldset class="border rounded p-2 mt-2" id="rb-help-delete-handling">
        <legend>Delete Handling</legend>
        <div class="p-2">
            "Delete files in the host backup that were removed on the remote" controls whether
            the backup is an exact mirror (<code>rsync --delete</code>) or simply accumulates files
            and never removes anything the remote no longer has, even after deletion there.
        </div>
    </fieldset>

    <fieldset class="border rounded p-2 mt-2" id="rb-help-scheduling">
        <legend>Scheduling</legend>
        <div class="p-2">
            This plugin adds two Commands - <b>Run Remote Backup</b> and
            <b>Run Remote Backup Dry Run</b> - that can be triggered from FPP's own Scheduler,
            Playlists, or Events just like any other FPP command, so backups can run automatically
            on a recurring schedule.
            <p class="mb-0 mt-2">Before a scheduled run actually starts, every <i>selected</i> remote's
            own FPP API is checked. If <b>any one</b> of them is currently playing a sequence, the
            <b>entire</b> run is refused - not just that one remote's backup, all of them - and nothing
            gets backed up that cycle. This is deliberate: a backup pulls files directly off the same
            SD card/storage fppd is actively reading from during playback, and doing that while a show
            is running risks stutters or dropped frames. A remote that can't be reached at all is
            treated as unknown, not as playing, so one remote being offline doesn't block backing up
            every other one. If a scheduled run gets refused this way, it's logged with the reason in
            <code>data/logs/engine.log</code> (viewable from the Status page's Diagnostic Log) and in
            FPP's own command output for that Scheduler entry - worth checking there before assuming a
            scheduled backup silently failed for no reason.</p>
            <div class="callout callout-info mb-0 mt-2">
                <b>Picking a time?</b> Config's <b>"Show Schedule Conflict Check"</b> panel reads the
                configured show schedule straight off whichever system you designate as the show
                master, and shows a day-by-day table of what's already scheduled to play - so you can
                pick a Scheduler time for backups that's clear of the show before you save it, rather
                than finding out during a live show. It's a recommendation to verify, not a guarantee
                - test any suggested time once before relying on it. See
                <a href="https://github.com/bobreese/fpp-plugin-RemoteBackup/blob/master/docs/schedule-conflict-check.md" target="_blank" rel="noopener">Show Schedule Conflict Check</a>
                for the full version.
            </div>
        </div>
    </fieldset>

    <fieldset class="border rounded p-2 mt-2" id="rb-help-email-updates">
        <legend>Email Status Updates</legend>
        <div class="p-2">
            Config's <b>Email Settings</b> section (between Backup Options and Show Schedule
            Conflict Check) can send an email summary after backup runs - off by default. Two
            independent choices: <b>Send for</b> all runs or scheduled runs only (default - a
            manual Start Backup click is already being watched live on the Status page), and
            <b>Send when</b> at least one remote completed / failed / was skipped /
            failed-and-or-skipped (default) / or every included run regardless of outcome. The
            email lists every remote's own result; a run refused before any remote started
            (halted, no destination, low space, etc.) counts as "failed" and sends a short reason
            instead of a per-remote list. Dry Runs never send email, and a run refused only
            because another run was already in progress never does either.
            <div class="callout callout-info mb-0 mt-2">
                This reuses FPP's own outbound email (<b>FPP Settings &gt; Email</b>) rather than
                this plugin managing delivery itself - a real SMTP server needs to be entered
                there and "Configure Email" clicked at least once, or there's nowhere for a
                status email to go. Config shows inline whether that's already done.
            </div>
        </div>
    </fieldset>

    <fieldset class="border rounded p-2 mt-2" id="rb-help-log-files">
        <legend>Log Files</legend>
        <div class="p-2">
            This plugin keeps its own logs separate from FPP's - they live under
            <code>data/logs/</code> inside the plugin's own directory
            (<code>/home/fpp/media/plugins/fpp-plugin-RemoteBackup/data/logs/</code>), not in
            FPP's own log directory. A single rsync run can log a fresh line per file transferred
            and per progress update with no TTY to overwrite in place, so keeping that volume out
            of FPP's own File Manager &rarr; Logs view is deliberate - it would otherwise flood that
            list with entries that have nothing to do with FPP itself.
            <br><br>
            <b>View them from <i>Remote Backup - Status</i> instead</b>, under the "Diagnostic Log"
            section. The dropdown there covers everything this plugin writes:
            <ul>
                <li><code>ajax.log</code> - every Config/Status page action and the backend script
                    it ran</li>
                <li><code>engine.log</code> - the backup run engine's own log (starts, finishes,
                    refusals, errors)</li>
                <li>one entry per remote (<code>&lt;hostname&gt; rsync log</code>) - that remote's
                    most recent full <code>rsync</code> run log</li>
                <li><code>clone.log</code> - the most recent "Clone Backups to a Second Drive" run</li>
            </ul>
            Pick one, click "Refresh Log" (or check "Tail Follow" to poll it every few seconds while
            watching a run live), same page for all of them - no SSH or file browser needed.
            <br><br>
            <b>Download</b> saves the selected log to your browser as a plain text file;
            <b>Download All Logs</b> zips everything currently under <code>data/logs/</code>
            into one archive instead - handy for grabbing a full diagnostic snapshot in one go,
            e.g. when reporting an issue. Both show live status text while the file/archive is
            being prepared. This is separate from FPP's own File Manager download button, which
            can't reach these logs since they deliberately live outside FPP's own log directory
            (see above).
        </div>
    </fieldset>

    <fieldset class="border rounded p-2 mt-2" id="rb-help-about">
        <legend>About</legend>
        <div class="p-2">
            Pulls rsync backups of one or more MultiSync remotes onto local NVMe/SSD, USB, or SD
            storage.
        </div>
        <div class="p-2">
            <div id="rb-help-credits">
                <b>Remote Backup Developed By:</b><br />
                <br />
                Bo Reese (bobreese)<br />
                <br />
                <a href="https://github.com/bobreese/fpp-plugin-RemoteBackup" target="_blank">Git Repository</a><br>
                <a href="https://github.com/bobreese/fpp-plugin-RemoteBackup/issues" target="_blank">Bug Reporter</a><br>
                <br />
            </div>
        </div>
    </fieldset>
</div>
