#!/usr/bin/env bash
# Restore drill (DEPLOY.md F2): pull the newest dump from R2, restore into a
# scratch DB, assert the data is real, drop it. Run as root, monthly —
# an untested backup is a hypothesis. Pings $HC_RESTORE_URL if configured.
set -euo pipefail

CONF=/etc/hep-backup.env
PASSFILE=/root/.hep-backup-passphrase
SCRATCH=hep_restore_test

HC_RESTORE_URL=""
# shellcheck source=/dev/null
source "$CONF"   # RCLONE_REMOTE, optional HC_RESTORE_URL

fail() {
    echo "RESTORE TEST FAILED: $*" >&2
    [ -n "$HC_RESTORE_URL" ] && curl -fsS -m 10 "$HC_RESTORE_URL/fail" > /dev/null 2>&1 || true
    exit 1
}
trap 'fail "line $LINENO"' ERR

# UTC timestamps in names: lexicographic sort == chronological
LATEST=$(rclone lsf "$RCLONE_REMOTE/db/" --files-only | sort | tail -1)
[ -n "$LATEST" ] || fail "no backups found in $RCLONE_REMOTE/db/"
echo "restoring: $LATEST"

sudo -u postgres dropdb --force --if-exists "$SCRATCH"
sudo -u postgres createdb "$SCRATCH"
cleanup() { sudo -u postgres dropdb --force --if-exists "$SCRATCH"; }
trap cleanup EXIT

# NEVER add -j: stdin custom-format archives lack data offsets; parallel
# restore requires a regular file
rclone cat "$RCLONE_REMOTE/db/$LATEST" \
    | gpg --batch --pinentry-mode loopback --decrypt --passphrase-file "$PASSFILE" \
    | sudo -u postgres pg_restore --dbname="$SCRATCH" --no-owner --exit-on-error

EX=$(sudo -u postgres psql -tA "$SCRATCH" -c "SELECT count(*) FROM exercises")
SE=$(sudo -u postgres psql -tA "$SCRATCH" -c "SELECT count(*) FROM set_events")
VIEW=$(sudo -u postgres psql -tA "$SCRATCH" -c "SELECT count(*) FROM v_weekly_completions")

echo "exercises: $EX (expect 22)  set_events: $SE  v_weekly_completions rows: $VIEW"
[ "$EX" -eq 22 ] || fail "exercise count $EX != 22"
[ -n "$HC_RESTORE_URL" ] && curl -fsS -m 10 "$HC_RESTORE_URL" > /dev/null 2>&1 || true
echo "RESTORE TEST OK"
