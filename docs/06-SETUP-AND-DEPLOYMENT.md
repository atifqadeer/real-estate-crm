# 06 — Environment, Setup & Deployment

## Requirements

- PHP 8.2+ (8.3 used here), Composer 2
- Node 20+ for the React frontend
- Production: MySQL 8, Redis 7, S3-compatible bucket, queue worker +
  scheduler (Supervisor/systemd), TLS via reverse proxy
- Local tests in this environment: SQLite in-memory (no MySQL server
  available on the build machine — see README "Environment note")

## Local setup (backend)

```bash
cd backend
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed     # demo data is clearly marked DEMO
php artisan serve              # API on http://127.0.0.1:8000
```

Seeded demo accounts (local only, change/remove before any real use):
- Agency super admin: `admin@agency.demo` / `password`
- Client A owner: `owner@clienta.demo` / `password`
- Client B owner: `owner@clientb.demo` / `password`

## Local setup (frontend)

```bash
cd frontend
cp .env.example .env   # VITE_API_URL=http://127.0.0.1:8000/api/v1
npm install
npm run dev
```

## Tests

```bash
cd backend
php artisan test    # includes TenantIsolationTest — the Phase 1 gate
```

## Production checklist

- `APP_ENV=production`, `APP_DEBUG=false`, strong `APP_KEY`
- MySQL 8 + Redis configured; `php artisan horizon` under Supervisor
- Scheduler: `* * * * * php artisan schedule:run`
- Object storage bucket private; signed URLs only
- Backups: DB + storage, tested restore procedure
- Sentry (or equivalent) DSN set; log level warning+
- Pakistani legal review of privacy/marketing obligations before go-live
  (prompt §20) — do not treat any draft law as enacted
