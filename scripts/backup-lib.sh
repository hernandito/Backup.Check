#!/bin/bash
# =============================================================================
#  Backup library - shared by every "Backup - ..." User Script.
#
#  Installed at:  /boot/config/backup-scripts/backup-lib.sh
#  Each backup script starts with:
#
#     source /boot/config/backup-scripts/backup-lib.sh
#     backup_start "appdata-nodeletes-pool" "Appdata - No Deletes - Pool"
#     ...one or more steps...
#     backup_finish
#
#  It gives every script the same safety rules:
#    - one copy of a job at a time (a lock), and a clear OK / WARNING / FAILED at the end
#    - never writes to a drive that isn't mounted (so nothing lands in RAM by mistake)
#    - three plain rsync styles: No Deletes, No Deletes and Old Versions, Exact Mirror
#    - a tripwire that refuses to copy when far more files changed than usual
#    - a list of every file each run added, changed or removed, kept for 60 days
#    - an unRAID notification when something goes wrong, and a status file for Backup Check
#
#  It never deletes anything from a backup except in an Exact Mirror (that is what a mirror is).
#  It does not touch shares, permissions, users or passwords.
# =============================================================================

BACKUP_LIB_VERSION=1
BACKUP_NOTIFY="${BACKUP_NOTIFY:-/usr/local/emhttp/webGui/scripts/notify}"   # tests set this to /bin/true
BACKUP_RUN_LOG_DAYS=60          # how long the per-run file lists are kept

