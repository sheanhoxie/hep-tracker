#!/usr/bin/env bash
# Nightly encrypted off-box backup to R2 (DEPLOY.md F1). Root cron.
# Config: /etc/hep-backup.env defines RCLONE_REMOTE (e.g. r2:hep-backups), HC_URL.
# Passphrase: /root/.hep-backup-passphrase — ALSO in the password manager;
# without it every backup is unreadable.
set -euo pipefail

CONF=/etc/hep-backup.env
PASSFILE=/root/.hep-backup-passphrase
MIN_BYTES=10000   # calibrated at install: fresh-seed dump measured, threshold set below it
LOG=/var/log/hep-backup.log
# GnuPG 2.4: --passphrase-file is IGNORED without loopback pinentry; headless
# cron then hangs on the agent and every backup silently fails
GPG=(gpg --batch --pinentry-mode loopback --passphrase-file "$PASSFILE")

log() { echo "$(date -u +%FT%TZ) $*" >> "$LOG"; }

# trap before config load so a broken config still alerts (when HC_URL is known)
HC_URL=""
RCLONE_REMOTE=""
DB_OBJ=""
on_error() {
    log "FAILED at line $1"
    # a mid-stream pg_dump/gpg death can leave a plausible-looking partial object
    # at the canonical name — the restore drill would pick it as "latest"
    [ -n "$DB_OBJ" ] && rclone deletefile "$DB_OBJ" > /dev/null 2>&1 || true
    [ -n "$HC_URL" ] && curl -fsS -m 10 --retry 3 "$HC_URL/fail" > /dev/null 2>&1 || true
}
trap 'on_error $LINENO' ERR

[ -f "$CONF" ] || { log "UNCONFIGURED: $CONF missing — no backup taken"; false; }
# shellcheck source=/dev/null
source "$CONF"
[ -n "$RCLONE_REMOTE" ] && [ -n "$HC_URL" ] || { log "bad config: RCLONE_REMOTE/HC_URL unset"; false; }

exec 9> /run/hep-backup.lock
flock -n 9 || { log "another run in progress — skipping"; exit 0; }

TS=$(date -u +%Y%m%d-%H%M%S)
DB_OBJ="$RCLONE_REMOTE/db/hep_production-$TS.dump.gpg"
ENV_OBJ="$RCLONE_REMOTE/env/env-$TS.gpg"

# stream: never staged on local disk (one-box SPOF — DEPLOY.md F1/F4)
sudo -u postgres pg_dump -Fc hep_production \
    | "${GPG[@]}" --symmetric --cipher-algo AES256 \
    | rclone rcat "$DB_OBJ"

# size sanity: a truncated/empty dump must not count as success
BYTES=$(rclone size --json "$DB_OBJ" | jq -r .bytes)
[ "$BYTES" -ge "$MIN_BYTES" ] || { log "dump only $BYTES bytes (< $MIN_BYTES)"; false; }

"${GPG[@]}" --symmetric --cipher-algo AES256 --output - /var/www/hep/shared/.env \
    | rclone rcat "$ENV_OBJ"

# retention: 30d dumps, 90d env history
rclone delete --min-age 30d "$RCLONE_REMOTE/db/" 2>> "$LOG" || true
rclone delete --min-age 90d "$RCLONE_REMOTE/env/" 2>> "$LOG" || true

# success: object is good — stop the trap from deleting it if the ping errors,
# and tolerate an unreachable healthchecks.io (its dead-man alarm covers that)
DB_OBJ=""
curl -fsS -m 10 --retry 3 "$HC_URL" > /dev/null || log "success ping failed (dead-man will alert)"
log "OK db=$BYTES bytes"
