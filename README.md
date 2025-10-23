# Real Leads Checker

A PHP 8.2 + MySQL web app to fetch emails via IMAP and classify them into genuine leads vs. spam. Uses vanilla PHP, PDO, Bootstrap 5 via CDN, and optional OpenAI (only if you add a key).

## Features

- User registration/login, admin user management
- Add IMAP accounts (Gmail/Outlook/etc. via credentials; OAuth not required)
- Fetch emails manually or via cron (Message-ID/hash dedupe)
- Process leads via algorithmic scoring or optional GPT
- Bulk actions: mark Genuine/Spam, re-process selected
- Export CSV of Genuine leads (respects current filters)
- Date range quick filters (Last week, 7/30 days, last month, all time)
- Sort by received date asc/desc, server-side search + pagination
- Audit trail of lead checks, re-process and manual overrides

## Requirements

- PHP 8.2+ with extensions: `pdo_mysql`, `imap`, `openssl`, `curl`
- MySQL 8+

## Local Development (Docker MySQL)

Option A — Everything in Docker (no local PHP needed)

1. Start services (MySQL + PHP/Apache):
   - `cd docker`
   - `docker compose up -d --build`
2. Use Docker config:
   - Copy `config/.env.docker.php` to `config/.env.php`
3. Initialize DB (inside app container):
   - `docker compose exec app php tools/install.php`
4. Open the app:
   - http://127.0.0.1:8000
5. Login (seeded):
   - Admin: `admin@example.com` / `Admin123!`
   - User: `user@example.com` / `User123!`

Option B — Local PHP + Docker MySQL

1. Start only MySQL:
   - `cd docker && docker compose up -d mysql`
2. Copy config sample and edit:
   - `cp config/config.sample.env.php config/.env.php`
   - Set DB to: host `127.0.0.1`, port `3310`, user `rlc`, pass `rlcpass`, db `real_leads_checker`
3. Install DB:
   - `php tools/install.php`
4. Run app via PHP built-in server:
   - `php -S 127.0.0.1:8000 -t public public/index.php`

## cPanel Deployment

1. Upload files; set `public/` as the document root (or move `public/*` into your `public_html/`).
2. Create a MySQL database and user in cPanel; assign privileges.
3. Copy `config/config.sample.env.php` to `config/.env.php` and set DB credentials, `APP_KEY` (32+ random chars), `CRON_TOKEN`, and timezone.
4. Ensure PHP IMAP and PDO MySQL extensions are enabled in cPanel’s PHP selector.
5. Visit the site; installer will run on first load.

## Cron Jobs

Set two GET endpoints (protected by `CRON_TOKEN`) in cPanel Cron Jobs, e.g. every 10 minutes:

- Fetch: `https://yourdomain.com/cron/fetch?token=YOUR_TOKEN`
- Process: `https://yourdomain.com/cron/process?token=YOUR_TOKEN`

Check logs at `storage/logs/app.log`. Basic rate limiting (60s per endpoint) prevents excessive invocations.

## OpenAI (Optional)

- Go to Settings → Filtering, set mode to GPT and paste your API key.
- The app uses Chat Completions to classify with a JSON response.
- If no key or Algorithmic mode is set, the local scorer is used.

## Security Notes

- Passwords hashed via `password_hash` (Argon2id if available else BCRYPT)
- CSRF tokens on all POST forms
- Prepared statements everywhere (PDO), XSS escaped outputs
- Secrets (IMAP password, OpenAI key) encrypted at rest with OpenSSL AES-256-CBC using `APP_KEY`
- Sessions hardened (HttpOnly, SameSite=Lax; Secure when HTTPS)
- Soft deletes: users and leads are soft-deleted (recoverable in DB)

## Email Accounts

- For Gmail, enable 2FA and create an App Password; use IMAP host `imap.gmail.com`, port `993`, encryption `ssl`, folder `INBOX`.
- Outlook/Office365 typically `outlook.office365.com`, port `993`, `ssl`.

## Project Structure

```
public/            Front controller `index.php`
app/
  Core/            DB, Router, View, Installer
  Security/        Auth, Csrf
  Controllers/     Route handlers
  Models/          DB access
  Services/        ImapService, LeadScorer, OpenAIClient
  Views/           PHP templates (Bootstrap 5)
config/
  .env.php         Your local/prod config (not committed)
  config.sample.env.php
migrations/
  schema.sql       Database schema and installer seed
docker/
  docker-compose.yml   MySQL for local dev
storage/
  logs/app.log     Cron logs
```

## Acceptance Checklist

- Register/Login works; admin panel manages users.
- Add an IMAP account, Fetch Now pulls emails (when IMAP enabled on server).
- Run Filter classifies leads via Algorithmic mode by default; GPT if key provided.
- Date filter buttons and sorting operate server-side.
- No paid third parties; OpenAI only used if you add a key.
- Secure coding practices applied and deployable on cPanel.
