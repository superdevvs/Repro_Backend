#!/bin/sh
# Run as the deployment account after publishing the revision-aware backend.
# Only installs this provider check; it does not enable unrelated Laravel schedules.
set -eu

app=/var/www/backend
state="$HOME/.local/share/repro-cubicasa-revisions"
test -f "$app/artisan"
test -x /usr/local/sbin/repro-storage-guard
command -v crontab >/dev/null
command -v flock >/dev/null
command -v sg >/dev/null
mkdir -p "$state"
chmod 700 "$state"
previous=$(mktemp "$state/crontab-before.XXXXXX")
if crontab -l > "$previous" 2> "$state/crontab-read-error.log"; then
    :
elif grep -q 'no crontab for' "$state/crontab-read-error.log"; then
    : > "$previous"
else
    echo 'Could not read the existing crontab; nothing changed.' >&2
    exit 1
fi
next=$(mktemp "$state/crontab-next.XXXXXX")
# Preserve every unrelated entry and retain the backup for rollback.
awk '!/# repro-cubicasa-revision-poll$/' "$previous" > "$next"
printf '%s\n' "7,37 * * * * /usr/local/sbin/repro-storage-guard --live && /usr/bin/flock -n $state/poll.lock /usr/bin/sg www-data -c 'umask 0002; cd $app && /usr/bin/php artisan cubicasa:refresh-deliveries --limit=25' >> $state/poll.log 2>&1 # repro-cubicasa-revision-poll" >> "$next"
crontab "$next"
test "$(crontab -l | grep -c '# repro-cubicasa-revision-poll$')" = 1
echo 'CubiCasa revision polling installed; previous crontab retained.'
