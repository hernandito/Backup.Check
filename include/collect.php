<?php
/*
 * Backup Check - collector.
 *
 * Builds one status document (status.json) describing every protected kind of data,
 * the jobs that copy it, and where the copies live. READ-ONLY with respect to backups:
 * it only reads schedules, run history, logs and file dates. The only things it writes
 * are its own state files (status.json, a run ledger, a probe cache) in its state dir.
 *
 * Usage (CLI):  php collect.php            write status.json
 *               php collect.php --stdout   print JSON, write nothing
 *               php collect.php --force    also refresh "slow" probes (USB / array paths)
 */

require_once __DIR__ . '/BCCron.php';

const BC_PLUGIN      = 'backupcheck';
const BC_CONF_DIR    = '/boot/config/plugins/backupcheck';
const BC_HISTORY_DAYS = 14;          // how far back to look for expected runs
const BC_SLOW_TTL    = 6 * 3600;     // USB/array paths are probed at most this often (keeps drives asleep)
const BC_LEDGER_KEEP = 400;          // runs kept per job in our own ledger

/* --------------------------------------------------------------------- paths */

function bc_read_cfg_value($file, $key)
{
    if (!is_readable($file)) return null;
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $ln) {
        if (preg_match('/^' . preg_quote($key, '/') . '="?(.*?)"?\s*$/', $ln, $m)) return $m[1];
    }
    return null;
}

/** /mnt/user/X -> /mnt/<pool>/X when X exists on a pool, so we never go through shfs or wake the array. */
function bc_resolve_user_path($p)
{
    if (strpos($p, '/mnt/user/') !== 0) return $p;
    $rest = rtrim(substr($p, strlen('/mnt/user/')), '/');
    foreach (glob('/mnt/*', GLOB_ONLYDIR) ?: [] as $m) {
        $b = basename($m);
        if (in_array($b, ['user', 'user0', 'disks', 'remotes', 'rootshare', 'addons'], true)) continue;
        if (preg_match('/^disk\d+$/', $b)) continue;
        if ($rest !== '' && is_dir("$m/$rest")) return "$m/$rest";
    }
    return rtrim($p, '/');
}

/** Docker's "Default appdata storage location", resolved to its pool path. */
function bc_appdata_base()
{
    $p = bc_read_cfg_value('/boot/config/docker.cfg', 'DOCKER_APP_CONFIG_PATH') ?: '/mnt/user/appdata/';
    return bc_resolve_user_path(rtrim($p, '/') . '/');
}

