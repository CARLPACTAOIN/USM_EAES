# USM EAES Web Portal

Laravel 12 backend and Blade/Tailwind web portal for the USM Event Attendance and Evaluation System.

## Local Setup

```powershell
cd "C:\Users\cjcar\Documents\Capstone 3rd Year\System\Web"
$env:Path = "C:\laragon\bin\php\php-8.4.5-nts-Win32-vs17-x64;" + $env:Path
php -v
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
```

`php -v` must show PHP 8.4.1 or newer. If it shows XAMPP PHP 8.2, move `C:\laragon\bin\php\php-8.4.5-nts-Win32-vs17-x64` above `C:\xampp\php` in your Windows user `Path`, then reopen PowerShell.

Install/build frontend assets with the Node installation available on the machine:

```powershell
npm install
npm run build
```

If the Codex sandbox cannot see Node, run those commands from your normal PowerShell.

## Demo Data

Demo data is optional and is never run by `DatabaseSeeder`.

```powershell
php artisan db:seed --class=DemoDataSeeder
```

Demo accounts use `password123`:

- `osa.demo@usm.edu.ph`
- `society.demo@usm.edu.ph`
- `student.demo@usm.edu.ph`

The local `/dev/login/{role}` shortcut is disabled by default. Enable it only for local walkthroughs:

```env
APP_ENV=local
EAES_ENABLE_DEV_LOGIN=true
```

## Running Services

For day-to-day portal development, run the combined server, queue, and Vite process:

```powershell
composer run dev
```

The dev script prepends the Laragon PHP 8.4 path before starting Laravel, so its child `php artisan` processes do not accidentally use XAMPP PHP 8.2.

Local demo fallback:

```powershell
$env:Path = "C:\laragon\bin\php\php-8.4.5-nts-Win32-vs17-x64;" + $env:Path
php artisan serve
php artisan queue:work --tries=3
php artisan schedule:work
```

Production recommendation:

- `CACHE_STORE=redis`
- `QUEUE_CONNECTION=redis`
- Run persistent queue workers under Supervisor/systemd or the hosting equivalent.
- Run Laravel scheduler every minute through cron.

## Verification

```powershell
$env:Path = "C:\laragon\bin\php\php-8.4.5-nts-Win32-vs17-x64;" + $env:Path
php artisan test
composer audit
npm run build
php artisan eaes:deployment-check --profile=demo
```

For production config validation:

```powershell
php artisan eaes:deployment-check --profile=production
```

## Deployment Templates

- `.env.example`: local development defaults.
- `.env.demo.example`: local demo/HTTPS tunnel template.
- `.env.production.example`: production template with secure cookies, Redis, and HTTPS defaults.

See `../DEPLOYMENT_HARDENING.md` for HTTPS, PII, queue, backup, and walkthrough notes.
