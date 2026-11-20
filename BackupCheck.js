/* Backup Check - page renderer. Reads api.php and draws the views; never changes anything.
 * Everything is drawn inside a shadow root, so unRAID's CSS can't style it and ours can't leak out. */
(function () {
  'use strict';
  var host = document.getElementById('bc-root');
  if (!host) return;
  var API = host.getAttribute('data-api');
  var CSS = host.getAttribute('data-css');
  var shadow = host.attachShadow ? host.attachShadow({ mode: 'open' }) : null;
  var mount = shadow || host;
  mount.innerHTML = '<link rel="stylesheet" href="' + CSS + '"><div class="bc-root"><div class="bc-loading">Loading backup status…</div></div>';
  var root = mount.querySelector('.bc-root');
  var D = null;                 // last status document
  var S = { view: 'overview', alertsOpen: false, selData: null, selJob: null, busy: false, err: null, files: null };

  /* ---------- helpers ---------- */
  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  var DOW = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  function hm(d) { return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2); }
  function dayStart(d) { return new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime(); }
  function dateText(d) {
    var s = DOW[d.getDay()] + ' ' + MON[d.getMonth()] + ' ' + d.getDate();
    return d.getFullYear() !== new Date().getFullYear() ? s + ', ' + d.getFullYear() : s;
  }
  function when(ts) {               // "Today 02:09" / "Yesterday 23:00" / "Mon 02:00" / "Sat Nov 7"
    if (!ts) return 'Never';
    var d = new Date(ts * 1000), days = Math.round((dayStart(new Date()) - dayStart(d)) / 86400000);
    if (days === 0) return 'Today ' + hm(d);
    if (days === 1) return 'Yesterday ' + hm(d);
    if (days > 1 && days < 6) return DOW[d.getDay()] + ' ' + hm(d);
    return dateText(d);
  }
  function ago(ts) {
    if (!ts) return '';
    var s = Math.max(0, Date.now() / 1000 - ts);
    if (s < 90) return 'just now';
    if (s < 3600) return Math.round(s / 60) + ' min ago';
    if (s < 36 * 3600) return Math.round(s / 3600) + ' h ago';
    return Math.round(s / 86400) + ' days ago';
  }
  function nextText(ts) {
    if (!ts) return '—';
    var d = new Date(ts * 1000), days = Math.round((dayStart(d) - dayStart(new Date())) / 86400000);
    if (days === 0) return 'Today ' + hm(d);
    if (days === 1) return 'Tomorrow ' + hm(d);
    return dateText(d) + ', ' + hm(d);
  }
  function inText(ts) {
    if (!ts) return '';
    var s = ts - Date.now() / 1000;
    if (s < 3600) return 'in ' + Math.max(1, Math.round(s / 60)) + ' min';
    if (s < 36 * 3600) return 'in ' + Math.round(s / 3600) + ' h';
    return 'in ' + Math.round(s / 86400) + ' days';
  }
  function num(n) { return Number(n || 0).toLocaleString(); }
  var ICON = {
    db: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><ellipse cx="12" cy="5.5" rx="7.5" ry="2.8"/><path d="M4.5 5.5v6c0 1.5 3.4 2.8 7.5 2.8s7.5-1.3 7.5-2.8v-6"/><path d="M4.5 11.5v6c0 1.5 3.4 2.8 7.5 2.8 1 0 2-.1 2.9-.2"/><path d="M16.5 18.5l2 2 3.5-4"/></svg>',
    refresh: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 11a8 8 0 1 0-2.3 5.7"/><path d="M20 4v7h-7"/></svg>',
    chev: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>',
    copy: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>',
    restore: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/><path d="M12 8v4l3 2"/></svg>',
    warn: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="flex:none"><path d="M12 3.5L2.5 20h19L12 3.5z"/><path d="M12 10v4.5"/><path d="M12 17.5v.01"/></svg>',
    clock: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="flex:none"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>'
  };

  // status vocabularies: [colour class, square class, label]
  var COPY = {
    ok: ['s-ok', 'k-o', 'Copied OK'], warn: ['s-warn', 'k-w', 'Copied, with an issue'], fail: ['s-bad', 'k-f', 'Last run failed'],
    miss: ['s-bad', 'k-m', 'Last run missed'], never: ['s-acc', 'k-s', 'First run coming'], off: ['s-off', 'k-x', 'Script disabled'],
    inc: ['s-ok', 'k-i', 'Included in another copy'], running: ['s-acc', 'k-d', 'Running now'], planned: ['s-acc', 'k-p', 'Planned'],
    paused: ['s-acc', 'k-p', 'Paused']
  };
  var JOB = {
    ok: ['s-ok', 'Healthy'], warn: ['s-warn', 'Ran, with an issue'], fail: ['s-bad', 'Last run failed'], miss: ['s-bad', 'Missed last run'],
    never: ['s-acc', 'First run coming'], off: ['s-off', 'Disabled'], running: ['s-acc', 'Running now'], planned: ['s-acc', 'Planned'],
    paused: ['s-acc', 'Paused']
  };
  var RUN = { ok: ['s-ok', 'k-o', 'OK'], warn: ['s-warn', 'k-w', 'Warning'], fail: ['s-bad', 'k-f', 'Failed'] };
  var VERDICT = { good: ['s-ok', 'Well protected'], one: ['s-warn', 'One place only'], risk: ['s-bad', 'At risk'], 'new': ['s-acc', 'New: first run coming'] };
  var CELL = { o: ['k-o', 'Copied OK'], w: ['k-w', 'Ran, with an issue'], f: ['k-f', 'Failed'], m: ['k-m', 'Missed'], u: ['k-u', 'No record'],
               '.': ['k-dot', 'Not scheduled that day'], s: ['k-s', 'Scheduled'], d: ['k-d', 'Due later today'] };
  var SEV = { fail: 's-bad', warn: 's-warn', info: 's-off' };
  var TIERS = [['daily', 'Daily'], ['weekly', 'Weekly'], ['monthly', 'Monthly']];
  var MADE = { ok: 1, warn: 1, inc: 1 };
  var SIDELINED = { off: 1, planned: 1, paused: 1 };

  function sq(cls, title, style) { return '<span class="bc-sq ' + cls + '"' + (title ? ' title="' + esc(title) + '"' : '') + (style ? ' style="' + style + '"' : '') + '></span>'; }
  function strip(cells) {           // the last 7 days (6 past + today) of one copy or job
    var out = '';
    String(cells || '').slice(0, 7).split('').forEach(function (c, i) {
      var k = CELL[c] || CELL['.'];
      out += sq(k[0], (D.days[i] ? D.days[i].dow + ' ' + D.days[i].num + ' · ' : '') + k[1]);
    });
    return '<span class="bc-sqs">' + out + '</span>';
  }
  function dayLetters() {
    return '<span class="days" aria-hidden="true">' + D.days.slice(0, 7).map(function (d) {
      return '<span class="' + (d.today ? 'today' : '') + '" title="' + esc(d.dow + ' ' + d.num) + '">' + esc(d.dow.charAt(0)) + '</span>';
    }).join('') + '</span>';
  }
  function loc(id) { return (D.locations && D.locations[id]) || { name: id, kind: '' }; }
  function job(id) { return (D.jobs && D.jobs[id]) || { name: id, display: id }; }
  function notesList(notes) {
    if (!notes || !notes.length) return '';
    return '<ul class="bc-notes">' + notes.map(function (n) {
      return '<li><span class="bc-dot ' + (SEV[n[0]] || 's-off') + '"></span><span>' + esc(n[1]) + '</span></li>';
    }).join('') + '</ul>';
  }
  function legend() {
    return '<span class="bc-legend">' +
      [['k-o', 'Copied'], ['k-w', 'Copied, with an issue'], ['k-m', 'Missed'], ['k-s', 'Scheduled / first run coming'], ['k-p', 'Paused or planned'], ['k-dot', 'Not scheduled that day'], ['k-u', 'No record']]
        .map(function (x) { return '<span>' + sq(x[0]) + esc(x[1]) + '</span>'; }).join('') + '</span>';
  }
  function tierCount(list) {        // "3 made · 1 paused", "0 of 2 made", ...
    var parts = [], made = 0, side = {}, active = 0, waiting = 0;
    list.forEach(function (c) {
      if (SIDELINED[c.status]) side[c.status] = (side[c.status] || 0) + 1;
      else if (c.status === 'never') waiting++;
      else { active++; if (MADE[c.status]) made++; }
    });
    var cls = 's-ok';
    if (active) {
      parts.push(made === active ? made + ' made' : made + ' of ' + active + ' made');
      if (made < active) cls = made === 0 ? 's-bad' : 's-warn';
    }
    if (waiting) parts.push(waiting + ' starting soon');
    ['paused', 'planned', 'off'].forEach(function (k) { if (side[k]) parts.push(side[k] + ' ' + (k === 'off' ? 'disabled' : k)); });
    if (!active) cls = (waiting || side.paused || side.planned) ? 's-acc' : 's-off';
    return '<span class="cnt t-c ' + cls + '">' + esc(parts.join(' · ')) + '</span>';
  }
  function liveAlertsFor(d) {
    var jobs = {};
    d.copies.forEach(function (c) { jobs[c.job] = 1; });
    return (D.alerts || []).filter(function (a) {
      return a.go && ((a.go.view === 'data' && a.go.id === d.id) || (a.go.view === 'scripts' && jobs[a.go.id]));
    });
  }
  function filesText(f) {
    if (!f) return '';
    var p = [];
    if (f['new']) p.push(num(f['new']) + ' new');
    if (f.changed) p.push(num(f.changed) + ' changed');
    if (f.removed) p.push(num(f.removed) + ' removed');
    if (f.versioned) p.push(num(f.versioned) + ' old versions kept');
    return p.length ? p.join(' · ') : 'nothing changed';
  }

  /* ---------- header + alerts ---------- */
  function header() {
    var views = [['overview', 'Overview'], ['scripts', 'Jobs'], ['locations', 'Locations']];
    var cur = S.view === 'data' ? 'overview' : S.view;
    var nJobs = Object.keys(D.jobs || {}).length, nLoc = Object.keys(D.locations || {}).length;
    return '<div class="bc-head"><div class="bc-brand"><div class="bc-logo">' + ICON.db + '</div><div>' +
      '<div class="bc-title">Backup Check</div>' +
      '<div class="bc-sub">' + esc(D.host) + ' · ' + D.data.length + ' kinds of data · ' + nJobs + ' jobs · ' + nLoc + ' locations · updated ' + esc(ago(D.generated)) + '</div>' +
      '</div></div><div class="bc-tools"><div class="bc-seg" role="group" aria-label="View">' +
      views.map(function (v) { return '<button data-act="view" data-v="' + v[0] + '" aria-pressed="' + (cur === v[0]) + '">' + v[1] + '</button>'; }).join('') +
      '</div><button class="bc-iconbtn' + (S.busy ? ' spin' : '') + '" data-act="refresh" aria-label="Check again now" title="Check again now (also re-reads the backup drives)">' + ICON.refresh + '</button></div></div>';
  }

  function alertsBox() {
    var a = D.alerts || [];
    var bootTxt = (D.boot && Date.now() / 1000 - D.boot < 14 * 86400) ? 'Tower restarted ' + when(D.boot) : '';
    var h = '<section class="bc-card bc-alerts"><button data-act="alerts" aria-expanded="' + S.alertsOpen + '">' +
      '<span class="bc-count' + (a.length ? '' : ' none') + '">' + a.length + '</span>' +
      '<span style="font-weight:600;color:var(--strong)">' + (a.length ? (a.length === 1 ? 'thing needs' : 'things need') + ' your attention' : 'Nothing needs your attention') + '</span>' +
      '<span class="bc-muted" style="flex:1 1 auto;font-size:12px">' + esc(bootTxt) + '</span>' +
      (a.length ? '<span class="bc-chev">' + ICON.chev + '</span>' : '') + '</button>';
    if (S.alertsOpen && a.length) {
      h += '<div class="bc-alert-grid">' + a.map(function (x, i) {
        var c = SEV[x.sev] || 's-off';
        return '<button class="bc-alert ' + c + '" data-act="go" data-i="' + i + '"><span class="bc-dot ' + c + '"></span><span><b>' + esc(x.title) + '</b><span class="d">' + esc(x.detail) + '</span></span></button>';
      }).join('') + '</div>';
    }
    return h + '</section>';
  }

  /* ---------- overview: one card per kind of data, one row per copy ---------- */
  function copyTable(copies) {
    var h = '';
    TIERS.forEach(function (t) {
      var list = copies.filter(function (c) { return c.tier === t[0]; });
      h += '<div class="bc-thead"><span class="t"><span class="bc-label">' + t[1] + '</span>' + (list.length ? tierCount(list) : '') + '</span>' +
        (list.length ? '<span class="bc-label" style="letter-spacing:0;text-transform:none;font-weight:500">Last copy</span>' + dayLetters() : '') + '</div>';
      if (!list.length) { h += '<div class="bc-none">No ' + t[1].toLowerCase() + ' copy</div>'; return; }
      list.forEach(function (c) {
        var k = COPY[c.status] || COPY.never, L = loc(c.location);
        var last = SIDELINED[c.status] ? k[2].toLowerCase() : c.status === 'never' ? (c.next ? nextText(c.next) : 'not yet') : (c.lastOk ? when(c.lastOk) : 'never');
        var type = c.included ? 'Inside another copy' : (c.label || '');
        h += '<div class="bc-crow' + (c.status === 'off' ? ' dim' : '') + (SIDELINED[c.status] ? ' planned' : '') + '" title="' + esc(c.display + ' — ' + c.path) + '">' +
          sq(k[1], k[2]) +
          '<span class="job">' + esc(type) + '</span>' +
          '<span class="loc">' + esc(L.name) + '</span>' +
          '<span class="last t-c ' + k[0] + '">' + esc(last) + '</span>' +
          (SIDELINED[c.status] || c.status === 'never' ? '<span></span>' : strip(c.cells)) + '</div>';
      });
    });
    return '<div class="bc-ctab">' + h + '</div>';
  }

  function overview() {
    var h = alertsBox();
    h += '<div class="bc-sechead"><h2>Your data</h2>' + legend() + '</div>' +
      '<div class="bc-explain">Each row is one backup copy: its type, and the drive it lands on. The squares are that copy\'s last 7 days, with today on the right. Click a card for paths, run history and how to restore.</div>' +
      '<div class="bc-grid">';
    D.data.forEach(function (d) {
      var v = VERDICT[d.verdict] || VERDICT.risk;
      var n = liveAlertsFor(d).length;
      var big = d.verdict === 'new' ? 'Not yet' : when(d.newest);
      var sub = d.verdict === 'new' ? 'first run ' + nextText(d.next) : (d.newest ? ago(d.newest) + ' · by ' + d.newestBy : 'no copy on record');
      h += '<div class="bc-dc" role="button" tabindex="0" data-act="data" data-id="' + esc(d.id) + '" aria-label="' + esc(d.name + ': open details') + '">' +
        '<div class="bc-dc-head"><div style="min-width:0"><div class="bc-dc-name"><span class="bc-dot ' + v[0] + '"></span>' + esc(d.name) + '</div><div class="bc-dc-src">' + esc(d.source) + '</div></div>' +
        '<span class="bc-chip ' + v[0] + '">' + v[1] + '</span></div>' +
        '<div class="bc-dc-sum"><div><div class="bc-label">Last backed up</div><div class="bc-big' + (d.verdict === 'risk' ? ' t-c s-bad' : '') + '">' + esc(big) + '</div>' +
        '<div class="bc-muted" style="font-size:12px">' + esc(sub) + '</div></div>' +
        '<div class="bc-places"><div class="n t-c ' + (d.places > 1 ? 's-ok' : d.places === 1 ? 's-warn' : 's-bad') + '">' + d.places + '</div><div class="bc-muted" style="font-size:12px">' + (d.places === 1 ? 'drive holds' : 'drives hold') + ' a recent copy</div></div></div>' +
        (d.reach && d.reach.length ? '<div class="bc-reach">' + ICON.clock + '<span><b>How far back:</b> ' + esc(d.reach.join(' · ')) + '</span></div>' : '') +
        copyTable(d.copies) +
        '<div class="bc-dc-foot"><span class="' + (n ? 't-c s-bad' : 'bc-muted') + '">' + (n ? n + (n === 1 ? ' alert' : ' alerts') : 'No alerts') + '</span><span class="more">Details, paths &amp; restore →</span></div></div>';
    });
    return h + '</div>';
  }

  /* ---------- data detail ---------- */
  function dataDetail() {
    var d = D.data.filter(function (x) { return x.id === S.selData; })[0] || D.data[0];
    var v = VERDICT[d.verdict] || VERDICT.risk;
    var h = '<button class="bc-btn" data-act="view" data-v="overview" style="align-self:flex-start">← Back to all data</button><div class="bc-detail"><nav class="bc-nav" aria-label="Protected data">';
    D.data.forEach(function (x) {
      var vv = VERDICT[x.verdict] || VERDICT.risk;
      h += '<button data-act="data" data-id="' + esc(x.id) + '" aria-pressed="' + (x.id === d.id) + '"><span style="display:flex;gap:8px;align-items:center;min-width:0"><span class="bc-dot ' + vv[0] + '"></span><b style="color:var(--strong)">' + esc(x.name) + '</b></span>' +
        '<span style="font-weight:600;text-align:right" class="' + (x.verdict === 'risk' ? 't-c s-bad' : '') + '">' + esc(x.verdict === 'new' ? 'Not yet' : when(x.newest)) + '</span>' +
        '<span class="bc-muted" style="font-size:12px;padding-left:17px">' + vv[1] + '</span><span class="bc-muted" style="font-size:12px;text-align:right">' + x.places + (x.places === 1 ? ' drive' : ' drives') + '</span></button>';
    });
    h += '</nav><article class="bc-card bc-article"><div style="display:flex;flex-wrap:wrap;gap:12px;justify-content:space-between;align-items:flex-start"><div style="min-width:0">' +
      '<h2 style="font-size:22px">' + esc(d.name) + '</h2><div style="color:var(--text2)">' + esc(d.desc) + '</div><span class="bc-code" style="margin-top:6px">source: ' + esc(d.source) + '</span></div>' +
      '<span class="bc-chip ' + v[0] + '">' + v[1] + '</span></div>';
    if (d.restore) {
      var L = loc(d.restore.location);
      h += '<div class="bc-hero"><div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center"><span class="bc-label" style="color:var(--acc);display:flex;gap:8px;align-items:center">' + ICON.restore + 'If you need to restore, start here</span>' +
        '<span class="bc-muted" style="font-size:12px">' + esc(d.restore.lastOk ? when(d.restore.lastOk) : 'not made yet') + ' · ' + esc(d.restore.job) + '</span></div>' +
        '<div style="font-size:17px;font-weight:600;color:var(--strong)">' + esc(L.name) + ' <span class="bc-muted" style="font-size:13px;font-weight:400">· ' + esc(L.kind) + '</span></div>' +
        '<div class="p"><code>' + esc(d.restore.path) + '</code><button class="bc-btn" data-act="copy" data-path="' + esc(d.restore.path) + '" style="height:44px">' + ICON.copy + '<span>Copy path</span></button></div>' +
        '<div style="font-size:13px;color:var(--text2)">' + esc(d.restore.why) + '</div></div>';
    }
    var nSide = d.copies.filter(function (c) { return SIDELINED[c.status]; }).length;
    h += '<div class="bc-stats">' +
      stat('Newest copy', d.verdict === 'new' ? 'Not yet' : when(d.newest), d.newest ? ago(d.newest) + ' · ' + d.newestBy : 'first run ' + nextText(d.next), d.verdict === 'risk' ? 't-c s-bad' : '') +
      stat('Drives', String(d.places), 'hold a recent copy, of ' + d.placesSetUp + ' set up', d.places > 1 ? 't-c s-ok' : 't-c s-warn') +
      stat('How far back', d.reach && d.reach.length ? d.reach[0] : '—', d.reach && d.reach.length > 1 ? d.reach.slice(1).join(' · ') : '', '') +
      stat('Next copy', nextText(d.next), inText(d.next) + (nSide ? ' · ' + nSide + ' paused or planned' : ''), '') + '</div>';
    h += '<div><h3 class="bc-label" style="margin-bottom:8px">Every copy, and where it\'s stored · click one for its runs</h3><div class="bc-table"><div class="bc-tr copies h bc-label"><span></span><span>Stored on</span><span>Made by</span><span>Last copy</span><span>Keeps</span></div>';
    TIERS.forEach(function (t) {
      var list = d.copies.filter(function (c) { return c.tier === t[0]; });
      if (!list.length) return;
      h += '<div class="bc-tierrow bc-label">' + t[1] + ' · ' + tierCount(list) + '</div>';
      list.forEach(function (c) {
        var k = COPY[c.status] || COPY.never, L = loc(c.location);
        var last = SIDELINED[c.status] ? k[2] : c.status === 'never' ? 'First run ' + nextText(c.next) : (c.lastOk ? when(c.lastOk) : 'Never');
        h += '<button class="bc-tr copies click' + (c.status === 'off' ? ' dim' : '') + '" data-act="job" data-id="' + esc(c.job) + '">' + sq(k[1], k[2], 'margin-top:3px;width:14px;height:14px') +
          '<span style="display:flex;flex-direction:column;gap:3px;min-width:0"><span><b style="color:var(--strong)">' + esc(L.name) + '</b> <span class="bc-muted" style="font-size:12px">· ' + esc(L.kind) + '</span></span><span class="bc-code">' + esc(c.path) + '</span></span>' +
          '<span style="display:flex;flex-direction:column;gap:2px"><span style="color:var(--link);font-weight:500">' + esc(c.display) + '</span><span class="bc-muted" style="font-size:12px">' + esc((c.included ? 'Inside another copy' : c.label) + ' · ' + c.schedule) + '</span></span>' +
          '<span style="display:flex;flex-direction:column;gap:2px"><span>' + esc(last) + '</span><span class="t-c ' + k[0] + '" style="font-size:12px">' + esc(SIDELINED[c.status] ? (job(c.job).statusText || '') : k[2]) + (c.lastOkStampOnly ? ' (from stamp)' : '') + '</span></span>' +
          '<span style="font-size:12.5px;color:var(--text2)">' + esc(c.keeps) + '</span></button>';
      });
    });
    h += '</div></div>';
    // 10-day matrix
    var rows = d.copies.filter(function (c) { return !SIDELINED[c.status]; });
    if (rows.length) {
      h += '<div><div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:8px"><h3 class="bc-label">Copy history · ' + esc(D.days[0].dow + ' ' + D.days[0].num) + ' → ' + esc(D.days[D.days.length - 1].dow + ' ' + D.days[D.days.length - 1].num) + '</h3></div>' +
        '<div class="bc-matrix"><div class="bc-mrow"><span></span>' + D.days.map(function (x) { return '<span class="bc-mday' + (x.today ? ' today' : '') + (x.future ? ' future' : '') + '">' + esc(x.dow) + '<br>' + esc(x.num) + '</span>'; }).join('') + '</div>' +
        rows.map(function (c) {
          var lab = (c.included ? 'Inside another copy' : c.label) + ' → ' + loc(c.location).name;
          return '<div class="bc-mrow"><span class="lab" title="' + esc(c.display) + '">' + esc(lab) + '</span>' +
            String(c.cells).split('').map(function (ch, i) { var k = CELL[ch] || CELL['.']; return '<span class="bc-mcell' + (D.days[i] && D.days[i].today ? ' today' : '') + '"><span class="' + k[0] + '" title="' + esc(D.days[i].dow + ' ' + D.days[i].num + ' · ' + k[1]) + '"></span></span>'; }).join('') + '</div>';
        }).join('') + '</div></div>';
    }
    var notes = liveAlertsFor(d).map(function (a) { return [a.sev, a.title]; }), seen = {};
    d.copies.forEach(function (c) { if (seen[c.job]) return; seen[c.job] = 1; (job(c.job).notes || []).forEach(function (n) { notes.push([n[0], job(c.job).display + ': ' + n[1]]); }); });
    if (notes.length) h += '<div><h3 class="bc-label">Alerts and notes</h3>' + notesList(notes) + '</div>';
    return h + '</article></div>';
  }
  function stat(k, v, s, cls) { return '<div class="bc-stat"><div class="bc-label">' + esc(k) + '</div><div class="v ' + (cls || '') + '">' + esc(v) + '</div><div class="s">' + esc(s) + '</div></div>'; }

  /* ---------- jobs ---------- */
  function jobsView() {
    var order = { fail: 0, miss: 1, never: 2, warn: 3, running: 4, ok: 5, paused: 6, planned: 7, off: 8 };
    var ids = Object.keys(D.jobs).sort(function (a, b) { return ((order[D.jobs[a].status] || 0) - (order[D.jobs[b].status] || 0)) || D.jobs[a].display.localeCompare(D.jobs[b].display); });
    if (!S.selJob || !D.jobs[S.selJob]) S.selJob = ids[0];
    var backs = {}, stores = {};
    D.data.forEach(function (d) { d.copies.forEach(function (c) {
      (backs[c.job] = backs[c.job] || {})[d.name] = 1;
      (stores[c.job] = stores[c.job] || {})[loc(c.location).name] = 1;
    }); });
    var list = function (o) { return Object.keys(o || {}).join(', ') || '—'; };
    var h = '<div class="bc-split"><section class="bc-card"><div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;padding:14px 18px"><h2 style="font-size:15px">Backup jobs · ' + ids.length + '</h2><span class="bc-muted" style="font-size:12px">The ones that need you come first · click one for its runs</span></div>' +
      '<div class="bc-jobs"><div class="bc-tr jobs h bc-label"><span></span><span>Job</span><span>Backs up → stores on</span><span>Schedule</span><span>Last run</span><span>Last 7 days</span></div>';
    ids.forEach(function (id) {
      var j = D.jobs[id], st = JOB[j.status] || JOB.never;
      h += '<button class="bc-tr jobs' + (j.status === 'off' ? ' dim' : '') + '" data-act="job" data-id="' + esc(id) + '" aria-pressed="' + (S.selJob === id) + '">' +
        '<span class="bc-dot ' + st[0] + '"></span><span style="display:flex;flex-direction:column;min-width:0"><b style="color:var(--strong)">' + esc(j.display) + '</b><span class="t-c ' + st[0] + '" style="font-size:12px">' + esc(j.label + ' · ' + (j.status === 'ok' ? st[1] : j.statusText)) + '</span></span>' +
        '<span style="font-size:12.5px;color:var(--text2)">' + esc(list(backs[id])) + ' <span class="bc-muted">→ ' + esc(list(stores[id])) + '</span></span>' +
        '<span style="font-size:13px">' + esc(j.schedule) + '</span>' +
        '<span style="display:flex;flex-direction:column"><span style="font-size:13px">' + esc(j.lastRun ? when(j.lastRun) : '—') + '</span><span class="bc-muted" style="font-size:12px">' + esc(j.lastRun ? ago(j.lastRun) : (j.next ? 'first run ' + nextText(j.next) : '')) + '</span></span>' +
        strip(j.cells) + '</button>';
    });
    h += '</div></section>';
    h += jobSide(D.jobs[S.selJob], backs, stores, list) + '</div>';
    return h;
  }

  function jobSide(j, backs, stores, list) {
    var st = JOB[j.status] || JOB.never;
    var hist = (j.history || []).slice().reverse();
    var h = '<aside class="bc-card bc-side" aria-label="Job details"><div style="display:flex;flex-direction:column;gap:6px"><span class="bc-chip ' + st[0] + '" style="align-self:flex-start">' + esc(st[1]) + '</span>' +
      '<h2 style="font-size:19px">' + esc(j.display) + '</h2>' +
      (j.script && j.script !== j.display ? '<div class="bc-muted" style="font-size:12px">User Script: ' + esc(j.script) + '</div>' : '') +
      '<div style="font-size:13px;color:var(--text2)">' + esc(j.label) + ' · ' + esc(list(backs[j.id])) + ' → ' + esc(list(stores[j.id])) + '</div>' +
      (j.status !== 'ok' ? '<div class="t-c ' + st[0] + '" style="font-size:13px">' + esc(j.statusText) + '</div>' : '') + '</div>' +
      '<div class="bc-kv">' + kv('Schedule', j.schedule, '') + kv('Next run', nextText(j.next), inText(j.next)) + kv('Last run', j.lastRun ? when(j.lastRun) : '—', j.lastRun ? ago(j.lastRun) : '') + kv('Duration', j.lastDur || '—', '') + '</div>';
    // run list (newest first) with what each run changed
    h += '<div><div class="bc-label">Runs · newest first</div>';
    if (!hist.length) h += '<div class="bc-empty" style="margin-top:8px">No runs on record yet.' + (j.next ? ' First run ' + esc(nextText(j.next)) + '.' : '') + '</div>';
    else {
      h += '<div class="bc-runs">' + hist.slice(0, 15).map(function (r) {
        var k = RUN[r.status] || RUN.warn;
        var open = S.files && S.files.job === j.id && S.files.run === r.run;
        var detail = r.files ? filesText(r.files) : (r.src === 'stamp' ? 'from a completion stamp' : (r.detail || ''));
        return '<div class="bc-run' + (open ? ' open' : '') + '">' + sq(k[1], k[2]) +
          '<span class="w">' + esc(when(r.start)) + '</span>' +
          '<span class="t-c ' + k[0] + ' st">' + esc(k[2]) + '</span>' +
          '<span class="dur">' + esc(r.durText || '') + '</span>' +
          '<span class="det" title="' + esc(r.detail || '') + '">' + esc(r.status !== 'ok' && r.detail ? r.detail : detail) + '</span>' +
          (r.filelist && r.run ? '<button class="bc-linkbtn" data-act="files" data-job="' + esc(j.id) + '" data-run="' + esc(r.run) + '">' + (open ? 'Hide files' : 'Show files') + '</button>' : '<span></span>') +
          '</div>' + (open ? filesPanel() : '');
      }).join('') + '</div>';
    }
    h += '</div>';
    if ((j.notes || []).length) h += '<div><div class="bc-label">Notes</div>' + notesList(j.notes) + '</div>';
    if (j.plugin) h += '<div><div class="bc-label">Plugin settings</div><div style="font-size:13px;color:var(--text2);margin-top:4px;display:flex;flex-direction:column;gap:4px"><span>Destination <span class="bc-code">' + esc(j.plugin.destination) + '</span></span><span>Keeps ' + esc(j.plugin.keepDays || '?') + ' days (never fewer than ' + esc(j.plugin.keepMin || '?') + ')</span></div></div>';
    return h + '</aside>';
  }
  function kv(k, v, s) { return '<div><div class="bc-label">' + esc(k) + '</div><div style="margin-top:2px">' + esc(v) + '</div>' + (s ? '<div class="bc-muted" style="font-size:12px">' + esc(s) + '</div>' : '') + '</div>'; }

  /* ---------- level 3: the files one run touched ---------- */
  var ACT = { 'new': ['s-ok', 'new'], changed: ['s-acc', 'changed'], removed: ['s-bad', 'removed'], version: ['s-warn', 'old version kept'], folder: ['s-off', 'new folder'] };
  function filesPanel() {
    var F = S.files;
    if (!F || F.loading) return '<div class="bc-files"><div class="bc-muted">Loading the file list…</div></div>';
    if (F.error) return '<div class="bc-files"><div class="t-c s-bad">Couldn\'t load the file list: ' + esc(F.error) + '</div></div>';
    var data = F.data;
    if (!data || !data.total) return '<div class="bc-files"><div class="bc-muted">No files were added, changed or removed in this run.</div></div>';
    var h = '<div class="bc-files"><div class="bc-muted" style="font-size:12px;margin-bottom:6px">' + num(data.total) + ' entries' + (data.truncated ? ' (showing the first 20,000)' : '') + ' · grouped by folder · click a folder to open it</div>';
    data.steps.forEach(function (s) {
      if (!s.items.length) return;
      var groups = {}, order = [];
      s.items.forEach(function (it) {
        if (it.a === 'folder') return;
        var parts = it.p.split('/'), g = parts.length > 1 ? parts[0] : '(top level)';
        if (!groups[g]) { groups[g] = []; order.push(g); }
        groups[g].push(it);
      });
      if (data.steps.length > 1) h += '<div class="bc-label" style="margin:8px 0 4px">' + esc(s.name) + '</div>';
      order.forEach(function (g) {
        var items = groups[g], counts = {};
        items.forEach(function (it) { counts[it.a] = (counts[it.a] || 0) + 1; });
        h += '<details class="bc-fgroup"><summary><b>' + esc(g) + '</b> <span class="bc-muted">' +
          Object.keys(counts).map(function (a) { return counts[a] + ' ' + (ACT[a] || [0, a])[1]; }).join(' · ') + '</span></summary><ul>' +
          items.slice(0, 300).map(function (it) {
            var a = ACT[it.a] || ['s-off', it.a];
            return '<li><span class="bc-chip ' + a[0] + '">' + esc(a[1]) + '</span><span class="p">' + esc(it.p) + '</span></li>';
          }).join('') + (items.length > 300 ? '<li class="bc-muted">… and ' + num(items.length - 300) + ' more in this folder</li>' : '') + '</ul></details>';
      });
    });
    return h + '</div>';
  }
  function loadFiles(jobId, run) {
    if (S.files && S.files.job === jobId && S.files.run === run) { S.files = null; render(); return; }
    S.files = { job: jobId, run: run, loading: true }; render();
    fetch(API + '?files=' + encodeURIComponent(jobId) + '&run=' + encodeURIComponent(run), { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (j) { if (S.files && S.files.run === run) { S.files = { job: jobId, run: run, data: j }; render(); } })
      .catch(function (e) { if (S.files && S.files.run === run) { S.files = { job: jobId, run: run, error: e.message }; render(); } });
  }

  /* ---------- locations ---------- */
  function locationsView() {
    var h = '<div class="bc-locs">';
    Object.keys(D.locations).forEach(function (id) {
      var L = D.locations[id], p = L.usedPct;
      var c = p == null ? 's-off' : p >= 90 ? 's-bad' : p >= 80 ? 's-warn' : 's-ok';
      var holds = [], waiting = 0;
      D.data.forEach(function (d) { d.copies.forEach(function (cp) {
        if (cp.location !== id) return;
        if (cp.lastOk && cp.status !== 'off') holds.push({ d: d, c: cp }); else if (SIDELINED[cp.status] || cp.status === 'never') waiting++;
      }); });
      h += '<section class="bc-card bc-loc"><div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px"><h2 style="font-size:16px">' + esc(L.name) + '</h2>' +
        '<span class="t-c ' + c + '" style="font-weight:600">' + (!L.mounted ? 'not mounted' : p == null ? 'not measured' : p + '% full') + '</span></div>' +
        '<span class="bc-muted" style="font-size:12px;margin-top:-6px">' + esc(L.kind) + ' · <span class="bc-code" style="font-size:11px;padding:1px 6px">' + esc(L.path) + '</span></span>' +
        '<div class="bc-meter ' + c + '"><div style="width:' + (p == null ? 0 : p) + '%"></div></div>' +
        (holds.length ? '<ul class="bc-holds">' + holds.map(function (x) {
          var k = COPY[x.c.status] || COPY.never;
          return '<li><span class="row"><span>' + sq(k[1], k[2]) + '<span><b style="color:var(--strong)">' + esc(x.d.name) + '</b> <span class="bc-muted">· ' + esc(x.c.included ? 'inside another copy' : x.c.label) + '</span></span></span><span class="t-c ' + k[0] + '" style="font-size:12px;flex:none">' + esc(when(x.c.lastOk)) + '</span></span><span class="bc-code">' + esc(x.c.path) + '</span></li>';
        }).join('') + '</ul>' : '<span class="bc-muted" style="font-size:13px">Nothing has been written here yet.</span>') +
        (waiting ? '<span class="bc-muted" style="font-size:12px">+ ' + waiting + ' more ' + (waiting === 1 ? 'copy' : 'copies') + ' paused, planned or starting soon</span>' : '') +
        (L.note ? '<p style="margin-top:2px;font-size:12px;padding-top:8px;border-top:1px solid var(--line2)" class="bc-muted">' + esc(L.note) + '</p>' : '') + '</section>';
    });
    return h + '</div><div class="bc-callout">' + ICON.warn + '<span>Every location is inside the server or attached to it. A copy somewhere else (another building, or the cloud) is what protects against fire, theft or a power surge.</span></div>';
  }

  /* ---------- render & events ---------- */
  function render() {
    if (S.err && !D) { root.innerHTML = '<div class="bc-loading">Couldn\'t load backup status: ' + esc(S.err) + '</div>'; return; }
    if (!D) return;
    if (D.error) { root.innerHTML = '<div class="bc-loading">' + esc(D.error) + '</div>'; return; }
    var openGroups = [];   // keep folders that were open in the file list open across re-renders
    root.querySelectorAll('details.bc-fgroup[open] summary b').forEach(function (b) { openGroups.push(b.textContent); });
    var body = S.view === 'data' ? dataDetail() : S.view === 'scripts' ? jobsView() : S.view === 'locations' ? locationsView() : overview();
    root.innerHTML = '<div class="bc-wrap">' + header() + body +
      '<div class="bc-foot">Backup Check only reads: it never changes a backup. Sources: each backup script\'s own run record, User Scripts schedules, Script Deck run history, and the Appdata Backup plugin\'s log and folders. Names are the ones set in User Scripts / Script Deck.<br>Catalog: ' + esc(D.catalog) + ' · state: ' + esc(D.stateDir) + '</div></div>';
    if (openGroups.length) root.querySelectorAll('details.bc-fgroup').forEach(function (d) { var b = d.querySelector('summary b'); if (b && openGroups.indexOf(b.textContent) >= 0) d.open = true; });
  }

  function load(refresh) {
    var opts = { credentials: 'same-origin', cache: 'no-store' };
    if (refresh) {
      S.busy = true; render();
      var body = new URLSearchParams();
      body.append('action', 'refresh');
      body.append('csrf_token', (typeof csrf_token !== 'undefined') ? csrf_token : '');
      opts.method = 'POST'; opts.body = body;
    }
    return fetch(API, opts).then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    }).then(function (j) { D = j; S.err = null; }).catch(function (e) { S.err = e.message; })
      .then(function () { S.busy = false; render(); });
  }

  function act(b) {
    var a = b.getAttribute('data-act'), id = b.getAttribute('data-id');
    var top = false;
    if (a === 'view') { S.view = b.getAttribute('data-v'); top = true; }
    else if (a === 'alerts') { S.alertsOpen = !S.alertsOpen; }
    else if (a === 'data') { S.view = 'data'; S.selData = id; top = true; }
    else if (a === 'job') { if (S.selJob !== id) S.files = null; S.view = 'scripts'; S.selJob = id; top = true; }
    else if (a === 'files') { loadFiles(b.getAttribute('data-job'), b.getAttribute('data-run')); return; }
    else if (a === 'refresh') { if (!S.busy) load(true); return; }
    else if (a === 'go') {
      var al = (D.alerts || [])[+b.getAttribute('data-i')];
      if (al && al.go) {
        if (al.go.view === 'data') { S.view = 'data'; S.selData = al.go.id; }
        else if (al.go.view === 'scripts') { S.view = 'scripts'; S.selJob = al.go.id; }
        else S.view = al.go.view;
        top = true;
      }
    } else if (a === 'copy') {
      var p = b.getAttribute('data-path'), label = b.querySelector('span');
      if (navigator.clipboard && label) navigator.clipboard.writeText(p).then(function () { label.textContent = 'Copied'; setTimeout(function () { label.textContent = 'Copy path'; }, 1200); });
      return;
    }
    render();
    if (top) { var y = host.getBoundingClientRect().top + window.pageYOffset - 10; if (window.pageYOffset > y) window.scrollTo(0, y); }
  }
  root.addEventListener('click', function (e) {
    if (e.target.closest('summary')) return;   // native open/close of a file-list folder
    var b = e.target.closest('[data-act]');
    if (b && root.contains(b)) act(b);
  });
  root.addEventListener('keydown', function (e) {   // cards are role="button" divs: Enter / Space open them
    if (e.key !== 'Enter' && e.key !== ' ') return;
    var b = e.target.closest('[role="button"][data-act]');
    if (b && b === e.target) { e.preventDefault(); act(b); }
  });

  // Dark unRAID themes: switch to the dark palette.
  try {
    var bg = getComputedStyle(document.body).backgroundColor.match(/\d+/g);
    if (bg && (0.299 * bg[0] + 0.587 * bg[1] + 0.114 * bg[2]) < 110) root.classList.add('bc-dark');
  } catch (e) { /* keep light */ }

  load(false);
  setInterval(function () { if (!S.busy && !S.files && document.visibilityState === 'visible') load(false); }, 60000);
})();
