#!/bin/bash
# =============================================================================
#  Backup Check - optional status helper for backup scripts.
#
#  Backup Check works without this: it reads schedules, run history and stamps.
#  Sourcing this file lets a script report its OWN result, which turns
#  "it ran" into "it ran and succeeded". Add near the end of a backup script:
#
#     source /usr/local/emhttp/plugins/backupcheck/backup-status.sh
#     bk_report "<job id>" "<ok|warn|fail|skipped>" "<short detail>" [bytes]
#
#  <job id> is the job's key in the catalog (e.g. "abk", "ntfs", "mysqlw").
#  Writes one small JSON file per job into Backup Check's state folder on the
#  cache pool (never the array, never the flash drive).
# =============================================================================

bk__state_dir() {
  local base
  base=$(sed -n 's/^DOCKER_APP_CONFIG_PATH="\{0,1\}\([^"]*\)"\{0,1\}$/\1/p' /boot/config/docker.cfg 2>/dev/null)
  base="${base:-/mnt/user/appdata/}"
  base="${base%/}"
  if [[ "$base" == /mnt/user/* ]]; then             # resolve the share to its pool, so the array stays asleep
    local rest="${base#/mnt/user/}" m
    for m in /mnt/*/; do
      m="${m%/}"
      case "$(basename "$m")" in user|user0|disks|remotes|rootshare|addons|disk[0-9]*) continue ;; esac
      if [ -d "$m/$rest" ]; then base="$m/$rest"; break; fi
    done
  fi
  if [[ "$base" == /mnt/user/* || "$base" =~ ^/mnt/disk[0-9]+ ]]; then
    echo "/tmp/backup-check"
  else
    echo "$base/backup-check"
  fi
}

bk__json() {   # escape a string for JSON
  local s="$1"
  s="${s//\\/\\\\}"; s="${s//\"/\\\"}"; s="${s//$'\n'/ }"; s="${s//$'\r'/}"; s="${s//$'\t'/ }"
  printf '%s' "$s"
}

bk_report() {
  local id="$1" status="$2" detail="${3:-}" bytes="${4:-}"
  case "$status" in ok|warn|fail|skipped) ;; *) echo "bk_report: status must be ok|warn|fail|skipped" >&2; return 1 ;; esac
  id=$(printf '%s' "$id" | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9_-')
  [ -n "$id" ] || { echo "bk_report: missing job id" >&2; return 1; }
  local dir now
  dir="$(bk__state_dir)/reports"
  mkdir -p "$dir" 2>/dev/null || return 1
  now=$(date +%s)
  [[ "$bytes" =~ ^[0-9]+$ ]] || bytes=null
  printf '{"job":"%s","status":"%s","detail":"%s","bytes":%s,"epoch":%s,"time":"%s"}\n' \
    "$id" "$status" "$(bk__json "$detail")" "$bytes" "$now" "$(date '+%Y-%m-%d %H:%M:%S')" \
    > "$dir/$id.json.tmp" && mv -f "$dir/$id.json.tmp" "$dir/$id.json"
}
