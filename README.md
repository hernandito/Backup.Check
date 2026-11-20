# Backup Check

> **Built for one specific server.** This plugin, its catalog and its backup scripts are tailored to my own unRAID setup: its shares, drives and User Scripts. It is **not intended for general use**, and installing it on another server is not recommended.

A read-only unRAID dashboard (Tools → Backup Check) showing, for each kind of data, when it was last backed up, every copy and the drive it is on, how far back it can be restored, and each backup run's results and changed files. It never runs, changes or deletes a backup.

## Layout

| Path | What it is |
| --- | --- |
| `backupcheck.plg` | The plugin installer |
| `BackupCheck.*`, `api.php`, `include/` | The page and its data collector |
| `catalog.default.json` | Describes this server's data, backup jobs and drives |
| `scripts/` | This server's backup scripts and their shared library (`backup-lib.sh`) |
| `tools/update-md5.php` | Refreshes the file checksums in the `.plg` for a release |

The database password is not stored here: the MySQL scripts hold the placeholder `__MARIADB_PASSWORD__`, filled in on the server when a script is installed.

## Releasing

1. Bump `&version;` in `backupcheck.plg` and add a `<CHANGES>` entry.
2. `php tools/update-md5.php`, then commit.
3. Tag the commit with the exact version, then push `main` **and** the tag.
