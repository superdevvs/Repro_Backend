#!/usr/bin/env bash
# Run as the existing deployment account. No root access or system worker changes.
set -Eeuo pipefail

app="${STUDIO_APP_DIR:-/var/www/backend}"
runtime="$HOME/.local/share/repro-studio"
prepare_only="${1:-}"
test "$app" = /var/www/backend || { echo 'Unexpected production app directory' >&2; exit 1; }
test -d "$app"
test "$(uname -m)" = x86_64 || { echo 'This runtime supports x86_64 Ubuntu hosts.' >&2; exit 1; }
for binary in apt-get dpkg-deb supervisord supervisorctl crontab flock sg; do command -v "$binary" >/dev/null; done
id -nG | tr ' ' '\n' | grep -qx www-data
umask 002
mkdir -p "$runtime" "$runtime/packages" "$runtime/root" "$runtime/bin" "$runtime/logs"

# Ubuntu's configured, authenticated APT indexes determine exact package versions.
# Download and extract only; no host package database or system files are changed.
if [ ! -x "$runtime/bin/ffmpeg" ] || ! "$runtime/bin/ffmpeg" -version >/dev/null 2>&1; then
  mapfile -t packages < <(apt-get -s install --no-install-recommends ffmpeg | awk '/^Inst / {name=$2; sub(/^[^(]*\(/, ""); split($0, parts, " "); print name "=" parts[1]}')
  if [ "${#packages[@]}" -eq 0 ]; then packages=(ffmpeg); fi
  (cd "$runtime/packages" && apt-get download "${packages[@]}")
  for package in "$runtime/packages"/*.deb; do dpkg-deb -x "$package" "$runtime/root"; done
  for binary in ffmpeg ffprobe; do
    printf '#!/bin/sh\nexport LD_LIBRARY_PATH="%s/root/usr/lib/x86_64-linux-gnu${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"\nexec "%s/root/usr/bin/%s" "$@"\n' "$runtime" "$runtime" "$binary" > "$runtime/bin/$binary"
    chmod 755 "$runtime/bin/$binary"
  done
fi
"$runtime/bin/ffmpeg" -version | head -n 1
"$runtime/bin/ffprobe" -version | head -n 1
"$runtime/bin/ffmpeg" -hide_banner -filters 2>/dev/null | grep -E ' (drawtext|drawbox|xfade) '
"$runtime/bin/ffmpeg" -hide_banner -encoders 2>/dev/null | grep ' libx264 '
test -r /usr/share/fonts/truetype/dejavu/DejaVuSans.ttf
if [ "$prepare_only" = --prepare-only ]; then exit 0; fi
test -f "$app/app/Jobs/ProcessStudioWorkspace.php"
systemctl is-active --quiet cron

# A dedicated user-owned Supervisor leaves the root-owned mail/default worker intact.
# sg + umask keep generated files writable by PHP-FPM's existing www-data group.
cat > "$runtime/worker.sh" <<EOF
#!/bin/sh
set -eu
cd "$app"
test -f app/Jobs/ProcessStudioWorkspace.php
test ! -f "$runtime/paused"
umask 002
export PATH="$runtime/bin:/usr/local/bin:/usr/bin:/bin"
exec /usr/bin/sg www-data -c 'exec /usr/bin/php -d memory_limit=1024M artisan queue:work studio --queue=studio --sleep=2 --tries=3 --timeout=7200 --memory=768 --max-time=3600'
EOF
chmod 755 "$runtime/worker.sh"
cat > "$runtime/supervisord.conf" <<EOF
[unix_http_server]
file=$runtime/supervisor.sock
chmod=0700

[supervisord]
logfile=$runtime/logs/supervisord.log
logfile_maxbytes=10MB
logfile_backups=3
pidfile=$runtime/supervisord.pid
childlogdir=$runtime/logs
umask=002

[rpcinterface:supervisor]
supervisor.rpcinterface_factory=supervisor.rpcinterface:make_main_rpcinterface

[supervisorctl]
serverurl=unix://$runtime/supervisor.sock

[program:repro-studio]
command=$runtime/worker.sh
directory=$app
autostart=true
autorestart=true
startsecs=5
startretries=10
stopasgroup=true
killasgroup=true
stopwaitsecs=7260
redirect_stderr=true
stdout_logfile=$runtime/logs/worker.log
stdout_logfile_maxbytes=20MB
stdout_logfile_backups=5
EOF
cat > "$runtime/ensure-supervisor.sh" <<EOF
#!/bin/sh
set -eu
exec 9>"$runtime/supervisor-start.lock"
/usr/bin/flock -n 9 || exit 0
if /usr/bin/supervisorctl -c "$runtime/supervisord.conf" pid >/dev/null 2>&1; then exit 0; fi
exec /usr/bin/supervisord -c "$runtime/supervisord.conf"
EOF
chmod 755 "$runtime/ensure-supervisor.sh"

# Preserve all existing cron jobs; Supervisor restarts workers immediately and cron
# restores Supervisor after reboot even when the deploy account is logged out.
cron_before="$runtime/crontab-before-$(date +%Y%m%d-%H%M%S)"
crontab -l > "$cron_before" 2>/dev/null || true
chmod 600 "$cron_before"
cron_next="$runtime/crontab.next"
grep -v '# repro-studio-supervisor$' "$cron_before" > "$cron_next" || true
printf '@reboot %s/ensure-supervisor.sh >> %s/logs/bootstrap.log 2>&1 # repro-studio-supervisor\n* * * * * %s/ensure-supervisor.sh >> %s/logs/bootstrap.log 2>&1 # repro-studio-supervisor\n' "$runtime" "$runtime" "$runtime" "$runtime" >> "$cron_next"
crontab "$cron_next"
chmod 600 "$cron_next"
"$runtime/ensure-supervisor.sh"
supervisorctl -c "$runtime/supervisord.conf" reread
supervisorctl -c "$runtime/supervisord.conf" update
sleep 6
supervisorctl -c "$runtime/supervisord.conf" status repro-studio
PATH="$runtime/bin:$PATH" php "$app/scripts/studio-runtime-check.php"
