<?php
/*
 * Refresh every <MD5> in backupcheck.plg from the file its <URL> points at.
 *
 *   php tools/update-md5.php            use the files in the working folder
 *   php tools/update-md5.php <git-ref>  use the bytes committed at <git-ref> (e.g. a tag)
 *
 * Release steps (Community Applications discipline):
 *   1. bump &version; in backupcheck.plg and add a ###<version> entry under <CHANGES>
 *   2. php tools/update-md5.php          (then commit everything)
 *   3. tag the commit with the exact version, e.g. 2026.11.18
 *   4. php tools/update-md5.php <tag>    should report "no changes" (proves the tag's bytes match)
 *   5. push main AND the tag (a plain push does not send tags)
 */
$repo = dirname(__DIR__);
$plg  = "$repo/backupcheck.plg";
$ref  = $argv[1] ?? null;
$xml  = file_get_contents($plg);

$changed = 0; $missing = 0;
$xml = preg_replace_callback('#(<URL>&src;/([^<]+)</URL><MD5>)([0-9a-f]{32})(</MD5>)#', function ($m) use ($repo, $ref, &$changed, &$missing) {
    $path = $m[2];
    if ($ref) {
        $cmd = 'git -c safe.directory=' . escapeshellarg($repo) . ' -C ' . escapeshellarg($repo) . ' show ' . escapeshellarg("$ref:$path") . ' 2>/dev/null';
        $bytes = shell_exec($cmd);
    } else {
        $bytes = @file_get_contents("$repo/$path");
    }
    if ($bytes === null || $bytes === false || $bytes === '') { $missing++; fwrite(STDERR, "missing: $path\n"); return $m[0]; }
    $md5 = md5($bytes);
    if ($md5 !== $m[3]) { $changed++; printf("%-28s %s\n", $path, $md5); }
    return $m[1] . $md5 . $m[4];
}, $xml);

if ($missing) { fwrite(STDERR, "$missing file(s) missing - plg not written\n"); exit(1); }
if ($changed) { file_put_contents($plg, $xml); echo "updated $changed MD5(s) in backupcheck.plg\n"; }
else echo "no changes: every MD5 matches\n";
