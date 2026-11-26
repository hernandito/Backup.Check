<?php
/*
 * Backup Check - daily summary file.
 *
 * Writes a one-page report of every backup to the flash drive, so it can be read on any
 * Windows PC even when the server is down (the flash drive is FAT32):
 *
 *   /boot/config/plugins/backupcheck/summary/Backup Summary - latest.html
 *   /boot/config/plugins/backupcheck/summary/Backup Summary - YYYY-MM-DD.html   (last 14 days)
 *
 * The same files are also copied to a "Backup Summary" folder on each backup drive named in the
 * catalog ("summary": {"copies": ["files", "ntfs"]}, location ids), so a copy survives even if the
 * flash drive itself fails. A drive that isn't mounted is skipped (never written to its empty mount point).
 *
 * The file is completely self-contained: styles inline, graphics drawn with CSS, system fonts,
 * no scripts, no images, no internet. Double-click it and it opens in any browser.
 * READ-ONLY with respect to backups: it only reads the status the collector builds.
 *
 * Usage (CLI):  php summary.php      refresh the status, then write today's summary
 */

require_once __DIR__ . '/collect.php';

const BC_SUMMARY_DIR  = BC_CONF_DIR . '/summary';
const BC_SUMMARY_KEEP = 14;                       // dated copies kept
const BC_SUMMARY_LATEST = 'Backup Summary - latest.html';

/* Times in the summary use the server's own time zone (Settings > Date and Time). */
function bc_summary_tz()
{
    $tz = '';
    if (is_readable('/var/local/emhttp/var.ini')) {
        $ini = @parse_ini_file('/var/local/emhttp/var.ini');
        $tz = $ini['timeZone'] ?? '';
    }
    if (!$tz && is_link('/etc/localtime')) $tz = preg_replace('#^.*/zoneinfo/#', '', (string)readlink('/etc/localtime'));
    if ($tz && @date_default_timezone_set($tz)) return;
}

/* The backup drives that also get a copy: [ ['id','name','mnt','dir'], ... ] from the catalog. */
function bc_summary_targets(array $doc)
{
    $cat = json_decode((string)@file_get_contents($doc['catalog'] ?? ''), true) ?: [];
    $out = [];
    foreach ((array)($cat['summary']['copies'] ?? []) as $id) {
        $L = $doc['locations'][$id] ?? null;
        if (!$L || empty($L['path'])) continue;
        $out[] = ['id' => $id, 'name' => $L['name'] ?? $id, 'mnt' => $L['path'], 'dir' => rtrim($L['path'], '/') . '/Backup Summary'];
    }
    return $out;
}

/* Keep the newest BC_SUMMARY_KEEP dated copies in a folder. */
function bc_summary_prune($dir)
{
    $all = glob("$dir/Backup Summary - 20??-??-??.html") ?: [];
    rsort($all);
    foreach (array_slice($all, BC_SUMMARY_KEEP) as $old) @unlink($old);
}

/* Where the files are, and what is there now (for the page). The drive copies are reported from
 * the last save (.copies.json), so showing the page never wakes a backup drive. */
function bc_summary_info()
{
    $dir = BC_SUMMARY_DIR;
    $latest = "$dir/" . BC_SUMMARY_LATEST;
    $dated = glob("$dir/Backup Summary - 20??-??-??.html") ?: [];
    $copies = json_decode((string)@file_get_contents("$dir/.copies.json"), true) ?: [];
    return [
        'dir'      => $dir,
        'file'     => BC_SUMMARY_LATEST,
        'windows'  => 'config\\plugins\\backupcheck\\summary\\' . BC_SUMMARY_LATEST,
        'exists'   => is_file($latest),
        'saved'    => is_file($latest) ? (int)filemtime($latest) : null,
        'size'     => is_file($latest) ? (int)filesize($latest) : null,
        'kept'     => count($dated),
        'keepDays' => BC_SUMMARY_KEEP,
        'copies'   => $copies,
    ];
}

