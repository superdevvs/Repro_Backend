#!/usr/bin/env python3
"""Restricted DNS-only download vhost. Preview by default; --apply requires root."""
import argparse
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile

HOST = 'downloads.reprodashboard.com'
TARGET = Path('/etc/nginx/sites-enabled/repro-downloads.conf')
MARKER = '# Managed by install-download-origin.py\n'
WEBROOT = '/var/www/repro-download-acme'


def http_config():
    return MARKER + f'''server {{
    listen 80;
    server_name {HOST};
    access_log off;
    location ^~ /.well-known/acme-challenge/ {{ root {WEBROOT}; }}
    location / {{ return 301 https://{HOST}$request_uri; }}
}}
'''


def https_config():
    cors = '''add_header Access-Control-Allow-Origin $repro_download_cors always;
        add_header Access-Control-Allow-Methods "GET, HEAD, OPTIONS" always;
        add_header Access-Control-Allow-Headers "Range, If-Range" always;
        add_header Access-Control-Expose-Headers "Content-Length, Content-Range, Accept-Ranges, ETag, Last-Modified, Content-Disposition" always;
        add_header Vary Origin always;
        add_header X-Content-Type-Options nosniff always;'''
    locations = ''
    for name, root in [('private', '/var/www/backend/storage/app/private'),
                       ('originals', '/mnt/16tb/repro/media-originals'),
                       ('legacy', '/var/www/backend/storage/app/public'),
                       ('downloads', '/var/www/backend/storage/app/private/downloads')]:
        locations += f'''
    location ^~ /_repro-media/{name}/ {{
        internal;
        alias {root}/;
        sendfile on;
        aio threads;
        disable_symlinks on;
        {cors}
    }}
'''
    return http_config() + f'''
map $http_origin $repro_download_cors {{
    default "";
    "https://reprodashboard.com" "https://reprodashboard.com";
    "https://www.reprodashboard.com" "https://www.reprodashboard.com";
}}
server {{
    listen 443 ssl http2;
    server_name {HOST};
    ssl_certificate /etc/letsencrypt/live/{HOST}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/{HOST}/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;
    # Never log bearer signatures, object names, cookies or authorization headers.
    access_log /var/log/nginx/repro-transfers.jsonl repro_transfers;
    client_max_body_size 1k;
    send_timeout 600s;
    gzip off;
    location ^~ /api/public/shoot-media/file/ {{
        if ($request_method = OPTIONS) {{ return 204; }}
        limit_except GET {{ deny all; }}
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/backend/public/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_param DOCUMENT_ROOT /var/www/backend/public;
        fastcgi_param HTTP_COOKIE "";
        fastcgi_param HTTP_AUTHORIZATION "";
        fastcgi_param HTTP_X_FORWARDED_HOST "";
        fastcgi_param HTTP_X_FORWARDED_PROTO "";
        fastcgi_hide_header Set-Cookie;
        fastcgi_hide_header Access-Control-Allow-Origin;
        fastcgi_hide_header Access-Control-Allow-Credentials;
        fastcgi_hide_header Access-Control-Expose-Headers;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_read_timeout 60s;
        {cors}
    }}
    location / {{ return 404; }}
    {locations}
}}
'''


def run(*args):
    subprocess.run(args, check=True)


def write_config(content):
    fd, filename = tempfile.mkstemp(dir=TARGET.parent, prefix='.repro-downloads-')
    try:
        with os.fdopen(fd, 'w') as stream:
            stream.write(content)
        os.chmod(filename, 0o644)
        os.replace(filename, TARGET)
    finally:
        if os.path.exists(filename):
            os.unlink(filename)


def apply():
    if os.geteuid() != 0:
        raise SystemExit('Run with sudo. No files were changed.')
    for command in ('nginx', 'certbot', 'systemctl'):
        if not shutil.which(command):
            raise SystemExit(f'Missing prerequisite: {command}')
    if '173.79.41.182' not in {row[4][0] for row in socket.getaddrinfo(HOST, 443, socket.AF_INET)}:
        raise SystemExit('DNS must point directly to 173.79.41.182 before installing.')
    if not Path('/etc/nginx/conf.d/repro-media-transfers.conf').is_file():
        raise SystemExit('Install the existing media transfer configuration first.')
    previous = TARGET.read_text() if TARGET.exists() else None
    if previous is not None and not previous.startswith(MARKER):
        raise SystemExit(f'Refusing to replace unmanaged {TARGET}')
    run('nginx', '-t')
    Path(WEBROOT).mkdir(mode=0o755, parents=True, exist_ok=True)
    try:
        if not Path(f'/etc/letsencrypt/live/{HOST}/fullchain.pem').exists():
            write_config(http_config())
            run('nginx', '-t')
            run('systemctl', 'reload', 'nginx')
            # Reuses the existing ACME account; never accepts new terms implicitly.
            run('certbot', 'certonly', '--non-interactive', '--webroot', '-w', WEBROOT,
                '--cert-name', HOST, '-d', HOST, '--keep-until-expiring')
        write_config(https_config())
        run('nginx', '-t')
        run('systemctl', 'reload', 'nginx')
        hook = Path('/etc/letsencrypt/renewal-hooks/deploy/repro-download-nginx')
        hook.parent.mkdir(parents=True, exist_ok=True)
        hook.write_text('#!/bin/sh\nnginx -t && systemctl reload nginx\n')
        hook.chmod(0o755)
    except BaseException:
        if previous is None:
            TARGET.unlink(missing_ok=True)
        else:
            write_config(previous)
        run('nginx', '-t')
        run('systemctl', 'reload', 'nginx')
        raise
    print('DOWNLOAD_ORIGIN_HTTPS_READY. App activation is a separate verified step.')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    if args.apply:
        apply()
    else:
        print(https_config())