# ------------------------------------------------------------------ state dir
# Backup Check's state folder: Docker's appdata location, resolved to its pool
# (never the array, so no disk is kept spinning). Falls back to /tmp.
_backup_state_dir() {
  local base rest m
  if [ -n "$BACKUP_STATE_DIR" ]; then echo "$BACKUP_STATE_DIR"; return; fi   # override (used for testing)
  base=$(sed -n 's/^DOCKER_APP_CONFIG_PATH="\{0,1\}\([^"]*\)"\{0,1\}$/\1/p' /boot/config/docker.cfg 2>/dev/null)
  base="${base:-/mnt/user/appdata/}"; base="${base%/}"
  if [[ "$base" == /mnt/user/* ]]; then
    rest="${base#/mnt/user/}"
    for m in /mnt/*/; do
      m="${m%/}"
      case "$(basename "$m")" in user|user0|disks|remotes|rootshare|addons|disk[0-9]*) continue ;; esac
      if [ -d "$m/$rest" ]; then base="$m/$rest"; break; fi
    done
  fi
  if [[ "$base" == /mnt/user/* || "$base" =~ ^/mnt/disk[0-9]+ ]]; then echo "/tmp/backup-check"; else echo "$base/backup-check"; fi
}

# ------------------------------------------------------------------- output
_backup_ts()   { date '+%H:%M:%S'; }
backup_say()   { echo "$(_backup_ts)  $*"; }
backup_ok()    { echo "$(_backup_ts)  OK       $*"; }
backup_note()  { echo "$(_backup_ts)  NOTE     $*"; }
backup_warn()  { echo "$(_backup_ts)  WARNING  $*"; BACKUP_WARNINGS+=("$*"); }
backup_error() { echo "$(_backup_ts)  ERROR    $*"; BACKUP_ERRORS+=("$*"); }

_backup_json() {   # escape text for JSON
  local s="$1"
  s="${s//\\/\\\\}"; s="${s//\"/\\\"}"; s="${s//$'\n'/ }"; s="${s//$'\r'/}"; s="${s//$'\t'/ }"
  printf '%s' "$s"
}

# ------------------------------------------------------------------ start/finish
# backup_start <job-id> "<title>"
#   job-id: lower-case letters, digits and dashes; it is the job's key in Backup Check.
backup_start() {
  BACKUP_JOB=$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9_-')
  BACKUP_TITLE="${2:-$1}"
  BACKUP_STARTED=$(date +%s)
  BACKUP_WARNINGS=(); BACKUP_ERRORS=(); BACKUP_STEPS=()
  BACKUP_NEW=0; BACKUP_CHANGED=0; BACKUP_REMOVED=0; BACKUP_VERSIONED=0
  BACKUP_FINISHED=0
  BACKUP_STATE="$(_backup_state_dir)"
  BACKUP_RUN_DIR="$BACKUP_STATE/runs/$BACKUP_JOB"
  BACKUP_RUN_ID=$(date +%Y%m%d-%H%M%S)
  BACKUP_FILELIST="$BACKUP_RUN_DIR/$BACKUP_RUN_ID.files"
  mkdir -p "$BACKUP_RUN_DIR" "$BACKUP_STATE/reports" 2>/dev/null
  : > "$BACKUP_FILELIST" 2>/dev/null

  # one run of this job at a time
  exec 8>"/tmp/backup-$BACKUP_JOB.lock"
  if ! flock -n 8; then
    echo "$(_backup_ts)  Another run of \"$BACKUP_TITLE\" is still going - this run is skipped."
    BACKUP_FINISHED=1
    exit 0
  fi
  trap '_backup_on_exit $?' EXIT

  echo "=================================================================="
  echo " $BACKUP_TITLE"
  echo " Started $(date '+%a %b %-d %Y, %H:%M:%S')  (job: $BACKUP_JOB)"
  echo "=================================================================="
}

# If the script stops early (an error, or a step called backup_abort), still report it.
_backup_on_exit() {
  local rc="$1"
  [ "$BACKUP_FINISHED" = 1 ] && return
  BACKUP_ERRORS+=("The script stopped before finishing (exit code $rc).")
  backup_finish
}

# backup_abort "<reason>"   stop now and report FAILED
backup_abort() {
  backup_error "$1"
  backup_finish
  exit 1
}

# backup_finish   print the summary, write the status for Backup Check, notify on problems
backup_finish() {
  [ "$BACKUP_FINISHED" = 1 ] && return
  BACKUP_FINISHED=1
  local status="ok" detail secs icon
  secs=$(( $(date +%s) - BACKUP_STARTED ))
  if [ ${#BACKUP_ERRORS[@]} -gt 0 ]; then status="fail"; elif [ ${#BACKUP_WARNINGS[@]} -gt 0 ]; then status="warn"; fi
  detail="$BACKUP_NEW new, $BACKUP_CHANGED changed, $BACKUP_REMOVED removed"
  [ "$BACKUP_VERSIONED" -gt 0 ] && detail="$detail, $BACKUP_VERSIONED old versions saved"
  [ "$status" = "fail" ] && detail="${BACKUP_ERRORS[0]}"
  [ "$status" = "warn" ] && detail="${BACKUP_WARNINGS[0]} ($detail)"

  echo "------------------------------------------------------------------"
  case "$status" in
    ok)   echo " RESULT: OK" ;;
    warn) echo " RESULT: WARNING"; printf '   - %s\n' "${BACKUP_WARNINGS[@]}" ;;
    fail) echo " RESULT: FAILED";  printf '   - %s\n' "${BACKUP_ERRORS[@]}" ;;
  esac
  echo " Files: $BACKUP_NEW new, $BACKUP_CHANGED changed, $BACKUP_REMOVED removed, $BACKUP_VERSIONED old versions saved"
  printf ' Took: %dh %dm %ds\n' $((secs/3600)) $(((secs%3600)/60)) $((secs%60))
  echo "------------------------------------------------------------------"

  # keep the list of files this run touched (compressed)
  local haslist=false
  if [ -s "$BACKUP_FILELIST" ]; then gzip -f "$BACKUP_FILELIST" 2>/dev/null && haslist=true; else rm -f "$BACKUP_FILELIST"; fi

  # status for Backup Check: the latest run (reports/<job>.json, same format as its bk_report)
  # and one line per run in runs/<job>/history.jsonl (the last 400 runs are kept)
  local steps="" s line dry=false
  [ "$BACKUP_DRY_RUN" = 1 ] && dry=true
  for s in "${BACKUP_STEPS[@]}"; do steps="$steps${steps:+,}$s"; done
  line=$(printf '{"job":"%s","title":"%s","run":"%s","status":"%s","detail":"%s","bytes":null,"epoch":%s,"time":"%s","started":%s,"seconds":%s,"files":{"new":%s,"changed":%s,"removed":%s,"versioned":%s},"steps":[%s],"filelist":%s,"dryrun":%s}' \
    "$BACKUP_JOB" "$(_backup_json "$BACKUP_TITLE")" "$BACKUP_RUN_ID" "$status" "$(_backup_json "$detail")" "$(date +%s)" "$(date '+%Y-%m-%d %H:%M:%S')" \
    "$BACKUP_STARTED" "$secs" "$BACKUP_NEW" "$BACKUP_CHANGED" "$BACKUP_REMOVED" "$BACKUP_VERSIONED" "$steps" "$haslist" "$dry")
  echo "$line" > "$BACKUP_STATE/reports/$BACKUP_JOB.json.tmp" 2>/dev/null && mv -f "$BACKUP_STATE/reports/$BACKUP_JOB.json.tmp" "$BACKUP_STATE/reports/$BACKUP_JOB.json"
  echo "$line" >> "$BACKUP_RUN_DIR/history.jsonl" 2>/dev/null
  if [ "$(wc -l < "$BACKUP_RUN_DIR/history.jsonl" 2>/dev/null || echo 0)" -gt 450 ]; then
    tail -n 400 "$BACKUP_RUN_DIR/history.jsonl" > "$BACKUP_RUN_DIR/history.jsonl.tmp" && mv -f "$BACKUP_RUN_DIR/history.jsonl.tmp" "$BACKUP_RUN_DIR/history.jsonl"
  fi

  # prune old file lists
  find "$BACKUP_RUN_DIR" -maxdepth 1 -type f -name '*.files.gz' -mtime +"$BACKUP_RUN_LOG_DAYS" -delete 2>/dev/null

  # tell unRAID when something is wrong (quiet when all is well)
  if [ "$status" != "ok" ] && [ -x "$BACKUP_NOTIFY" ]; then
    icon="warning"; [ "$status" = "fail" ] && icon="alert"
    "$BACKUP_NOTIFY" -e "Backup" -s "Backup: $BACKUP_TITLE - $( [ "$status" = fail ] && echo FAILED || echo WARNING )" -d "$detail" -i "$icon" >/dev/null 2>&1
  fi
  [ "$status" = "fail" ] && return 1 || return 0
}

# ------------------------------------------------------------------ checks
# backup_need_mounted <path>   the path must be on a real, mounted drive (not the RAM root, not a stub)
backup_need_mounted() {
  local p="$1" target fstype
  [ -e "$p" ] || { backup_abort "$p does not exist - is the drive mounted?"; }
  target=$(findmnt -n -o TARGET --target "$p" 2>/dev/null)
  fstype=$(findmnt -n -o FSTYPE --target "$p" 2>/dev/null)
  if [ -z "$target" ] || [ "$target" = "/" ] || [ "$fstype" = "tmpfs" ] || [ "$fstype" = "rootfs" ]; then
    backup_abort "$p is not on a mounted drive (found: ${target:-nothing}, ${fstype:-?}). Nothing was copied, so nothing could land in RAM."
  fi
  backup_ok "Drive is mounted: $target ($fstype), $(df -h --output=avail "$target" | tail -1 | tr -d ' ') free"
}

# backup_is_mounted <path>   true if the path is on a real, mounted drive (checks only; never stops the run)
backup_is_mounted() {
  local p="$1" target fstype
  [ -e "$p" ] || return 1
  target=$(findmnt -n -o TARGET --target "$p" 2>/dev/null)
  fstype=$(findmnt -n -o FSTYPE --target "$p" 2>/dev/null)
  [ -n "$target" ] && [ "$target" != "/" ] && [ "$fstype" != "tmpfs" ] && [ "$fstype" != "rootfs" ]
}

# backup_need_source <dir>   the source must exist and not be empty (protects backups from an empty or missing source)
backup_need_source() {
  local p="$1"
  if [ ! -d "$p" ] || [ -z "$(ls -A "$p" 2>/dev/null)" ]; then
    backup_abort "The source $p is missing or empty - nothing was copied, to protect the backup."
  fi
}

# ------------------------------------------------------------------ rsync styles
# Options shared by all styles:  -a keeps dates, links and permissions; -H keeps hard links.
# There is no --update: a file restored to an older version on the server is copied again.
BACKUP_RSYNC_BASE=(-aH --numeric-ids --human-readable --itemize-changes --stats)
# Drives formatted NTFS (readable on Windows: FilesBackup and NTFS-Backup) can't hold Linux owners
# and permissions, so copy contents and file times only. --omit-dir-times leaves folder times alone;
# otherwise rsync "updates" every folder on every run (~100,000 for Nextcloud) while copying nothing.
BACKUP_RSYNC_NTFS=(-rlt -H --omit-dir-times --modify-window=1 --no-perms --no-owner --no-group --no-specials --no-devices --human-readable --itemize-changes --stats)

# Count what an rsync run did, from its --itemize-changes output, and add it to the file list.
# Sets TALLY_NEW / TALLY_CHANGED / TALLY_REMOVED and adds them to the run totals.
# (Called directly, never in $( ), so the totals survive.)
_backup_tally() {   # $1 = file with rsync output, $2 = step name
  local out="$1"
  TALLY_NEW=$(grep -cE '^[<>]f\+{9}' "$out")
  TALLY_CHANGED=$(grep -cE '^[<>]f[^+]' "$out")
  TALLY_REMOVED=$(grep -cE '^\*deleting ' "$out")
  BACKUP_NEW=$((BACKUP_NEW + TALLY_NEW)); BACKUP_CHANGED=$((BACKUP_CHANGED + TALLY_CHANGED)); BACKUP_REMOVED=$((BACKUP_REMOVED + TALLY_REMOVED))
  { echo "### $2  ($(date '+%F %T'))"; grep -E '^([<>]f|\*deleting|cd\+)' "$out"; } >> "$BACKUP_FILELIST" 2>/dev/null
}

# _backup_rsync <style> <step name> <src> <dst> [extra rsync options...]
_backup_rsync() {
  local style="$1" name="$2" src="$3" dst="$4"; shift 4
  local opts out rc n c r
  case "$style" in
    ntfs) opts=("${BACKUP_RSYNC_NTFS[@]}") ;;
    *)    opts=("${BACKUP_RSYNC_BASE[@]}") ;;
  esac
  out=$(mktemp /tmp/backup-rsync.XXXXXX)
  mkdir -p "$dst" || { backup_error "$name: could not create $dst"; rm -f "$out"; return 1; }
  # The backup folder itself keeps the owner and permissions it already has: copying "src/"
  # would otherwise give it the source folder's settings. Noted here, put back after the copy.
  local keep_owner keep_mode
  keep_owner=$(stat -c '%u:%g' "$dst" 2>/dev/null); keep_mode=$(stat -c '%a' "$dst" 2>/dev/null)
  backup_say "$name: $src  ->  $dst"
  /usr/bin/rsync "${opts[@]}" "$@" "$src" "$dst" > "$out" 2>&1
  rc=$?
  if [ -n "$keep_owner" ] && [ "$(stat -c '%u:%g %a' "$dst" 2>/dev/null)" != "$keep_owner $keep_mode" ]; then
    chown "$keep_owner" "$dst" 2>/dev/null; chmod "$keep_mode" "$dst" 2>/dev/null
  fi
  _backup_tally "$out" "$name"
  n=$TALLY_NEW; c=$TALLY_CHANGED; r=$TALLY_REMOVED
  grep -E '^(Number of (regular )?files transferred|Total transferred file size|Total file size)' "$out" | sed 's/^/           /'
  if [ $rc -eq 0 ]; then
    backup_ok "$name: $n new, $c changed, $r removed"
    BACKUP_STEPS+=("{\"name\":\"$(_backup_json "$name")\",\"status\":\"ok\",\"new\":$n,\"changed\":$c,\"removed\":$r}")
  elif [ $rc -eq 24 ]; then   # files vanished while copying: normal for live data
    backup_ok "$name: $n new, $c changed, $r removed (some files disappeared while copying - normal)"
    BACKUP_STEPS+=("{\"name\":\"$(_backup_json "$name")\",\"status\":\"ok\",\"new\":$n,\"changed\":$c,\"removed\":$r}")
  elif [ $rc -eq 23 ]; then   # some files couldn't be read
    backup_warn "$name: some files could not be copied (rsync code 23): $(grep -m1 -E 'rsync: |failed' "$out")"
    BACKUP_STEPS+=("{\"name\":\"$(_backup_json "$name")\",\"status\":\"warn\",\"new\":$n,\"changed\":$c,\"removed\":$r}")
  else
    backup_error "$name: rsync failed (code $rc): $(grep -m1 -E 'rsync( error)?:' "$out")"
    BACKUP_STEPS+=("{\"name\":\"$(_backup_json "$name")\",\"status\":\"fail\",\"new\":$n,\"changed\":$c,\"removed\":$r}")
  fi
  rm -f "$out"
  [ $rc -eq 0 ] || [ $rc -eq 24 ]
}

# backup_tripwire <style> <src> <dst> <max changed+removed> [extra rsync options...]
#   A dry run first: if more existing files would be CHANGED or REMOVED than <max>, stop without
#   copying anything. New files never trip it (adding files can't damage a backup).
backup_tripwire() {
  local style="$1" src="$2" dst="$3" max="$4"; shift 4
  local opts out c r
  [ -d "$dst" ] || { backup_note "Tripwire: first copy into $dst, nothing to compare yet."; return 0; }
  case "$style" in ntfs) opts=("${BACKUP_RSYNC_NTFS[@]}") ;; *) opts=("${BACKUP_RSYNC_BASE[@]}") ;; esac
  out=$(mktemp /tmp/backup-trip.XXXXXX)
  /usr/bin/rsync "${opts[@]}" --dry-run "$@" "$src" "$dst" > "$out" 2>&1
  c=$(grep -cE '^[<>]f[^+]' "$out"); r=$(grep -cE '^\*deleting ' "$out")
  if [ $((c + r)) -gt "$max" ]; then
    { echo "### TRIPWIRE - would have changed $c and removed $r files (limit $max). Nothing was copied."; grep -E '^([<>]f[^+]|\*deleting)' "$out" | head -500; } >> "$BACKUP_FILELIST"
    rm -f "$out"
    backup_abort "Tripwire: $c files would be changed and $r removed, more than the limit of $max. Nothing was copied. If this was you (a big reorganise), raise the limit for one run; if not, check the source for damage or ransomware."
  fi
  rm -f "$out"
  backup_ok "Tripwire passed: $c files would change, $r would be removed (limit $max)"
}

# backup_nodeletes <step name> <src/> <dst/> [extra rsync options...]
#   Adds new files and updates changed ones. Never removes anything from the backup.
backup_nodeletes() { _backup_rsync linux "$@"; }

# backup_nodeletes_ntfs <step name> <src/> <dst/> [extra...]   same, for a Windows-readable NTFS drive
backup_nodeletes_ntfs() { _backup_rsync ntfs "$@"; }

# backup_versions <step name> <src/> <dst/> [extra...]
#   No Deletes and Old Versions: like No Deletes, but before a file in the backup is overwritten,
#   its previous version is kept RIGHT NEXT TO IT, with the date that version was saved
#   (MMDDYY) added before the extension:
#       My Resume 2024.docx            <- current version
#       My Resume 2024-v110626.docx    <- the version as it was on Nov 6, 2026
#   If that name is taken, the time is added (-v110626-1432), then a counter.
#   How: rsync moves each replaced file into a hidden staging folder beside the backup
#   (".<backup name>.replaced", same drive), then each one is renamed into place here.
backup_versions()      { _backup_versions linux "$@"; }
backup_versions_ntfs() { _backup_versions ntfs  "$@"; }

_backup_versions() {
  local style="$1" name="$2" src="$3" dst="$4"; shift 4
  local stage rc nv=0 f rel dir base stem ext d t new n
  stage="$(dirname "${dst%/}")/.$(basename "${dst%/}").replaced"
  _backup_rsync "$style" "$name" "$src" "$dst" --backup --backup-dir="$stage" "$@"
  rc=$?
  if [ -d "$stage" ]; then
    while IFS= read -r -d '' f; do
      rel="${f#$stage/}"
      dir="$(dirname "${dst%/}/$rel")"
      base="$(basename "$f")"
      if [[ "$base" == *.* && "$base" != .* ]]; then stem="${base%.*}"; ext=".${base##*.}"; else stem="$base"; ext=""; fi
      d=$(date -r "$f" +%m%d%y); t=$(date -r "$f" +%H%M)
      new="$dir/$stem-v$d$ext"
      [ -e "$new" ] && new="$dir/$stem-v$d-$t$ext"
      n=2; while [ -e "$new" ]; do new="$dir/$stem-v$d-$t-$n$ext"; n=$((n + 1)); done
      mkdir -p "$dir" && mv -n "$f" "$new" && nv=$((nv + 1)) && echo "kept old version: ${new#${dst%/}/}" >> "$BACKUP_FILELIST"
    done < <(find "$stage" -type f -print0)
    # The staging folder should now be empty; remove it (only empty folders are removed).
    find "$stage" -depth -type d -empty -delete 2>/dev/null
    if [ -d "$stage" ]; then backup_warn "$name: some replaced files could not be renamed into place; they are in $stage"; fi
    BACKUP_VERSIONED=$((BACKUP_VERSIONED + nv))
    [ "$nv" -gt 0 ] && backup_ok "$name: $nv old versions kept next to their current files (name-vMMDDYY)"
  fi
  return $rc
}

# backup_mirror <step name> <src/> <dst/> [extra...]
#   Exact Mirror: the backup becomes an exact copy of the source, so files deleted on the
#   server are removed here too. Use only where a clean "as of today" copy is the point.
backup_mirror() { local name="$1" src="$2" dst="$3"; shift 3; _backup_rsync linux "$name" "$src" "$dst" --delete "$@"; }
backup_mirror_ntfs() { local name="$1" src="$2" dst="$3"; shift 3; _backup_rsync ntfs "$name" "$src" "$dst" --delete "$@"; }

# ------------------------------------------------------------------ database dumps
# backup_mysqldump <container> <user> <password> <final .sql file> [dated .gz copy] [min size %]
#   Dumps every database to a temporary file and only replaces <final> once the dump is complete
#   ("Dump completed" at the end). If the new dump is smaller than [min size %] (default 50) of the
#   previous one, the previous dump is kept and the new one is only saved as the dated copy, with a
#   warning. The final file keeps the owner and permissions the previous one had.
backup_mysqldump() {
  local ctr="$1" user="$2" pass="$3" final="$4" dated="${5:-}" ratio="${6:-50}"
  local tmp="$final.partial" rc new_size old_size new_h
  mkdir -p "$(dirname "$final")"
  if ! docker ps --format '{{.Names}}' 2>/dev/null | grep -Fxq "$ctr"; then
    backup_error "The $ctr container is not running, so no dump was made. The previous dump was kept."; return 1
  fi
  backup_say "Dumping all databases from the $ctr container..."
  docker exec "$ctr" sh -c "exec mysqldump --all-databases --single-transaction --routines --events -u$user -p$pass" > "$tmp" 2> /tmp/backup-mysqldump.err
  rc=$?
  if [ $rc -ne 0 ]; then
    rm -f "$tmp"; backup_error "mysqldump failed (code $rc): $(grep -v -i password /tmp/backup-mysqldump.err | tail -1). The previous dump was kept."; return 1
  fi
  if ! tail -c 200 "$tmp" | grep -q "Dump completed"; then
    rm -f "$tmp"; backup_error "The dump is incomplete (no 'Dump completed' line at the end). The previous dump was kept."; return 1
  fi
  new_size=$(stat -c %s "$tmp"); old_size=$(stat -c %s "$final" 2>/dev/null || echo 0); new_h=$(du -h "$tmp" | cut -f1)
  if [ "$old_size" -gt 0 ] && [ $((new_size * 100)) -lt $((old_size * ratio)) ]; then
    if [ -n "$dated" ]; then gzip -c "$tmp" > "$dated"; fi
    rm -f "$tmp"
    backup_warn "The new dump ($new_h) is less than ${ratio}% of the previous one ($(du -h "$final" | cut -f1)). The previous dump was kept${dated:+; the new one is saved as $(basename "$dated") for checking}."
    return 0
  fi
  [ -f "$final" ] && { chown --reference="$final" "$tmp" 2>/dev/null; chmod --reference="$final" "$tmp" 2>/dev/null; }
  mv -f "$tmp" "$final"
  backup_ok "Dump saved: $final ($new_h)"
  BACKUP_STEPS+=("{\"name\":\"Database dump\",\"status\":\"ok\",\"bytes\":$new_size}")
  if [ -n "$dated" ]; then
    if gzip -c "$final" > "$dated"; then
      chown --reference="$final" "$dated" 2>/dev/null; chmod --reference="$final" "$dated" 2>/dev/null
      backup_ok "Dated copy: $(basename "$dated") ($(du -h "$dated" | cut -f1))"
    else
      backup_warn "Could not write the dated copy $(basename "$dated")"
    fi
  fi
  return 0
}

# ------------------------------------------------------------------ small helpers
# backup_write_atomic <final file> <command...>   run a command writing to a temp file; only replace the final file if it succeeded and isn't empty
backup_write_atomic() {
  local final="$1"; shift
  local tmp="$final.partial"
  if "$@" > "$tmp" 2>/dev/null && [ -s "$tmp" ]; then mv -f "$tmp" "$final"; return 0; fi
  rm -f "$tmp"; return 1
}

# backup_prune <dir> <name pattern> <keep N newest>   remove older dated items beyond the newest N (used for dated archives/reports)
backup_prune() {
  local dir="$1" pat="$2" keep="$3"
  [ -d "$dir" ] || return 0
  ls -1d "$dir"/$pat 2>/dev/null | sort -r | tail -n +$((keep + 1)) | while read -r old; do rm -rf -- "$old"; done
}
