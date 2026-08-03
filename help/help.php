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
        </div>
    </fieldset>
</div>
