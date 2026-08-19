<div class="mt-2">
    <fieldset class="border rounded p-2">
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
                <li><b>Authenticate.</b> Each remote needs to accept SSH connections from the Host
                    for the fpp user. Use the "Push SSH Key" button next to a remote to install the
                    Host's dedicated backup key (generated automatically on plugin install), or copy
                    <code>~fpp/.ssh/id_rsa_remotebackup.pub</code> to the remote's
                    <code>~fpp/.ssh/authorized_keys</code> yourself.</li>
                <li><b>Dry run first.</b> Use <i>Remote Backup - Status</i> &rarr; "Dry Run" to see
                    the estimated transfer size for all selected remotes compared against free space
                    on the Host's destination storage, with no files copied.</li>
                <li><b>Start Backup.</b> Runs <code>rsync</code> pulls of each remote's
                    <code>/home/fpp/media</code> from the Host, up to 2 remotes at a time by default;
                    as each finishes, the next queued remote starts automatically.</li>
            </ol>
        </div>
    </fieldset>

    <fieldset class="border rounded p-2 mt-2">
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

    <fieldset class="border rounded p-2 mt-2">
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

    <fieldset class="border rounded p-2 mt-2">
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

    <fieldset class="border rounded p-2 mt-2">
        <legend>Restoring a Backup</legend>
        <div class="p-2">
            This plugin only ever pulls backups down - it has no restore button of its own, by
            design. Recovery always goes through FPP's own built-in <b>File Copy Backup/Restore</b>
            page (under Content Setup), which already knows how to restore
            sequences/media/playlists/effects safely. There are two ways to get to it, depending on
            where the destination drive physically is at the time:
            <ol>
                <li><b>Using the Host, over the network.</b> Leave the destination drive right where
                    it is, still attached to the Host. On whichever system you're restoring to, open
                    its own <i>File Copy Backup/Restore</i> page, point the "Remote Storage" source at
                    the Host, and browse into the remote's own
                    <code>&lt;Hostname&gt;-&lt;YYYYMMDD&gt;</code> folder (or
                    <code>&lt;Hostname&gt;/&lt;YYYYMMDD&gt;</code> if Snapshot mode was enabled) to
                    restore from. This is the easiest option whenever the system you're restoring to
                    still has working network access back to the Host.</li>
                <li><b>Using the drive directly in the device's own USB port.</b> Useful when the
                    system you're restoring has no network access yet (e.g. a from-scratch rebuild
                    after a dead SD card) or you'd just rather not depend on the network for it.
                    <ol type="a">
                        <li>On the Config page, <b>Unmount</b> the destination drive from the Host
                            first - never unplug it while still mounted.</li>
                        <li>Physically move the drive to the system you're restoring, and plug it into
                            one of <i>that system's own</i> USB ports.</li>
                        <li>Open <i>that system's own</i> File Copy Backup/Restore page. Because this
                            plugin always formats destination drives with a real GPT partition table
                            (not a filesystem directly on the raw disk), the drive is recognized by
                            FPP's own device picker on any FPP system, not just this plugin - it'll
                            show up there the same way it would in this plugin's own Config page.</li>
                        <li>Browse into that remote's own <code>&lt;Hostname&gt;-&lt;YYYYMMDD&gt;</code>
                            folder on the drive - the same layout as restoring over the network, just
                            browsed locally instead. The drive normally holds every selected remote's
                            backups side by side; ignore the others and pick the one that's yours.</li>
                        <li>When you're done, move the drive back to the Host, Mount it again on the
                            Config page, and re-select it as the destination if needed before the next
                            backup run - only one system can have it plugged in at a time.</li>
                    </ol>
                </li>
            </ol>
            A few things that apply either way:
            <ul>
                <li>You don't have to restore to the same physical remote a backup came from - either
                    method works just as well for rebuilding/cloning onto a different system, as long
                    as you pick that remote's own folder on the drive.</li>
                <li>The <code>system-logs.tar.gz</code>/<code>system-config.tar.gz</code> archives
                    inside a backup (if "Include system config" is enabled) are deliberately packaged
                    as <code>.tar.gz</code> files rather than plain folders, specifically so File Copy
                    Restore's device browser doesn't mistake them for restorable show-content backups
                    of their own - they aren't part of its file-level restore either way. Extract those
                    yourself (<code>tar xzf</code>) over SSH/SCP if you need the original
                    <code>/etc/fpp</code>, network config, or relocated log directory back.</li>
                <li>Rolling mode (the default) only ever keeps each remote's most recent backup -
                    restoring an older point in time requires Snapshot mode to have been enabled
                    before that backup was made.</li>
            </ul>
        </div>
    </fieldset>

    <fieldset class="border rounded p-2 mt-2">
        <legend>Delete Handling</legend>
        <div class="p-2">
            "Delete files in the host backup that were removed on the remote" controls whether
            the backup is an exact mirror (<code>rsync --delete</code>) or simply accumulates files
            and never removes anything the remote no longer has, even after deletion there.
        </div>
    </fieldset>

    <fieldset class="border rounded p-2 mt-2">
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
        </div>
    </fieldset>
</div>
