# Direct downloads

`downloads.reprodashboard.com` is a DNS-only A record pointing at the existing
origin. No AWS or other storage provider is required. The dashboard stays behind
Cloudflare. Archives are still prepared asynchronously and served by Nginx.

## Provisioning and activation

1. Review `python3 scripts/deploy/install-download-origin.py` (preview only).
2. Run the same script with `sudo` and `--apply` on the origin. It installs a
   restricted vhost and certificate using the existing ACME account, tests the
   configuration, and rolls the vhost back on failure. Certificate renewal reloads
   Nginx. The existing media-transfer HTTP log configuration is required.
3. Deploy the application with the guarded prepared-release workflow.
4. Verify a signed link on the download host: valid HTTPS, correct Content-Length,
   strong ETag, Accept-Ranges, correct 206 bytes, and allowed-origin CORS headers.
   Verify unsigned/tampered links return 403, internal aliases and other APIs 404.
5. Set `MEDIA_DOWNLOAD_ORIGIN=https://downloads.reprodashboard.com` in the preserved
   production environment, rebuild Laravel's config cache and restart queue
   workers using the existing deployment account and shared web group.

An empty `MEDIA_DOWNLOAD_ORIGIN` immediately returns new links to the dashboard
origin after the config cache/workers are refreshed. Keep the download vhost
available until previously issued links expire. Existing dashboard links remain
valid. APP_URL, API URLs, preview URLs, role checks and signature expiry stay as
before; only ZIP links and explicit single-file download links use the new host.
Absolute signatures cover the final hostname, path, expiry and attachment name.
Do not weaken signed middleware to accept unsigned/relative URLs for this change.

## Editor transfer option

The existing Raw Uploads toolbar offers **Parallel download** on browsers with a
file save API (Chrome/Edge). Standard browser downloads remain available. It uses
up to four 8 MiB ranges, writes at the correct file offsets, retries transient
failures, and validates ETag and Content-Range on every part. It supports files
up to 50 GiB without buffering the complete archive. Pause commits completed parts;
resume reauthorizes the same file selection and rejects a changed archive. Keep
the tab open: cross-reload resumability is not implemented. Closing a paused
download leaves an incomplete file which must not be mistaken for a complete ZIP.
No application bearer token or cookies are sent to the asset host.

Benchmark actual editor networks. Compare equal byte ranges on the old and new
paths, then validate a complete archive with SHA-256. Direct routing removes the
Cloudflare proxy hop; it cannot exceed the origin's upload link, disk throughput,
or recipient's bandwidth. Storage/CDN migration remains a separate future choice.
