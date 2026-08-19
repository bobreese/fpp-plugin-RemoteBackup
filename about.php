<div class="mt-2">
    <fieldset class="border rounded p-2">
        <legend>Remote Backup</legend>
        <div class="p-2">
            Pulls rsync backups of one or more MultiSync remotes onto local NVMe/SSD, USB, or SD
            storage.
        </div>
    </fieldset>

    <fieldset class="border rounded p-2 mt-2">
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
            Pick one, click "Refresh Log" (or check "Auto-tail" to poll it every few seconds while
            watching a run live), same page for all of them - no SSH or file browser needed.
        </div>
    </fieldset>

    <fieldset class="border rounded p-2 mt-2">
        <legend>Info</legend>
        <div class="p-2">
            <div id='credits'>
                <b>Remote Backup Developed By:</b><br />
                <br />
                Bo Reese (bobreese)<br />
                <br />
                <a href='https://github.com/bobreese/fpp-plugin-RemoteBackup' target='_blank'>Git Repository</a><br>
                <a href='https://github.com/bobreese/fpp-plugin-RemoteBackup/issues' target='_blank'>Bug Reporter</a><br>
                <br />
            </div>
        </div>
    </fieldset>
</div>
