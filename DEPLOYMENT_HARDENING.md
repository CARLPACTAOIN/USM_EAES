# USM EAES Deployment Hardening

## Deployment Posture

The current target is a local demo-ready Windows/Laragon setup with production-safe templates. For a real server, terminate HTTPS at Apache, Nginx, Caddy, a tunnel, or a managed proxy, then run Laravel with secure session settings.

Run before any demo or deployment:

```powershell
cd "C:\Users\cjcar\Documents\Capstone 3rd Year\System\Web"
$env:Path = "C:\laragon\bin\php\php-8.4.5-nts-Win32-vs17-x64;" + $env:Path
php -v
php artisan test
composer audit
npm run build
php artisan eaes:deployment-check --profile=demo
```

`php -v` must show PHP 8.4.1 or newer. If it shows XAMPP PHP 8.2, put the Laragon PHP 8.4 path before `C:\xampp\php` in your Windows user `Path`, then reopen PowerShell.

Use `--profile=production` on a real deployment.

For local development, `composer run dev` starts Laravel, the queue listener, and Vite. The script prepends the Laragon PHP 8.4 path before spawning child processes so `php artisan serve` and `php artisan queue:listen` do not fall back to XAMPP PHP 8.2.

## HTTPS And Cookies

Required for demo/production readiness:

- `APP_DEBUG=false`
- `APP_URL=https://...`
- `SESSION_ENCRYPT=true`
- `SESSION_SECURE_COOKIE=true`
- `EAES_FORCE_HTTPS=true` behind HTTPS
- `EAES_SECURITY_HEADERS=true`

Security headers are applied globally: content-type sniffing is disabled, framing is denied, referrer leakage is reduced, camera permissions are limited to same-origin, and HSTS is added for secure/forced HTTPS requests.

## PII Data Map

PII stored by EAES:

- Student name, email, student ID number, program, college, organization.
- Physical ID QR value.
- Attendance timestamps and validity status.
- Evaluation comments and sentiment outputs.
- Pending QR values for unresolved scanner records.

PII handling rules:

- Gemini sentiment payloads include only evaluation IDs and comment text.
- NLP assistant queries use whitelisted tables/fields and tenant scoping.
- Exports are role/tenant scoped and should be shared only with authorized USM/OSA personnel.
- Scanner hydration exposes only student name, course/program, student ID, and QR validation data needed for event attendance.
- Raw scan keys and unresolved QR values stay behind authenticated admin/scanner flows.

## Queue And Scheduler

Production recommendation:

- `CACHE_STORE=redis`
- `QUEUE_CONNECTION=redis`
- Run persistent queue workers.
- Run Laravel scheduler every minute.

Local/demo fallback:

- `CACHE_STORE=database`
- `QUEUE_CONNECTION=database`
- Run `php artisan queue:work --tries=3`.
- Run `php artisan schedule:work`.

The database queue fallback is approved only for local/demo usage. Redis remains the production path for scanner sync and AI sentiment jobs.

## Demo Walkthrough

1. Copy `.env.demo.example` to `.env`.
2. Fill `APP_KEY`, database, Google OAuth, and optional Gemini values.
3. Run:

```powershell
$env:Path = "C:\laragon\bin\php\php-8.4.5-nts-Win32-vs17-x64;" + $env:Path
php artisan migrate:fresh
php artisan db:seed
php artisan db:seed --class=DemoDataSeeder
php artisan storage:link
php artisan test
php artisan eaes:deployment-check --profile=demo
php artisan serve
php artisan queue:work --tries=3
php artisan schedule:work
```

Demo accounts created by `DemoDataSeeder` use `password123`:

- `osa.demo@usm.edu.ph`
- `society.demo@usm.edu.ph`
- `student.demo@usm.edu.ph`

Keep `/dev/login/{role}` disabled unless doing a local-only walkthrough:

```env
APP_ENV=local
EAES_ENABLE_DEV_LOGIN=true
```

## Backups And Secrets

- Back up PostgreSQL before demos and before production releases.
- Back up uploaded proposal documents from Laravel storage.
- Keep `.env`, Google OAuth secrets, Gemini API keys, and database credentials out of git.
- Rotate demo passwords before sharing recordings or screenshots.
- Re-run `composer audit` after dependency changes.