/* Build today's summary from a status document and save it. Returns bc_summary_info(). */
function bc_summary_write(array $doc)
{
    if (isset($doc['error'])) return bc_summary_info() + ['error' => $doc['error']];
    bc_summary_tz();
    $dir = BC_SUMMARY_DIR;
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $html = bc_summary_html($doc);
    $latest = "$dir/" . BC_SUMMARY_LATEST;
    $dated  = "$dir/Backup Summary - " . date('Y-m-d', $doc['generated'] ?? time()) . '.html';
    $tmp = "$dir/.summary.tmp";
    if (@file_put_contents($tmp, $html) === false) return bc_summary_info() + ['error' => "Could not write to $dir"];
    @copy($tmp, $dated);
    @rename($tmp, $latest);
    bc_summary_prune($dir);

    // the same two files on each backup drive named in the catalog
    $results = [];
    foreach (bc_summary_targets($doc) as $t) {
        $r = ['name' => $t['name'], 'dir' => $t['dir'], 'saved' => null, 'error' => null];
        if (!bc_is_mounted($t['mnt'])) {
            $r['error'] = 'not mounted - skipped';
        } elseif (!is_dir($t['dir']) && !@mkdir($t['dir'], 0777, true)) {
            $r['error'] = 'could not create the folder';
        } else {
            $ttmp = $t['dir'] . '/.summary.tmp';
            $ok = @copy($latest, $ttmp) && @copy($latest, $t['dir'] . '/' . basename($dated)) && @rename($ttmp, $t['dir'] . '/' . BC_SUMMARY_LATEST);
            if ($ok) { bc_summary_prune($t['dir']); $r['saved'] = time(); }
            else { @unlink($ttmp); $r['error'] = 'could not write the file'; }
        }
        $results[] = $r;
    }
    @file_put_contents("$dir/.copies.json", json_encode($results, JSON_UNESCAPED_SLASHES));
    return bc_summary_info();
}

