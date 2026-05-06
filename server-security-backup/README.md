# Public HTML Security Backup (Cron + Manual Trigger)

This script set creates daily backup snapshots for:

- `wp-includes` (zip)
- `wp-admin` (zip)
- `wp-content` (zip) excluding `wp-content/plugins`
- All files in root `public_html` (zip)

It does not delete or modify source files.

## 1) Files

- `server-security-backup/config.php`
- `server-security-backup/run-backup.php`
- `server-security-backup/trigger.php`

## 2) Configure secure storage (important)

Edit `config.php`:

- `public_html`: absolute path to your site root. If null, auto-detect.
- `backup_storage`: set absolute path OUTSIDE `public_html`, for example:
  - `/home/CPANEL_USER/tgs_secure_backups`
- `manual_trigger_key`: set a long random secret key.
- `manual_allowed_ips`: optional whitelist (your office/home IP only).

Why outside `public_html`:

- Reduces direct web exposure if website is compromised.

## 3) Cron setup (end of day)

In cPanel Cron Jobs, add command (adjust paths):

```bash
/usr/local/bin/php -d detect_unicode=0 /home/CPANEL_USER/public_html/server-security-backup/run-backup.php --mode=daily >> /home/CPANEL_USER/tgs_secure_backups/cron.log 2>&1
```

Suggested schedule:

- `55 23 * * *` (11:55 PM every day)

Each run creates:

- `backup_storage/YYYY-MM-DD/daily-scan-HH-mm-ss-random/`

Inside run folder:

- `wp-includes-backup-YYYYmmdd-HHmmss.zip`
- `wp-admin-backup-YYYYmmdd-HHmmss.zip`
- `wp-content-backup-YYYYmmdd-HHmmss.zip` (without plugins)
- `public-html-root-files-YYYYmmdd-HHmmss.zip`
- `manifest.json`

## 4) Manual test trigger URL

Open URL in browser:

```text
https://YOUR_DOMAIN/server-security-backup/trigger.php?key=YOUR_SECRET_KEY
```

Result is JSON with:

- status
- created run folder
- generated archives

## 5) Security notes

- Keep `manual_trigger_key` secret.
- Use `manual_allowed_ips` when possible.
- Do not put `backup_storage` under `public_html`.
- Rotate trigger key periodically.
