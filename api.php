<?php
/*
 * Backup Check - status endpoint for the page.
 *   GET                         -> the cached status (rebuilt first if older than 20 minutes)
 *   GET ?files=<job>&run=<id>   -> the files one backup run added, changed or removed (read-only)
 *   GET ?summary=view           -> the latest daily summary file, as a web page
 *   POST action=refresh         -> rebuild now, including the slow probes of USB/array paths
 *   POST action=summary         -> rebuild the status and save today's summary file now
 * unRAID checks csrf_token on every POST to the webGUI, so the POST actions are CSRF-protected.
 */
require_once __DIR__ . '/include/summary.php';

header('Cache-Control: no-store');

// ---- the daily summary, shown as a page -------------------------------------------------------
if (($_GET['summary'] ?? '') === 'view') {
    $f = BC_SUMMARY_DIR . '/' . BC_SUMMARY_LATEST;
    if (!is_file($f)) {
        $doc = bc_collect(false, true);
        if (!isset($doc['error'])) bc_write_status($doc);
        bc_summary_write($doc);
    }
    if (!is_file($f)) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'No summary has been saved yet.'; exit; }
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
    readfile($f);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// ---- one run's file list -----------------------------------------------------------------
if (isset($_GET['files'])) {
    $job = (string)$_GET['files'];
    $run = (string)($_GET['run'] ?? '');
    if (!preg_match('/^[a-z0-9_-]{1,80}$/', $job) || !preg_match('/^\d{8}-\d{6}$/', $run)) {
        http_response_code(400); echo json_encode(['error' => 'bad request']); exit;
    }
    $f = bc_state_dir(false) . "/runs/$job/$run.files.gz";
    if (!is_file($f)) { echo json_encode(['steps' => [], 'total' => 0, 'missing' => true]); exit; }

    $max = 20000; $total = 0; $steps = []; $cur = null;
    $gz = gzopen($f, 'r');
    while ($gz && ($ln = gzgets($gz)) !== false) {
        $ln = rtrim($ln, "\r\n");
        if ($ln === '') continue;
        if (preg_match('/^### (.*?)\s+\((.*)\)$/', $ln, $m) || preg_match('/^### (.*)$/', $ln, $m)) {
            $steps[] = ['name' => $m[1], 'items' => []]; $cur = count($steps) - 1; continue;
        }
        if ($cur === null) { $steps[] = ['name' => 'Files', 'items' => []]; $cur = 0; }
        $a = null; $p = null;
        if (preg_match('/^[<>]f\+{9}\s+(.*)$/', $ln, $m))        { $a = 'new';     $p = $m[1]; }
        elseif (preg_match('/^[<>]f\S{9}\s+(.*)$/', $ln, $m))    { $a = 'changed'; $p = $m[1]; }
        elseif (preg_match('/^\*deleting\s+(.*)$/', $ln, $m))    { $a = 'removed'; $p = $m[1]; }
        elseif (preg_match('/^cd\+{9}\s+(.*)$/', $ln, $m))       { $a = 'folder';  $p = $m[1]; }
        elseif (preg_match('/^kept old version: (.*)$/', $ln, $m)) { $a = 'version'; $p = $m[1]; }
        if ($a === null) continue;
        $total++;
        if ($total <= $max) $steps[$cur]['items'][] = ['a' => $a, 'p' => $p];
    }
    if ($gz) gzclose($gz);
    echo json_encode(['steps' => $steps, 'total' => $total, 'truncated' => $total > $max], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- the status document (with the summary file's location and date) ------------------------
$post    = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$action  = $post ? ($_POST['action'] ?? '') : '';
$file    = bc_state_dir() . '/status.json';
$stale   = !is_readable($file) || (time() - (int)@filemtime($file)) > 20 * 60;

if ($action === 'refresh' || $action === 'summary' || $stale) {
    $doc = bc_collect($action === 'refresh', true);
    if (!isset($doc['error'])) bc_write_status($doc);
    $info = $action === 'summary' ? bc_summary_write($doc) : bc_summary_info();
} else {
    $doc = json_decode((string)@file_get_contents($file), true) ?: ['error' => 'Could not read the status file'];
    $info = bc_summary_info();
}
$doc['summary'] = $info;
echo json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
