<?php
// Backup Check - page shell. BackupCheck.js draws the page inside a shadow root and loads
// BackupCheck.css there, so the plugin's styles are fully separate from unRAID's.
$bcWeb = '/plugins/backupcheck';
$bcDir = '/usr/local/emhttp/plugins/backupcheck';
$bcVer = function ($f) use ($bcDir) { return @filemtime("$bcDir/$f") ?: time(); };   // cache-bust on update
?>
<div id="bc-root" data-api="<?= $bcWeb ?>/api.php" data-css="<?= $bcWeb ?>/BackupCheck.css?v=<?= $bcVer('BackupCheck.css') ?>"></div>
<script src="<?= $bcWeb ?>/BackupCheck.js?v=<?= $bcVer('BackupCheck.js') ?>"></script>
