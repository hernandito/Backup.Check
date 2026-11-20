# Backup Check

An unRAID plugin that puts every backup on the server in one place, organised by **the data being protected** rather than by script.

For each kind of data (Appdata, Flash, MySQL, Nextcloud…) it shows:

- **when it was last backed up**, and every **copy**: its type (No Deletes, Exact Mirror, Database Dump…), the drive it lands on, and its last 7 days;
- **how far back you can restore** (for example "any of the last 10 nights · deleted files: forever");
- a **"restore from here"** pointer to the best copy to use, with its full path;
- each job's **runs**, with how many files were new, changed or removed, and the **exact list of files** a run touched.

It's under **Tools → System Information → Backup Check**.

**Backup Check is read-only.** It never runs, changes or deletes a backup. It reads schedules, run records, logs and file dates.

## Where the information comes from

| Source | What it provides |
| --- | --- |
| The backup library's run records (`<appdata>/backup-check/runs/<job>/history.jsonl` and `reports/<job>.json`) | The job's own verdict for each run (ok / warning / failed), its file counts, and the list of files it touched |
| `/boot/config/plugins/user.scripts/schedule.json` + the root crontab | Each User Script's schedule, so expected runs (and missed ones) can be worked out |
| Each User Script's `name` file | The friendly name shown in User Scripts and Script Deck |
| Script Deck history (`<appdata>/script.deck/history/*/runs.json`) | Start/end time of User Script runs, kept across reboots |
| Appdata Backup plugin: `config.json`, its cron file, `ab_*` folders, `/tmp/appdata.backup/ab.log` | Schedule, one run per folder (`-failed` = failed), and whether the latest run finished (`DONE!`) |
| Completion stamps listed in the catalog | Last-known run for jobs with no other history |

Backup drives outside the array and pools are checked at most every 6 hours (or when you click Refresh), so sleeping drives stay asleep.

## Files and folders

| Path | Purpose |
| --- | --- |
| `/boot/config/plugins/backupcheck/catalog.json` | **Your catalog.** Edit this one. Created from `catalog.default.json` on first install, then never overwritten. |
| `<appdata>/backup-check/status.json` | The latest status the page shows (rebuilt every 30 min, or on Refresh) |
| `<appdata>/backup-check/ledger.json` | Backup Check's own run history |
| `<appdata>/backup-check/reports/<job>.json` | Each backup job's latest result (written by the backup library, or by `bk_report`) |
| `<appdata>/backup-check/runs/<job>/` | Each job's run history (`history.jsonl`) and the file list of every run (`<run>.files.gz`, 60 days) |

`<appdata>` is Docker's *Default appdata storage location*, resolved to its pool path (for example `/mnt/cache/appdata`). State is never written to the array; if appdata only exists on the array, it falls back to `/tmp/backup-check`.

## The catalog

```jsonc
{
  "version": 2,
  "locations": {                     // where copies land
    "pool": { "name": "Cachebackup", "kind": "ZFS pool (SSD)", "path": "/mnt/cachebackup",
              "note": "Shown on the Locations card.",
              "mount": false,           // true = warn when this path isn't mounted (Unassigned Devices drives)
              "capacity": true }        // false = don't measure free space (user shares, flash)
  },
  "jobs": {                          // things that make copies; the key is the job id
    "appdata-nodeletes-cachebackup": {
      "name": "Backup - Appdata - No Deletes - Cachebackup",
      "type": "userscript",           // or "appdata.backup" for the Appdata Backup plugin
      "script": "Backup - Appdata - No Deletes - Cachebackup",   // the User Scripts folder name
      "label": "No Deletes",          // the backup type shown on the cards
      "since": "2026-11-19",          // runs expected before this day are not counted as missed
      "stamps": ["/path/to/a/completion-stamp.log"],
      "paused": "Why it is paused",   // shown instead of a status while the job has no schedule
      "planned": true,                // a job that exists but is not scheduled yet
      "notes": [["warn", "Shown under the job and the data it backs up."]] }
  },
  "data": [                          // what is being protected
    { "id": "appdata", "name": "Appdata", "source": "/mnt/cache/appdata", "desc": "…",
      "restore": { "copy": 0, "why": "Why this copy is the one to restore from." },
      "copies": [
        { "job": "appdata-nodeletes-cachebackup", "tier": "daily", "location": "pool",
          "path": "/mnt/cachebackup/backups/appdata/daily/",
          "keeps": "Latest files, plus everything ever deleted",
          "reach": "deleted files: forever",   // the "How far back" line on the card
          "included": false,      // true = this data rides inside another copy (e.g. scripts inside the flash copy)
          "onlyDow": 0,           // optional: the job makes this copy only on Sundays (onlyDom = day of month)
          "latestFolder": false } // appdata.backup only: show the newest ab_* folder as the path
      ] }
  ]
}
```

- **The job key** matches the id the backup script reports under (the first argument of `backup_start` or `bk_report`).
- `tier` is `daily`, `weekly` or `monthly`: the group a copy is listed in.
- `notes` severity: `fail`, `warn` or `info`.
- `restore.copy` is the index, in `copies`, of the copy to recommend for a restore.

### Adding a new backup job

1. Add the job under `jobs` (for a User Script, `script` is its folder name).
2. Add a `copies` entry under each kind of data it backs up, with its location, path and `reach`. Add a `locations` entry if it writes somewhere new, or a `data` entry for a new kind of data.
3. Click **Refresh** on the page.

## The backup scripts (`scripts/`)

`scripts/` holds the server's backup scripts: one User Script per kind of data, per backup type, per drive (for example `Backup - Appdata - No Deletes - Cachebackup`). They all use `scripts/backup-lib.sh`, installed at `/boot/config/backup-scripts/backup-lib.sh`, which gives every script the same rules:

- one run of a job at a time, and a clear OK / WARNING / FAILED at the end, with an unRAID notification on problems;
- never writes to a drive that isn't mounted;
- the backup types: **No Deletes**, **No Deletes and Old Versions** (the previous version of a changed file is kept next to it as `name-vMMDDYY.ext`), **Exact Mirror**, **Database Dump** (a failed dump never replaces the last good one), **Dated Archive**;
- a **tripwire** that refuses to copy when far more files changed than usual;
- writes a run record and the list of files each run touched, which Backup Check reads.

The database password is **not** stored in this repository: the scripts hold the placeholder `__MARIADB_PASSWORD__`, filled in on the server when a script is installed. `scripts-superseded/` keeps scripts that were retired.

## Optional: report from any other script

Any script can report its own result with `bk_report`:

```bash
source /usr/local/emhttp/plugins/backupcheck/backup-status.sh
bk_report "my-job" "ok" "what happened"      # status: ok | warn | fail | skipped
```

## Releasing (Community Applications)

Every file the `.plg` downloads is pinned to the git tag `&version;` and checked against an MD5:

1. Bump `&version;` in `backupcheck.plg` and add a `###<version>` entry under `<CHANGES>`.
2. `php tools/update-md5.php`, then commit.
3. Tag that commit with the exact version (e.g. `2026.11.20`).
4. `php tools/update-md5.php <tag>` should say *no changes*, which proves the tag's bytes match.
5. Push `main` **and** the tag (a plain push does not send tags).

`&pluginURL;` stays on `main` so unRAID notices updates.