/* ------------------------------------------------------------------------ the page */
function bc_summary_html(array $D)
{
    $h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
    $gen = (int)($D['generated'] ?? time());
    $fmt = function ($ts, $withYear = false) { return $ts ? date($withYear ? 'D M j, Y · H:i' : 'D M j · H:i', $ts) : '—'; };
    $ago = function ($ts) use ($gen) {
        if (!$ts) return '';
        $s = max(0, $gen - $ts);
        if ($s < 90) return 'just before this summary';
        if ($s < 3600) return round($s / 60) . ' min earlier';
        if ($s < 36 * 3600) return round($s / 3600) . ' h earlier';
        return round($s / 86400) . ' days earlier';
    };

    $VERDICT = ['good' => ['ok', 'Well protected'], 'one' => ['warn', 'One place only'], 'risk' => ['bad', 'At risk'], 'new' => ['acc', 'New: first run coming']];
    $COPY = ['ok' => ['ok', 'Copied OK'], 'warn' => ['warn', 'Copied, with an issue'], 'fail' => ['bad', 'Last run failed'],
             'miss' => ['bad', 'Last run missed'], 'never' => ['acc', 'First run coming'], 'off' => ['off', 'Script disabled'],
             'inc' => ['ok', 'Included in another copy'], 'running' => ['acc', 'Running now'], 'planned' => ['acc', 'Planned'], 'paused' => ['acc', 'Paused']];
    $JOB = ['ok' => ['ok', 'Healthy'], 'warn' => ['warn', 'Ran, with an issue'], 'fail' => ['bad', 'Last run failed'], 'miss' => ['bad', 'Missed last run'],
            'never' => ['acc', 'First run coming'], 'off' => ['off', 'Disabled'], 'running' => ['acc', 'Running now'], 'planned' => ['acc', 'Planned'], 'paused' => ['acc', 'Paused']];
    $CELL = ['o' => ['ok', 'Copied OK'], 'w' => ['warn', 'Ran, with an issue'], 'f' => ['bad', 'Failed'], 'm' => ['bad', 'Missed'], 'u' => ['unk', 'No record'],
             '.' => ['none', 'Not scheduled that day'], 's' => ['acc', 'Scheduled'], 'd' => ['acc', 'Due later today']];
    $SIDE = ['off' => 1, 'planned' => 1, 'paused' => 1];
    $TIERS = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'];

    $locs = $D['locations'] ?? [];
    $locName = function ($id) use ($locs) { return $locs[$id]['name'] ?? $id; };
    $days = array_slice($D['days'] ?? [], 0, 7);       // 6 past days + today, same as the dashboard
    $squares = function ($cells) use ($days, $CELL, $h) {
        $out = '';
        foreach (str_split(substr((string)$cells, 0, 7)) as $i => $c) {
            $k = $CELL[$c] ?? $CELL['.'];
            $t = isset($days[$i]) ? $days[$i]['dow'] . ' ' . $days[$i]['num'] . ' · ' . $k[1] : $k[1];
            $out .= '<i class="q q-' . $k[0] . '" title="' . $h($t) . '"></i>';
        }
        return '<span class="qs">' . $out . '</span>';
    };
    $dayHead = '<span class="qs dh">' . implode('', array_map(function ($d) use ($h) {
        return '<b' . (!empty($d['today']) ? ' class="t"' : '') . '>' . $h(substr($d['dow'], 0, 1)) . '</b>';
    }, $days)) . '</span>';

    // ---- overall picture
    $data = $D['data'] ?? [];
    $jobs = $D['jobs'] ?? [];
    $alerts = $D['alerts'] ?? [];
    $risk = count(array_filter($data, function ($d) { return ($d['verdict'] ?? '') === 'risk'; }));
    $one  = count(array_filter($data, function ($d) { return ($d['verdict'] ?? '') === 'one'; }));
    $active = array_filter($jobs, function ($j) use ($SIDE) { return empty($SIDE[$j['status'] ?? '']) && ($j['status'] ?? '') !== 'never'; });
    $healthy = count(array_filter($active, function ($j) { return in_array($j['status'] ?? '', ['ok', 'running'], true); }));
    $day24 = 0;
    foreach ($jobs as $j) if (!empty($j['lastOk']) && $gen - $j['lastOk'] < 26 * 3600) $day24++;
    if ($risk || count($alerts) && array_filter($alerts, function ($a) { return ($a['sev'] ?? '') === 'fail'; })) { $overall = ['bad', 'Needs attention']; }
    elseif ($one || $alerts) { $overall = ['warn', 'Mostly protected']; }
    else { $overall = ['ok', 'All data protected']; }

    // ---- styles (inline; system fonts; prints cleanly)
    $css = <<<'CSS'
*{box-sizing:border-box}
html{background:#eef1f5}
body{margin:0;font:14px/1.45 "Segoe UI",system-ui,-apple-system,Roboto,Helvetica,Arial,sans-serif;color:#2d3440;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.page{max-width:1100px;margin:0 auto;padding:28px 22px 40px}
h1,h2,h3{margin:0;color:#121722;line-height:1.2}
code,.mono{font-family:Consolas,"Cascadia Mono","Courier New",monospace;font-size:12px}
.label{font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#9a0101}
.muted{color:#6b7380}
/* hero */
.hero{background:linear-gradient(135deg,#0a77d8 0%,#0861b0 100%);color:#fff;border-radius:16px;padding:24px 26px;display:flex;flex-wrap:wrap;gap:18px;justify-content:space-between;align-items:center;box-shadow:0 6px 24px rgba(10,119,216,.25)}
.hero h1{color:#fff;font-size:26px;font-weight:700}
.hero .sub{opacity:.9;margin-top:4px}
.badge{display:inline-flex;align-items:center;gap:8px;padding:9px 16px;border-radius:999px;font-weight:700;font-size:15px;background:#fff}
.badge i{width:11px;height:11px;border-radius:50%;display:inline-block}
.badge.ok{color:#1d7a43}.badge.ok i{background:#22a55a}
.badge.warn{color:#9a5b00}.badge.warn i{background:#e69500}
.badge.bad{color:#b3261e}.badge.bad i{background:#d93025}
/* stat tiles */
.tiles{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:16px 0 8px}
.tile{background:#fff;border:1px solid #c9c9c9;border-radius:12px;padding:14px 16px}
.tile .v{font-size:26px;font-weight:700;color:#121722;margin-top:2px}
.tile .s{font-size:12px;color:#6b7380}
/* attention */
.attn{background:#fff6f5;border:1px solid #f0c4c0;border-left:5px solid #d93025;border-radius:12px;padding:14px 18px;margin:16px 0}
.attn.calm{background:#f3fbf6;border-color:#bfe5cd;border-left-color:#22a55a}
.attn ul{margin:8px 0 0;padding:0;list-style:none;display:grid;gap:6px}
.attn li{display:flex;gap:10px;align-items:baseline}
.dot{width:9px;height:9px;border-radius:50%;flex:none;display:inline-block;position:relative;top:-1px}
.dot.ok{background:#22a55a}.dot.warn{background:#e69500}.dot.bad{background:#d93025}.dot.acc{background:#0a77d8}.dot.off,.dot.info{background:#9aa3ae}.dot.fail{background:#d93025}
section{margin-top:26px}
.sechead{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap;margin-bottom:10px;padding-bottom:6px;border-bottom:2px solid #dde3ea}
.sechead h2{font-size:18px}
/* data cards */
.card{background:#fff;border:1px solid #c9c9c9;border-radius:14px;padding:18px 20px;margin-bottom:14px;break-inside:avoid;page-break-inside:avoid}
.chead{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}
.chead h3{font-size:18px;display:flex;align-items:center;gap:9px}
.chip{display:inline-block;padding:3px 11px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap}
.chip.ok{background:#e3f5ea;color:#1d7a43}.chip.warn{background:#fdf0d9;color:#9a5b00}.chip.bad{background:#fde5e3;color:#b3261e}.chip.acc{background:#e2effb;color:#0861b0}.chip.off{background:#eef0f3;color:#5b6470}
.kpis{display:grid;grid-template-columns:1.2fr .8fr 2fr;gap:14px;margin:14px 0 12px}
.kpi .v{font-size:17px;font-weight:700;color:#121722;margin-top:2px}
.kpi .s{font-size:12px;color:#6b7380}
.v.bad{color:#b3261e}
table{width:100%;border-collapse:collapse}
th{font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#6b7380;text-align:left;padding:6px 8px;border-bottom:1px solid #dde3ea}
td{padding:7px 8px;border-bottom:1px solid #eef1f4;vertical-align:top;font-size:13px}
tr.tier td{background:#f6f8fb;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#9a0101;padding:5px 8px}
tr.dim td{opacity:.55}
td .p{display:block;color:#6b7380;word-break:break-all;margin-top:2px}
.st{font-weight:600;font-size:12px}
.st.ok{color:#1d7a43}.st.warn{color:#9a5b00}.st.bad{color:#b3261e}.st.acc{color:#0861b0}.st.off{color:#6b7380}
.qs{display:inline-flex;gap:3px;white-space:nowrap}
.q{width:13px;height:13px;border-radius:3px;display:inline-block;background:#e4e8ed}
.q-ok{background:#22a55a}.q-warn{background:#e69500}.q-bad{background:#d93025}.q-acc{background:#fff;box-shadow:inset 0 0 0 2px #0a77d8}.q-unk{background:#c3c9d1}.q-none{background:#f1f3f6;box-shadow:inset 0 0 0 1px #e1e5ea}
.dh b{width:13px;text-align:center;font-size:10px;color:#8a929d;font-weight:600}
.dh b.t{color:#0a77d8}
.restore{margin-top:12px;background:#f2f8fe;border:1px solid #cfe3f6;border-radius:10px;padding:11px 14px}
.restore .where{font-weight:600;color:#121722;margin:2px 0}
.restore code{background:#fff;border:1px solid #cfe3f6;border-radius:6px;padding:3px 7px;display:inline-block;word-break:break-all}
.restore .why{font-size:12.5px;color:#46505c;margin-top:6px}
/* drives */
.drives{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px}
.drive{background:#fff;border:1px solid #c9c9c9;border-radius:12px;padding:14px 16px;break-inside:avoid}
.drive .top{display:flex;justify-content:space-between;gap:8px;align-items:baseline}
.bar{height:9px;background:#e9edf2;border-radius:99px;overflow:hidden;margin:8px 0 6px}
.bar span{display:block;height:100%;border-radius:99px}
.bar .ok{background:#22a55a}.bar .warn{background:#e69500}.bar .bad{background:#d93025}.bar .off{background:#c3c9d1}
/* jobs */
.jobs{background:#fff;border:1px solid #c9c9c9;border-radius:14px;padding:6px 10px 10px}
.legend{display:flex;flex-wrap:wrap;gap:12px;font-size:12px;color:#6b7380}
.legend span{display:inline-flex;align-items:center;gap:5px}
.foot{margin-top:28px;font-size:12px;color:#6b7380;border-top:1px solid #dde3ea;padding-top:12px}
@media (max-width:760px){.tiles{grid-template-columns:repeat(2,1fr)}.kpis{grid-template-columns:1fr 1fr}.hide-s{display:none}}
@media print{html{background:#fff}.page{padding:0;max-width:none}.hero{box-shadow:none}.card,.tile,.drive,.jobs{border-color:#bbb}a{color:inherit}}
@page{margin:14mm}
CSS;

    $out = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' .
        '<title>Backup Summary · ' . $h($D['host'] ?? '') . ' · ' . date('Y-m-d', $gen) . '</title><style>' . $css . '</style></head><body><div class="page">';

    // ---- hero
    $out .= '<header class="hero"><div><div class="label" style="color:#cfe6fb">Backup Summary</div><h1>' . $h($D['host'] ?? 'Server') . '</h1>' .
        '<div class="sub">' . $h(date('l, F j, Y', $gen)) . ' · made at ' . $h(date('H:i', $gen)) . ' (' . $h(date_default_timezone_get()) . ')</div></div>' .
        '<span class="badge ' . $overall[0] . '"><i></i>' . $h($overall[1]) . '</span></header>';

    // ---- tiles
    $good = count(array_filter($data, function ($d) { return ($d['verdict'] ?? '') === 'good'; }));
    $tile = function ($k, $v, $s) use ($h) { return '<div class="tile"><div class="label">' . $h($k) . '</div><div class="v">' . $h($v) . '</div><div class="s">' . $h($s) . '</div></div>'; };
    $out .= '<div class="tiles">' .
        $tile('Kinds of data', count($data), $good . ' well protected' . ($one ? ' · ' . $one . ' in one place' : '') . ($risk ? ' · ' . $risk . ' at risk' : '')) .
        $tile('Backup jobs', $healthy . ' / ' . count($active), 'healthy, of those scheduled') .
        $tile('Ran in the last day', $day24, 'jobs that completed OK') .
        $tile('Alerts', count($alerts), count($alerts) ? 'listed below' : 'nothing needs attention') . '</div>';

    // ---- attention
    if ($alerts) {
        $out .= '<div class="attn"><div class="label">Needs your attention</div><ul>' . implode('', array_map(function ($a) use ($h) {
            return '<li><span class="dot ' . $h($a['sev'] ?? 'info') . '"></span><span><b>' . $h($a['title'] ?? '') . '</b>' . (!empty($a['detail']) ? ' <span class="muted">— ' . $h($a['detail']) . '</span>' : '') . '</span></li>';
        }, $alerts)) . '</ul></div>';
    } else {
        $out .= '<div class="attn calm"><div class="label" style="color:#1d7a43">All clear</div><div style="margin-top:4px">Every scheduled backup ran as expected. Nothing needs your attention.</div></div>';
    }

    // ---- your data
    $out .= '<section><div class="sechead"><h2>Your data</h2><div class="legend">' .
        '<span><i class="q q-ok"></i>Copied</span><span><i class="q q-warn"></i>With an issue</span><span><i class="q q-bad"></i>Failed / missed</span>' .
        '<span><i class="q q-acc"></i>Scheduled</span><span><i class="q q-none"></i>Not scheduled that day</span><span><i class="q q-unk"></i>No record</span></div></div>';
    foreach ($data as $d) {
        $v = $VERDICT[$d['verdict'] ?? 'risk'] ?? $VERDICT['risk'];
        $isNew = ($d['verdict'] ?? '') === 'new';
        $out .= '<article class="card"><div class="chead"><div><h3><span class="dot ' . $v[0] . '"></span>' . $h($d['name']) . '</h3>' .
            '<div class="muted" style="margin-top:3px">' . $h($d['desc'] ?? '') . ' <span class="mono">' . $h($d['source'] ?? '') . '</span></div></div>' .
            '<span class="chip ' . $v[0] . '">' . $h($v[1]) . '</span></div>';
        $out .= '<div class="kpis">' .
            '<div class="kpi"><div class="label">Last backed up</div><div class="v' . (($d['verdict'] ?? '') === 'risk' ? ' bad' : '') . '">' . $h($isNew ? 'Not yet' : $fmt($d['newest'] ?? 0)) . '</div>' .
            '<div class="s">' . $h($isNew ? 'first run ' . $fmt($d['next'] ?? 0) : (!empty($d['newest']) ? $ago($d['newest']) . ' · ' . ($d['newestBy'] ?? '') : 'no copy on record')) . '</div></div>' .
            '<div class="kpi"><div class="label">Drives</div><div class="v">' . (int)($d['places'] ?? 0) . ' of ' . (int)($d['placesSetUp'] ?? 0) . '</div><div class="s">hold a recent copy</div></div>' .
            '<div class="kpi"><div class="label">How far back</div><div class="v" style="font-size:14px;font-weight:600">' . $h(!empty($d['reach']) ? implode(' · ', $d['reach']) : '—') . '</div></div></div>';
        $out .= '<table><thead><tr><th style="width:24%">Copy</th><th>Stored on · path</th><th style="width:17%">Last copy</th><th class="hide-s" style="width:110px">' . $dayHead . '</th></tr></thead><tbody>';
        foreach ($TIERS as $tk => $tn) {
            $list = array_values(array_filter($d['copies'] ?? [], function ($c) use ($tk) { return ($c['tier'] ?? '') === $tk; }));
            if (!$list) continue;
            $out .= '<tr class="tier"><td colspan="4">' . $h($tn) . '</td></tr>';
            foreach ($list as $c) {
                $k = $COPY[$c['status'] ?? 'never'] ?? $COPY['never'];
                $side = !empty($SIDE[$c['status'] ?? '']);
                $last = $side ? $k[1] : (($c['status'] ?? '') === 'never' ? 'First run ' . $fmt($c['next'] ?? 0) : (!empty($c['lastOk']) ? $fmt($c['lastOk']) : 'Never'));
                $type = !empty($c['included']) ? 'Inside another copy' : ($c['label'] ?? '');
                $out .= '<tr' . ($side || ($c['status'] ?? '') === 'off' ? ' class="dim"' : '') . '><td><b style="color:#121722">' . $h($type) . '</b><span class="p">' . $h($c['display'] ?? '') . '</span></td>' .
                    '<td><b style="color:#121722">' . $h($locName($c['location'] ?? '')) . '</b> <span class="muted" style="font-size:12px">· ' . $h($c['keeps'] ?? '') . '</span><span class="p mono">' . $h($c['path'] ?? '') . '</span></td>' .
                    '<td>' . $h($last) . '<br><span class="st ' . $k[0] . '">' . $h($k[1]) . '</span></td>' .
                    '<td class="hide-s">' . ($side || ($c['status'] ?? '') === 'never' ? '' : $squares($c['cells'] ?? '')) . '</td></tr>';
            }
        }
        $out .= '</tbody></table>';
        if (!empty($d['restore'])) {
            $r = $d['restore'];
            $out .= '<div class="restore"><div class="label" style="color:#0861b0">If you need to restore, start here</div>' .
                '<div class="where">' . $h($locName($r['location'] ?? '')) . ' <span class="muted" style="font-weight:400;font-size:12px">· ' . $h($locs[$r['location'] ?? '']['kind'] ?? '') . ' · ' . $h(!empty($r['lastOk']) ? 'copy from ' . $fmt($r['lastOk']) : 'not made yet') . '</span></div>' .
                '<code>' . $h($r['path'] ?? '') . '</code><div class="why">' . $h($r['why'] ?? '') . '</div></div>';
        }
        $out .= '</article>';
    }
    $out .= '</section>';

    // ---- drives
    $out .= '<section><div class="sechead"><h2>Backup drives</h2><span class="muted" style="font-size:12px">Where the copies live</span></div><div class="drives">';
    foreach ($locs as $L) {
        $p = $L['usedPct'] ?? null;
        $c = $p === null ? 'off' : ($p >= 90 ? 'bad' : ($p >= 80 ? 'warn' : 'ok'));
        $state = empty($L['mounted']) ? 'not mounted' : ($p === null ? 'not measured' : $p . '% full');
        $out .= '<div class="drive"><div class="top"><h3 style="font-size:15px">' . $h($L['name'] ?? '') . '</h3><span class="st ' . $c . '" style="font-size:13px">' . $h($state) . '</span></div>' .
            '<div class="muted" style="font-size:12px">' . $h($L['kind'] ?? '') . '</div>' .
            '<div class="bar"><span class="' . $c . '" style="width:' . (int)($p ?? 0) . '%"></span></div>' .
            '<div class="mono muted">' . $h($L['path'] ?? '') . '</div></div>';
    }
    $out .= '</div></section>';

    // ---- jobs
    $order = ['fail' => 0, 'miss' => 1, 'warn' => 2, 'never' => 3, 'running' => 4, 'ok' => 5, 'paused' => 6, 'planned' => 7, 'off' => 8];
    uasort($jobs, function ($a, $b) use ($order) {
        return (($order[$a['status'] ?? ''] ?? 9) <=> ($order[$b['status'] ?? ''] ?? 9)) ?: strcmp($a['display'] ?? '', $b['display'] ?? '');
    });
    $out .= '<section><div class="sechead"><h2>Every backup job</h2><span class="muted" style="font-size:12px">Anything that needs you comes first</span></div><div class="jobs"><table><thead><tr>' .
        '<th>Job</th><th style="width:16%">Schedule</th><th style="width:20%">Last run</th><th style="width:16%">Next run</th><th class="hide-s" style="width:110px">' . $dayHead . '</th></tr></thead><tbody>';
    foreach ($jobs as $j) {
        $st = $JOB[$j['status'] ?? 'never'] ?? $JOB['never'];
        $out .= '<tr' . (in_array($j['status'] ?? '', ['off', 'paused', 'planned'], true) ? ' class="dim"' : '') . '><td><span class="dot ' . $st[0] . '"></span> <b style="color:#121722">' . $h($j['display'] ?? '') . '</b>' .
            '<span class="p">' . $h(($j['label'] ?? '') . ' · ') . '<span class="st ' . $st[0] . '">' . $h(($j['status'] ?? '') === 'ok' ? $st[1] : ($j['statusText'] ?? $st[1])) . '</span></span></td>' .
            '<td>' . $h($j['schedule'] ?? '') . '</td>' .
            '<td>' . $h(!empty($j['lastRun']) ? $fmt($j['lastRun']) : '—') . (!empty($j['lastDur']) ? '<span class="p">took ' . $h($j['lastDur']) . '</span>' : '') . '</td>' .
            '<td>' . $h(!empty($j['next']) ? $fmt($j['next']) : '—') . '</td>' .
            '<td class="hide-s">' . $squares($j['cells'] ?? '') . '</td></tr>';
    }
    $out .= '</tbody></table></div></section>';

    // ---- footer
    $out .= '<div class="foot">Made by <b>Backup Check</b> on ' . $h($D['host'] ?? '') . ', ' . $h($fmt($gen, true)) . '. ' .
        'This file is saved on the unRAID flash drive in <span class="mono">config\\plugins\\backupcheck\\summary\\</span>' .
        implode('', array_map(function ($t) use ($h) { return ', and in <span class="mono">' . $h($t['dir']) . '</span> on ' . $h($t['name']); }, bc_summary_targets($D))) .
        ', each with one dated copy for each of the last ' . BC_SUMMARY_KEEP . ' days. ' .
        'It is self-contained (no internet or other files needed) and can be opened on any PC. To keep a PDF, use your browser\'s Print → Save as PDF.</div>';

    return $out . '</div></body></html>';
}

/* ------------------------------------------------------------------------ CLI */
if (PHP_SAPI === 'cli' && realpath($_SERVER['argv'][0] ?? '') === realpath(__FILE__)) {
    $doc = bc_collect(false, true);          // fresh status (fast probes; slow ones come from cache)
    if (!isset($doc['error'])) bc_write_status($doc);
    $info = bc_summary_write($doc);
    if (isset($info['error'])) { fwrite(STDERR, $info['error'] . "\n"); exit(1); }
    echo 'Saved ' . $info['dir'] . '/' . $info['file'] . "\n";
}
