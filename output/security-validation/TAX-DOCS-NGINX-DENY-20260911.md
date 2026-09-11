# TAX-DOCS Nginx Deny — 2026-09-11

Issue: parked #6 — permanent Nginx deny for public tax-document paths on PRODUCTION (`repro-deploy` / `ssh.reprodashboard.com` as `maverick`).

## Status: BLOCKED (sudo gap)

| Item | Result |
| --- | --- |
| Permanent nginx rule live | **NO** — not installed |
| `nginx -t` + reload | **NOT DONE** — requires sudo |
| Passwordless sudo (`sudo -n`) | **FAIL** — `sudo: a password is required` (exit 1) |
| Write `/etc/nginx/snippets/` | **Permission denied** (root-owned) |
| Secrets rotated / Backend.lnk / backend deploy | Not performed (per stop rule) |

Operator action required: grant passwordless sudo for nginx edit+reload (or an admin run the install below), then re-run this close-out.

## Nginx map (read-only)

| Site | File | Notes |
| --- | --- | --- |
| `api.reprodashboard.com` | `/etc/nginx/sites-available/laravel.conf` (enabled) | `root /var/www/backend/public`; front-controller; no tax deny |
| `reprodashboard.com` / `www` | `/etc/nginx/sites-available/frontend.conf` (enabled) | `location ^~ /storage/` **aliases** `/var/www/backend/storage/app/public/` — direct origin risk if `tax-documents/` appears |
| catch-all | `/etc/nginx/sites-available/main.conf` | returns 404 |
| Existing tax deny | **none** found under sites-available / snippets / conf.d |

Public symlink present: `public/storage` → `storage/app/public`. Directory `storage/app/public/tax-documents` currently **absent** (404 today is absence / Laravel 404, **not** a permanent deny).

## Prepared permanent snippet (NOT installed)

Intended path: `/etc/nginx/snippets/tax-documents-deny.conf`

```nginx
# Permanent deny: legacy public tax-document paths (keep forever)
# Prefer 404 to avoid confirming existence.
location ^~ /storage/tax-documents {
    return 404;
}
location ^~ /storage/Tax-Documents {
    return 404;
}
location ^~ /storage/TAX-DOCUMENTS {
    return 404;
}
```

Include **before** any broader `location ^~ /storage/` (critical on `frontend.conf`) and inside the `api.reprodashboard.com` server block in `laravel.conf`:

```nginx
include snippets/tax-documents-deny.conf;
```

Then: `sudo nginx -t` && `sudo systemctl reload nginx` (or `sudo nginx -s reload`).

## Probe results (2026-09-11 ~04:31 UTC / ~10:01 IST)

Edge (public HTTPS):

| URL | HTTP |
| --- | --- |
| `https://api.reprodashboard.com/storage/tax-documents/` | 404 (Laravel HTML; not nginx deny) |
| `https://api.reprodashboard.com/storage/tax-documents/probe-nonexistent-20260911.pdf` | 404 |
| `https://api.reprodashboard.com/storage/Tax-Documents/` | 404 |
| `https://reprodashboard.com/storage/tax-documents/` | 404 (nginx empty-path) |
| `https://reprodashboard.com/storage/tax-documents/probe-nonexistent-20260911.pdf` | 404 |
| `http://api.reprodashboard.com/storage/tax-documents/` | 301 → HTTPS |

Origin (curl `--resolve` to 127.0.0.1):

| Host path | HTTP |
| --- | --- |
| api `/storage/tax-documents/` | 404 (Laravel `cache-control: no-cache, private`) |
| frontend `/storage/tax-documents/` | 404 (nginx) |

Authenticated API routes without cookies (expected; do not mutate tax docs):

| URL | HTTP |
| --- | --- |
| `GET /api/profile/tax-document` | 401 JSON |
| `GET /api/profile/tax-document/download` | 401 JSON |

No 200 with file content observed on probed paths. **Gap remains:** if a public `tax-documents/` tree is created later, frontend `^~ /storage/` would serve it until the permanent deny is installed.

## Three-line summary

- **Issue:** permanent nginx deny for `/storage/tax-documents*` not yet live on production.
- **Solved:** no — stopped on passwordless sudo gap (per policy; no workarounds).
- **How to finish:** admin installs snippet + includes in `laravel.conf` and `frontend.conf`, `nginx -t`, reload; re-probe for nginx-level 404 (not Laravel body).

Frontend LIVE 8e7e6d5 untouched. Backend tree not mutated. No `.env` / keys / tax contents in this note.