/** Where Backup Check keeps its own state. Never on the array; falls back to RAM rather than flash. */
function bc_state_dir($create = true)
{
    $base = bc_appdata_base();
    $dir  = rtrim($base, '/') . '/backup-check';
    if (strpos($dir, '/mnt/user/') === 0 || preg_match('#^/mnt/disk\d+/#', $dir)) {
        $dir = '/tmp/backup-check';      // array path: don't keep a disk spinning; RAM is the safe fallback
    }
    if ($create && !is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

/** Paths whose stat might wake a sleeping drive. */
function bc_is_slow_path($p)
{
    return strpos($p, '/mnt/disks/') === 0 || strpos($p, '/mnt/user/') === 0
        || strpos($p, '/mnt/remotes/') === 0 || preg_match('#^/mnt/disk\d+(/|$)#', $p);
}

/* ------------------------------------------------------------- probe cache */

$BC_PROBE = [];
$BC_PROBE_FILE = '';
$BC_FORCE = false;

function bc_probe($key, $path, $fn)
{
    global $BC_PROBE, $BC_FORCE;
    $slow = bc_is_slow_path($path);
    $now  = time();
    if ($slow && !$BC_FORCE && isset($BC_PROBE[$key]) && ($now - $BC_PROBE[$key]['at']) < BC_SLOW_TTL) {
        return $BC_PROBE[$key]['v'];
    }
    $v = $fn();
    if ($slow) $BC_PROBE[$key] = ['at' => $now, 'v' => $v];
    return $v;
}

function bc_mtime($path)
{
    return bc_probe('mtime:' . $path, $path, function () use ($path) {
        clearstatcache(true, $path);
        return @file_exists($path) ? (int)@filemtime($path) : null;
    });
}

function bc_is_mounted($mnt)
{
    $m = @file_get_contents('/proc/mounts') ?: '';
    foreach (explode("\n", $m) as $ln) {
        $f = preg_split('/\s+/', $ln);
        if (count($f) > 2 && $f[1] === $mnt && $f[2] !== 'tmpfs') return true;
    }
    return false;
}

/* ----------------------------------------------------- user scripts schedule */

function bc_preset_crons()
{
    $out = ['daily' => '59 1 * * *', 'weekly' => '30 1 * * 0', 'monthly' => '20 2 4 * *'];
    foreach (['/var/spool/cron/crontabs/root', '/etc/crontab'] as $f) {
        if (!is_readable($f)) continue;
        foreach (file($f, FILE_IGNORE_NEW_LINES) as $ln) {
            if (preg_match('#^\s*(\S+\s+\S+\s+\S+\s+\S+\s+\S+)\s+.*run-parts\s+/etc/cron\.(daily|weekly|monthly)#', $ln, $m)) {
                $out[$m[2]] = $m[1];
            }
        }
    }
    return $out;
}

function bc_userscript_schedule($script)
{
    static $sched = null, $presets = null;
    if ($sched === null) {
        $sched   = json_decode(@file_get_contents('/boot/config/plugins/user.scripts/schedule.json') ?: '{}', true) ?: [];
        $presets = bc_preset_crons();
    }
    $key = "/boot/config/plugins/user.scripts/scripts/$script/script";
    if (!isset($sched[$key])) return ['state' => 'unscheduled', 'cron' => null];
    $f = $sched[$key]['frequency'] ?? 'disabled';
    if ($f === 'custom')   return ['state' => 'scheduled', 'cron' => trim($sched[$key]['custom'] ?? '')];
    if (isset($presets[$f])) return ['state' => 'scheduled', 'cron' => $presets[$f]];
    if ($f === 'disabled') return ['state' => 'disabled', 'cron' => null];
    if (in_array($f, ['start', 'stop', 'boot'], true)) return ['state' => 'event', 'cron' => null, 'event' => $f];
    if ($f === 'hourly') return ['state' => 'scheduled', 'cron' => '47 * * * *'];
    return ['state' => 'unscheduled', 'cron' => null];
}

/* --------------------------------------------------------------- run sources */

/** Script Deck keeps User Scripts run history across reboots. */
function bc_scriptdeck_runs($script, $appdata)
{
    $runs = [];
    $f = rtrim($appdata, '/') . '/script.deck/history/' . rawurlencode($script) . '/runs.json';
    $j = json_decode(@file_get_contents($f) ?: '[]', true);
    if (!is_array($j)) return $runs;
    foreach ($j as $r) {
        if (!isset($r['start'])) continue;
        $runs[] = ['start' => (int)$r['start'], 'end' => isset($r['end']) ? (int)$r['end'] : null,
                   'aborted' => !empty($r['aborted']), 'src' => 'scriptdeck'];
    }
    return $runs;
}

/** The run User Scripts is holding in RAM since boot (script copy = start, log = end). */
function bc_tmpscripts_run($script)
{
    $d = "/tmp/user.scripts/tmpScripts/$script";
    if (!is_dir($d)) return null;
    $s = @filemtime("$d/script");
    $e = @filemtime("$d/log.txt");
    if (!$s) return null;
    return ['start' => (int)$s, 'end' => $e ? (int)$e : null, 'aborted' => false, 'src' => 'userscripts'];
}

/** True while a User Script is executing. */
function bc_userscript_running($script)
{
    $pidf = "/tmp/user.scripts/running/$script";
    return is_file($pidf);
}

/**
 * Runs recorded by the backup library itself (runs/<job>/history.jsonl): the job's own verdict,
 * with file counts and a link to the list of files the run touched. Dry runs are ignored.
 */
function bc_library_runs($state, $job)
{
    $runs = [];
    $id = preg_replace('/[^a-z0-9_-]/', '', strtolower($job));
    $lines = is_readable("$state/runs/$id/history.jsonl") ? (file("$state/runs/$id/history.jsonl", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
    // The latest-run status file too (covers runs made before the history file existed).
    if (is_readable("$state/reports/$id.json")) $lines[] = trim((string)file_get_contents("$state/reports/$id.json"));
    $seen = [];
    foreach ($lines as $ln) {
        $r = json_decode($ln, true);
        if (!is_array($r) || !empty($r['dryrun']) || empty($r['started']) || isset($seen[$r['started']])) continue;
        $seen[$r['started']] = true;
        // older status files had no run id: derive it from the start time, and look for its file list
        if (empty($r['run'])) {
            $r['run'] = date('Ymd-His', (int)$r['started']);
            $r['filelist'] = is_file("$state/runs/$id/{$r['run']}.files.gz");
        }
        $runs[] = [
            'start' => (int)$r['started'], 'end' => (int)($r['epoch'] ?? $r['started']), 'aborted' => false, 'src' => 'library',
            'status' => in_array($r['status'] ?? '', ['ok', 'warn', 'fail'], true) ? $r['status'] : 'warn',
            'detail' => $r['detail'] ?? null, 'files' => $r['files'] ?? null,
            'run' => preg_match('/^\d{8}-\d{6}$/', $r['run'] ?? '') ? $r['run'] : null, 'filelist' => !empty($r['filelist']),
        ];
    }
    return $runs;
}

/** The friendly name given to a User Script (its "name" file, as shown in User Scripts and Script Deck), as plain text. */
function bc_display_name($script)
{
    $raw = @file_get_contents("/boot/config/plugins/user.scripts/scripts/$script/name");
    if ($raw === false || trim($raw) === '') return null;
    $t = preg_replace('#<br\s*/?>#i', ' · ', $raw);
    $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = trim(preg_replace('/\s+/u', ' ', $t), " ·");
    return $t !== '' ? $t : null;
}

function bc_merge_runs(array $a, array $b)
{
    $all = array_merge($a, $b);
    usort($all, function ($x, $y) { return $x['start'] <=> $y['start']; });
    $out = [];
    foreach ($all as $r) {
        $n = count($out);
        if ($n && abs($out[$n - 1]['start'] - $r['start']) <= 180) {
            // same run seen by two sources: keep the most informative fields
            $p = &$out[$n - 1];
            if (empty($p['end']) && !empty($r['end'])) $p['end'] = $r['end'];
            if (!empty($r['aborted'])) $p['aborted'] = true;
            if (($r['status'] ?? null) && (empty($p['status']) || $p['status'] === 'running')) $p['status'] = $r['status'];
            if (empty($r['stampOnly'])) unset($p['stampOnly']);
            // the library's own record is the most accurate: its verdict and counts win
            if (($r['src'] ?? '') === 'library') {
                foreach (['status', 'detail', 'files', 'run', 'filelist', 'end'] as $k) { if (isset($r[$k])) $p[$k] = $r[$k]; }
                $p['src'] = 'library';
            }
            unset($p);
            continue;
        }
        $out[] = $r;
    }
    return $out;
}

/* ---------------------------------------------------- Appdata Backup plugin */

function bc_appdata_backup_job()
{
    $cfgFile = '/boot/config/plugins/appdata.backup/config.json';
    $cfg  = json_decode(@file_get_contents($cfgFile) ?: '{}', true) ?: [];
    $dest = rtrim($cfg['destination'] ?? '', '/');
    $cron = null;
    $cronLine = trim(@file_get_contents('/boot/config/plugins/appdata.backup/appdata_backup.cron') ?: '');
    foreach (explode("\n", $cronLine) as $ln) {
        if (preg_match('#^\s*([0-9*,/\-]+\s+[0-9*,/\-]+\s+[0-9*,/\-]+\s+[0-9*,/\-]+\s+[0-9*,/\-]+)\s+.*backup\.php#', $ln, $m)) { $cron = $m[1]; break; }
    }
    $installed = is_dir('/usr/local/emhttp/plugins/appdata.backup');

    // Each run leaves a folder ab_YYYYMMDD_HHMMSS (suffix -failed when it failed).
    $runs = [];
    $latestFolder = null;
    if ($dest !== '') {
        $names = bc_probe('ls:' . $dest, $dest, function () use ($dest) {
            $n = @scandir($dest);
            return $n === false ? null : array_values(array_filter($n, function ($x) { return strpos($x, 'ab_') === 0; }));
        });
        foreach ($names ?: [] as $n) {
            if (!preg_match('/^ab_(\d{8})_(\d{6})(-failed)?$/', $n, $m)) continue;
            $t = strtotime(substr($m[1], 0, 4) . '-' . substr($m[1], 4, 2) . '-' . substr($m[1], 6, 2) . ' '
                         . substr($m[2], 0, 2) . ':' . substr($m[2], 2, 2) . ':' . substr($m[2], 4, 2));
            if (!$t) continue;
            $runs[] = ['start' => $t, 'end' => null, 'aborted' => false, 'src' => 'abfolder',
                       'status' => !empty($m[3]) ? 'fail' : null, 'folder' => "$dest/$n/"];
        }
        usort($runs, function ($x, $y) { return $x['start'] <=> $y['start']; });
        if ($runs) $latestFolder = end($runs)['folder'];
    }

    // The latest run's log tells us whether it finished.
    $log = '/tmp/appdata.backup/ab.log';
    $live = ['state' => null, 'detail' => null, 'start' => null, 'end' => null];
    if (is_readable($log)) {
        $lines = file($log, FILE_IGNORE_NEW_LINES) ?: [];
        $first = null; $last = null; $done = false; $errors = 0;
        foreach ($lines as $ln) {
            if (preg_match('/^\[(\d{2})\.(\d{2})\.(\d{4}) (\d{2}:\d{2}:\d{2})\]/', $ln, $m)) {
                $t = strtotime("$m[3]-$m[2]-$m[1] $m[4]");
                if ($first === null) $first = $t;
                $last = $t;
            }
            if (strpos($ln, 'DONE!') !== false) $done = true;
            if (strpos($ln, '[❌]') !== false || stripos($ln, '][error]') !== false) $errors++;
        }
        $running = false;
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $c) {
            if (strpos((string)@file_get_contents($c), 'appdata.backup/scripts/backup.php') !== false) { $running = true; break; }
        }
        $live['start'] = $first; $live['end'] = $last;
        if ($running)        { $live['state'] = 'running'; $live['detail'] = 'Running now'; }
        elseif ($done)       { $live['state'] = $errors ? 'warn' : 'ok'; $live['detail'] = $errors ? "Finished with $errors error lines" : 'Finished (DONE)'; }
        elseif ($first)      {
            $tail = '';
            for ($i = count($lines) - 1; $i >= 0; $i--) { if (trim($lines[$i]) !== '') { $tail = $lines[$i]; break; } }
            $tail = trim(preg_replace('/^\[[^\]]*\](\[[^\]]*\])*\s*/u', '', $tail));
            $live['state']  = 'warn';
            $live['detail'] = 'Stopped before finishing, at ' . date('H:i', $last) . ($tail !== '' ? ': "' . mb_strimwidth($tail, 0, 60, '…') . '"' : '');
        }
    }
    // Attach the log result to the matching folder run.
    if ($live['start'] && $runs) {
        foreach ($runs as &$r) {
            if (abs($r['start'] - $live['start']) < 600) {
                $r['end'] = $live['end'];
                if ($r['status'] === null && $live['state']) $r['status'] = $live['state'];
                $r['detail'] = $live['detail'];
            }
        }
        unset($r);
    }

    if (!$installed && !$runs) $sched = ['state' => 'missing', 'cron' => null];
    elseif (($cfg['backupFrequency'] ?? '') === 'disabled' || !$cron) $sched = ['state' => $cron ? 'scheduled' : 'disabled', 'cron' => $cron];
    else $sched = ['state' => 'scheduled', 'cron' => $cron];

    return [
        'sched' => $sched, 'runs' => $runs, 'latestFolder' => $latestFolder, 'running' => $live['state'] === 'running',
        'meta'  => [
            'destination' => $dest, 'flashCopy' => $cfg['flashBackupCopy'] ?? null,
            'keepDays' => $cfg['deleteBackupsOlderThan'] ?? null, 'keepMin' => $cfg['keepMinBackups'] ?? null,
        ],
    ];
}

/* ------------------------------------------------------------- job analysis */

/** "Sun Nov 15", with the year added when it isn't this year. */
function bc_day($ts)
{
    return date('Y', $ts) === date('Y') ? date('D M j', $ts) : date('D M j, Y', $ts);
}

function bc_fmt_dur($s)
{
    if ($s === null || $s < 0) return null;
    if ($s < 60) return $s . 's';
    if ($s < 3600) { $m = intdiv($s, 60); return $m < 10 ? $m . 'm ' . ($s % 60) . 's' : $m . 'm'; }
    return intdiv($s, 3600) . 'h ' . intdiv($s % 3600, 60) . 'm';
}

/**
 * Turn a job's schedule + runs into status, day strip, last/next run.
 * Run statuses: ok | warn | fail (explicit), otherwise ok unless aborted.
 */
function bc_analyse_job($id, $def, $sched, $runs, $now, $days, $running, $report)
{
    $cron = $sched['cron'];
    // Runs: newest last. Status of each run.
    foreach ($runs as &$r) {
        if (empty($r['status'])) $r['status'] = !empty($r['aborted']) ? 'warn' : 'ok';
    }
    unset($r);
    $real    = array_values(array_filter($runs, function ($r) { return empty($r['stampOnly']); }));
    $lastRun = $runs ? end($runs) : null;
    $lastOk  = null;
    for ($i = count($runs) - 1; $i >= 0; $i--) { if (in_array($runs[$i]['status'], ['ok', 'warn'], true)) { $lastOk = $runs[$i]; break; } }
    $earliest = $runs ? $runs[0]['start'] : null;

    // A bk_report newer than the last run start overrides that run's status.
    if ($report && $lastRun && ($report['epoch'] ?? 0) >= $lastRun['start'] - 300) {
        $map = ['ok' => 'ok', 'warn' => 'warn', 'fail' => 'fail', 'skipped' => 'warn'];
        $lastRun['status'] = $map[$report['status']] ?? $lastRun['status'];
        $lastRun['detail'] = $report['detail'] ?? null;
        $runs[count($runs) - 1] = $lastRun;
    }

    // Expected fires over the window, and which ones a run covered.
    $from = $now - BC_HISTORY_DAYS * 86400;
    $expected = $cron ? BCCron::between($cron, $from, $now + 4 * 86400, 2000) : [];
    // A job only counts runs from the day it was set up ("since" in the catalog): earlier
    // fires belong to the scripts it replaced, so they are never shown as missed.
    if (!empty($def['since']) && ($since = strtotime($def['since']))) {
        $expected = array_values(array_filter($expected, function ($t) use ($since) { return $t >= $since; }));
    }
    $grace = 12 * 3600;
    $matched = [];
    foreach ($expected as $t) {
        if ($t > $now) continue;
        $hit = null;
        foreach ($runs as $r) {
            if ($r['start'] >= $t - 900 && $r['start'] <= $t + $grace) { $hit = $r; break; }
        }
        $matched[$t] = $hit;
    }

    // Status
    $pastExpected = array_keys($matched);
    $lastExpected = $pastExpected ? end($pastExpected) : null;
    $status = 'ok'; $statusText = 'Ran on schedule';
    if ($sched['state'] === 'missing')        { $status = 'off';  $statusText = 'Not installed'; }
    elseif ($sched['state'] === 'disabled')   { $status = 'off';  $statusText = 'Disabled'; }
    elseif ($running)                         { $status = 'running'; $statusText = 'Running now'; }
    elseif (!empty($def['paused']) && $sched['state'] !== 'scheduled') { $status = 'paused'; $statusText = (string)$def['paused']; }
    elseif (!empty($def['planned']) && !$runs && $sched['state'] !== 'scheduled') { $status = 'planned'; $statusText = 'Planned, not scheduled yet'; }
    elseif (!$runs && in_array($sched['state'], ['unscheduled', 'event'], true)) { $status = 'never'; $statusText = 'No schedule, never ran'; }
    elseif (!$runs)                           { $status = 'never'; $statusText = 'No run on record'; }
    elseif ($lastExpected && $matched[$lastExpected] === null && $now - $lastExpected > 900 && $lastExpected >= $earliest - 86400 * 400) {
        $status = 'miss'; $statusText = 'Missed ' . bc_day($lastExpected);
    }
    elseif ($lastRun && $lastRun['status'] === 'fail') { $status = 'fail'; $statusText = 'Last run failed'; }
    elseif ($lastRun && $lastRun['status'] === 'warn') { $status = 'warn'; $statusText = $lastRun['detail'] ?? 'Last run had an issue'; }
    elseif ($sched['state'] === 'unscheduled') { $status = 'warn'; $statusText = 'Not scheduled (manual runs only)'; }

    // Day strip: one cell per day in $days.
    $cells = [];
    foreach ($days as $d) {
        $dayStart = $d['ts']; $dayEnd = $dayStart + 86399;
        $exp = array_values(array_filter($expected, function ($t) use ($dayStart, $dayEnd) { return $t >= $dayStart && $t <= $dayEnd; }));
        $ran = array_values(array_filter($runs, function ($r) use ($dayStart, $dayEnd) { return $r['start'] >= $dayStart && $r['start'] <= $dayEnd; }));
        $k = '.';
        if ($ran) {
            $st = 'ok';
            foreach ($ran as $r) { if ($r['status'] === 'fail') $st = 'fail'; elseif ($r['status'] === 'warn' && $st !== 'fail') $st = 'warn'; }
            $k = ['ok' => 'o', 'warn' => 'w', 'fail' => 'f'][$st];
        }
        if ($exp) {
            $missed = false; $pending = false;
            foreach ($exp as $t) {
                if ($t > $now) { $pending = true; continue; }
                if (($matched[$t] ?? null) === null) {
                    if ($earliest === null || $t < $earliest - 86400) { if ($k === '.') $k = 'u'; }
                    else $missed = true;
                }
            }
            if ($missed && !$ran) $k = 'm';
            if ($pending && $k === '.') $k = $d['today'] ? 'd' : 's';
        }
        if ($sched['state'] === 'disabled' && $k === '.') $k = '.';
        $cells[] = $k;
    }

    $next = ($cron && in_array($sched['state'], ['scheduled'], true)) ? BCCron::next($cron, $now) : null;
    $hist = [];
    foreach (array_slice($runs, -30) as $r) {
        $dur = (!empty($r['end']) && $r['end'] >= $r['start']) ? $r['end'] - $r['start'] : null;
        $hist[] = ['start' => $r['start'], 'dur' => $dur, 'durText' => bc_fmt_dur($dur), 'status' => $r['status'],
                   'src' => $r['src'] ?? null, 'detail' => $r['detail'] ?? null,
                   'files' => $r['files'] ?? null, 'run' => $r['run'] ?? null, 'filelist' => !empty($r['filelist'])];
    }
    $missedList = [];
    foreach ($matched as $t => $hit) { if ($hit === null && $earliest && $t >= $earliest - 86400 && $now - $t > 900) $missedList[] = $t; }

    $schedText = $cron ? BCCron::describe($cron) : ([
        'disabled' => 'Disabled', 'unscheduled' => 'Not scheduled', 'missing' => 'Not installed',
        'event' => 'At array ' . ($sched['event'] ?? 'event'),
    ][$sched['state']] ?? 'Not scheduled');

    return [
        'id' => $id, 'name' => $def['name'], 'type' => $def['type'],
        'display' => (($def['type'] ?? '') === 'userscript' ? bc_display_name($def['script'] ?? $def['name']) : null) ?: $def['name'],
        'script' => $def['script'] ?? null, 'label' => $def['label'] ?? '',
        'status' => $status, 'statusText' => $statusText,
        'schedule' => $schedText, 'cron' => $cron, 'schedState' => $sched['state'],
        'lastRun' => $lastRun ? $lastRun['start'] : null,
        'lastEnd' => $lastRun['end'] ?? null,
        'lastStatus' => $lastRun['status'] ?? null,
        'lastDetail' => $lastRun['detail'] ?? null,
        'lastOk' => $lastOk ? ($lastOk['end'] ?: $lastOk['start']) : null,
        'lastOkStampOnly' => $lastOk ? !empty($lastOk['stampOnly']) : false,
        'lastDur' => ($lastRun && !empty($lastRun['end'])) ? bc_fmt_dur($lastRun['end'] - $lastRun['start']) : null,
        'next' => $next, 'interval' => $cron ? BCCron::interval($cron, $now) : null,
        'cells' => implode('', $cells), 'history' => $hist, 'missed' => $missedList,
        'runCount' => count($real),
        'notes' => $def['notes'] ?? [],
        'report' => $report,
    ];
}

/* ----------------------------------------------------------------- the run */

function bc_collect($force = false, $persist = true)
{
    global $BC_PROBE, $BC_PROBE_FILE, $BC_FORCE;
    $BC_FORCE = $force;
    $now   = time();
    $state = bc_state_dir($persist);
    $BC_PROBE_FILE = "$state/probe-cache.json";
    $BC_PROBE = json_decode(@file_get_contents($BC_PROBE_FILE) ?: '{}', true) ?: [];

    $catFile = BC_CONF_DIR . '/catalog.json';
    if (!is_readable($catFile)) $catFile = dirname(__DIR__) . '/catalog.default.json';
    $cat = json_decode(@file_get_contents($catFile) ?: '', true);
    if (!is_array($cat)) {
        return ['error' => 'Could not read the catalog (' . $catFile . '): ' . json_last_error_msg(), 'generated' => $now];
    }
    $appdata = bc_appdata_base();

    // Days shown in strips: 6 past days, today, 3 ahead.
    $today = strtotime('today', $now);
    $days = [];
    for ($i = -6; $i <= 3; $i++) {
        $ts = strtotime(($i >= 0 ? '+' : '') . $i . ' day', $today);
        $days[] = ['ts' => $ts, 'dow' => date('D', $ts), 'num' => date('j', $ts), 'iso' => date('Y-m-d', $ts), 'today' => $i === 0, 'future' => $i > 0];
    }

    // Our own ledger keeps runs longer than Script Deck does.
    $ledgerFile = "$state/ledger.json";
    $ledger = json_decode(@file_get_contents($ledgerFile) ?: '{}', true) ?: [];

    $jobs = []; $jobRuns = []; $abInfo = null;
    foreach ($cat['jobs'] as $id => $def) {
        $report = null;
        $rf = "$state/reports/" . preg_replace('/[^a-z0-9_-]/', '', strtolower($id)) . '.json';
        if (is_readable($rf)) $report = json_decode(@file_get_contents($rf), true) ?: null;

        if (($def['type'] ?? '') === 'appdata.backup') {
            $abInfo  = bc_appdata_backup_job();
            $runs    = $abInfo['runs'];
            $sched   = $abInfo['sched'];
            $running = $abInfo['running'];
        } else {
            $script  = $def['script'] ?? $def['name'];
            $sched   = bc_userscript_schedule($script);
            $runs    = bc_scriptdeck_runs($script, $appdata);
            $tmp     = bc_tmpscripts_run($script);
            if ($tmp) $runs = bc_merge_runs($runs, [$tmp]);
            $running = bc_userscript_running($script);
        }
        // Ledger merge (keeps history across Script Deck pruning and reboots), then the
        // backup library's own record of each run, which has the final say on its result.
        $runs = bc_merge_runs($ledger[$id] ?? [], $runs);
        $runs = bc_merge_runs($runs, bc_library_runs($state, $id));
        $ledger[$id] = array_slice(array_map(function ($r) {
            $keep = array_intersect_key($r, array_flip(['start', 'end', 'aborted', 'src', 'status', 'detail', 'files', 'run', 'filelist']));
            // "running" is a moment, not a result: never store it, so the finished result replaces it
            if (($keep['status'] ?? '') === 'running') unset($keep['status'], $keep['detail'], $keep['end']);
            return $keep;
        }, array_filter($runs, function ($r) { return empty($r['stampOnly']); })), -BC_LEDGER_KEEP);

        // Completion stamps: last-known evidence for jobs without run history.
        // A stamp older than the job itself ("since") was left by the script it replaced: ignored.
        $newestRun = $runs ? end($runs)['start'] : 0;
        $sinceTs = !empty($def['since']) ? (int)strtotime($def['since']) : 0;
        foreach ($def['stamps'] ?? [] as $sp) {
            $mt = bc_mtime($sp);
            if ($mt && $mt > $newestRun + 600 && $mt >= $sinceTs) {
                $runs[] = ['start' => $mt, 'end' => null, 'aborted' => false, 'src' => 'stamp', 'stampOnly' => true, 'detail' => 'From the completion stamp ' . basename($sp)];
                usort($runs, function ($x, $y) { return $x['start'] <=> $y['start']; });
            }
        }
        $jobRuns[$id] = $runs;
        $jobs[$id] = bc_analyse_job($id, $def, $sched, $runs, $now, $days, $running, $report);
        if (($def['type'] ?? '') === 'appdata.backup' && $abInfo) $jobs[$id]['plugin'] = $abInfo['meta'];
    }

    // Locations
    $locs = [];
    foreach ($cat['locations'] as $lid => $L) {
        $p = $L['path'];
        $mounted = empty($L['mount']) ? true : bc_is_mounted($p);
        $used = null; $size = null;
        if (($L['capacity'] ?? true) && $mounted) {
            $cap = bc_probe('df:' . $p, $p, function () use ($p) {
                $t = @disk_total_space($p); $f = @disk_free_space($p);
                return ($t && $t > 0) ? ['t' => $t, 'f' => $f] : null;
            });
            if ($cap) { $size = $cap['t']; $used = (int)round(100 * ($cap['t'] - $cap['f']) / $cap['t']); }
        }
        $locs[$lid] = ['id' => $lid, 'name' => $L['name'], 'kind' => $L['kind'] ?? '', 'path' => $p,
                       'note' => $L['note'] ?? '', 'mounted' => $mounted, 'usedPct' => $used, 'size' => $size];
    }

    // Data items
    $data = [];
    foreach ($cat['data'] as $D) {
        $copies = [];
        foreach ($D['copies'] as $i => $C) {
            $J = $jobs[$C['job']] ?? null;
            if (!$J) continue;
            $st = $J['status'];
            if (!empty($C['included']) && in_array($st, ['ok', 'warn'], true)) $st = 'inc';
            if ($st === 'running') $st = $J['lastOk'] ? 'ok' : 'never';
            $lastOk = $J['lastOk']; $cells = $J['cells']; $next = $J['next']; $interval = $J['interval'] ?: 86400; $sched = $J['schedule'];
            // A copy the job makes only on some days (e.g. its weekly set on Sundays).
            if (isset($C['onlyDow']) || isset($C['onlyDom'])) {
                $match = function ($ts) use ($C) {
                    if (isset($C['onlyDow']) && (int)date('w', $ts) !== (int)$C['onlyDow']) return false;
                    if (isset($C['onlyDom']) && (int)date('j', $ts) !== (int)$C['onlyDom']) return false;
                    return true;
                };
                $lastOk = null;
                foreach ($jobRuns[$C['job']] ?? [] as $r) {
                    $rs = $r['status'] ?? (!empty($r['aborted']) ? 'warn' : 'ok');
                    if (in_array($rs, ['ok', 'warn'], true) && $match($r['start'])) $lastOk = $r['end'] ?: $r['start'];
                }
                $cells = '';
                foreach ($days as $di => $d) $cells .= $match($d['ts']) ? substr($J['cells'], $di, 1) : '.';
                $interval = isset($C['onlyDom']) ? 31 * 86400 : 7 * 86400;
                $sched = isset($C['onlyDom']) ? 'Monthly · on the ' . $C['onlyDom'] . ($C['onlyDom'] == 1 ? 'st' : 'th') : 'Weekly · ' . ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][(int)$C['onlyDow']];
                if ($next) { $n = $next; for ($k = 0; $k < 40 && !$match($n); $k++) $n = BCCron::next($J['cron'], $n) ?: $n + 86400; $next = $n; }
                if ($st === 'ok' && !$lastOk) $st = 'never';
            }
            $path = $C['path'];
            if (!empty($C['latestFolder']) && $C['job'] === 'abp' && $abInfo && $abInfo['latestFolder']) $path = $abInfo['latestFolder'];
            $loc = $locs[$C['location']] ?? null;
            if ($loc && !$loc['mounted'] && in_array($st, ['ok', 'warn', 'inc'], true)) $st = 'warn';
            // Fresh = this location holds a copy recent enough for its schedule (a missed run doesn't erase an older good copy).
            $limit = max(2 * 86400, 2 * $interval) + 6 * 3600;
            $fresh = $lastOk && ($now - $lastOk) <= $limit && !in_array($st, ['off', 'planned', 'paused', 'never'], true);
            $copies[] = [
                'i' => $i, 'job' => $C['job'], 'jobName' => $J['name'], 'display' => $J['display'], 'label' => $J['label'],
                'reach' => $C['reach'] ?? '', 'tier' => $C['tier'] ?? 'daily',
                'location' => $C['location'], 'path' => $path, 'keeps' => $C['keeps'] ?? '',
                'included' => !empty($C['included']), 'status' => $st, 'jobStatus' => $J['status'], 'jobStatusText' => $J['statusText'],
                'lastOk' => $lastOk, 'lastOkStampOnly' => $J['lastOkStampOnly'], 'lastRun' => $J['lastRun'],
                'schedule' => $sched, 'next' => $next, 'cells' => $cells, 'fresh' => $fresh,
            ];
        }
        // Places = distinct locations holding a recent copy.
        $active = []; $setUp = [];
        $newest = null; $newestBy = null; $nextCopy = null;
        foreach ($copies as $c) {
            if (!in_array($c['status'], ['off', 'planned', 'paused'], true)) $setUp[$c['location']] = true;
            if ($c['fresh']) $active[$c['location']] = true;
            if ($c['lastOk'] && !in_array($c['status'], ['off', 'planned', 'paused'], true) && ($newest === null || $c['lastOk'] > $newest)) {
                $newest = $c['lastOk']; $newestBy = $c['display'];
            }
            if ($c['next'] && ($nextCopy === null || $c['next'] < $nextCopy)) $nextCopy = $c['next'];
        }
        // Verdict: risk if no location holds a recent copy, "one" if only one does, else good.
        // "new": nothing to show yet only because every copy's job is new and hasn't had its first run.
        $verdict = !$active ? 'risk' : (count($active) <= 1 ? 'one' : 'good');
        if (!$active) {
            $waiting = array_filter($copies, function ($c) { return !in_array($c['status'], ['off', 'paused', 'planned'], true); });
            $allNew = $waiting && !array_filter($waiting, function ($c) { return !($c['jobStatus'] === 'never' && $c['next']); });
            if ($allNew) $verdict = 'new';
        }
        $ri = $D['restore']['copy'] ?? 0;
        $best = $copies[0] ?? null;
        foreach ($copies as $c) { if ($c['i'] === $ri) { $best = $c; break; } }
        // How far back you can go: the reach of every copy that is actually working.
        $reach = [];
        foreach ($copies as $c) {
            if ($c['reach'] !== '' && in_array($c['status'], ['ok', 'warn', 'inc', 'miss'], true) && $c['lastOk']) $reach[$c['reach']] = true;
        }
        $data[] = [
            'id' => $D['id'], 'name' => $D['name'], 'source' => $D['source'] ?? '', 'desc' => $D['desc'] ?? '',
            'verdict' => $verdict, 'places' => count($active), 'placesSetUp' => count($setUp),
            'newest' => $newest, 'newestBy' => $newestBy, 'next' => $nextCopy, 'reach' => array_keys($reach),
            'restore' => $best ? ['location' => $best['location'], 'path' => $best['path'], 'job' => $best['display'],
                                  'lastOk' => $best['lastOk'], 'why' => $D['restore']['why'] ?? ''] : null,
            'copies' => $copies,
        ];
    }

    // Alerts (computed, most severe first)
    $alerts = [];
    foreach ($jobs as $J) {
        if ($J['status'] === 'never' && in_array($J['schedState'], ['unscheduled', 'event'], true)) {
            $alerts[] = ['sev' => 'fail', 'title' => $J['display'] . ' has never run', 'detail' => 'It has no schedule in User Scripts, and nothing it writes exists yet.', 'go' => ['view' => 'scripts', 'id' => $J['id']]];
        } elseif ($J['status'] === 'miss') {
            $alerts[] = ['sev' => 'fail', 'title' => $J['display'] . ': ' . lcfirst($J['statusText']), 'detail' => 'Next run: ' . ($J['next'] ? bc_day($J['next']) . ', ' . date('H:i', $J['next']) : 'not scheduled') . '.', 'go' => ['view' => 'scripts', 'id' => $J['id']]];
        } elseif (in_array($J['status'], ['warn', 'fail'], true) && $J['schedState'] === 'scheduled') {
            $alerts[] = ['sev' => $J['status'], 'title' => $J['display'] . ': ' . ($J['lastStatus'] === 'fail' ? 'last run failed' : 'last run had an issue'), 'detail' => (string)$J['statusText'], 'go' => ['view' => 'scripts', 'id' => $J['id']]];
        }
    }
    foreach ($data as $D) {
        if ($D['verdict'] === 'risk') $alerts[] = ['sev' => 'fail', 'title' => $D['name'] . ': no recent copy', 'detail' => $D['newest'] ? 'The newest copy is from ' . bc_day($D['newest']) . '.' : 'No copy on record.', 'go' => ['view' => 'data', 'id' => $D['id']]];
        elseif ($D['verdict'] === 'one') $alerts[] = ['sev' => 'warn', 'title' => $D['name'] . ' is stored in one place only', 'detail' => 'Every copy is on ' . ($locs[$D['copies'][0]['location']]['name'] ?? 'one location') . '.', 'go' => ['view' => 'data', 'id' => $D['id']]];
    }
    foreach ($locs as $L) {
        if ($L['usedPct'] !== null && $L['usedPct'] >= 90) $alerts[] = ['sev' => 'warn', 'title' => $L['name'] . ' is ' . $L['usedPct'] . '% full', 'detail' => 'Backups to it may start failing when it fills.', 'go' => ['view' => 'locations', 'id' => $L['id']]];
        if (!$L['mounted']) $alerts[] = ['sev' => 'fail', 'title' => $L['name'] . ' is not mounted', 'detail' => $L['path'] . ' is not mounted, so copies to it are failing or going to RAM.', 'go' => ['view' => 'locations', 'id' => $L['id']]];
    }
    $rank = ['fail' => 0, 'warn' => 1, 'info' => 2];
    usort($alerts, function ($a, $b) use ($rank) { return $rank[$a['sev']] <=> $rank[$b['sev']]; });

    $boot = null;
    $up = @file_get_contents('/proc/uptime');
    if ($up) $boot = $now - (int)floatval($up);

    // Persist our own state (cache pool, never the array).
    if ($persist) {
        @file_put_contents($ledgerFile, json_encode($ledger));
        @file_put_contents($BC_PROBE_FILE, json_encode($BC_PROBE));
    }

    return [
        'generated' => $now, 'host' => php_uname('n'), 'boot' => $boot, 'stateDir' => $state,
        'catalog' => $catFile, 'days' => $days, 'jobs' => $jobs, 'locations' => $locs, 'data' => $data, 'alerts' => $alerts,
    ];
}

function bc_write_status($doc)
{
    $state = bc_state_dir();
    $tmp = "$state/status.json.tmp";
    if (@file_put_contents($tmp, json_encode($doc)) !== false) @rename($tmp, "$state/status.json");
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['argv'][0] ?? '') === realpath(__FILE__)) {
    $args  = array_slice($_SERVER['argv'], 1);
    $force  = in_array('--force', $args, true);
    $stdout = in_array('--stdout', $args, true);
    $doc    = bc_collect($force, !$stdout);
    if ($stdout) {
        echo json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    } else {
        bc_write_status($doc);
    }
}
