# 13 — Run the CRM on your own PC (Windows, localhost)

The project runs on SQLite out of the box, or on MySQL 8 if you prefer
(see "Using MySQL instead of SQLite" below). You need three free tools
installed once: PHP, Composer, Node.js.

## Step 1 — Install PHP 8.4

1. Go to https://windows.php.net/download/ and download the
   **PHP 8.4 "VS17 x64 Thread Safe" ZIP** (Laravel 13 needs PHP 8.3+).
2. Extract it to `C:\php`.
3. In `C:\php`, copy `php.ini-development` and rename the copy to
   `php.ini`. Open `php.ini` in Notepad, find these lines and remove
   the `;` at the start of each:
   `extension=curl`, `extension=fileinfo`, `extension=gd`,
   `extension=mbstring`, `extension=openssl`, `extension=pdo_sqlite`,
   `extension=sqlite3`, `extension=zip`.
   Also make sure this line exists without a `;`: `extension_dir = "ext"`.
4. Add PHP to PATH: Windows Settings → search "environment variables" →
   Edit the system environment variables → Environment Variables →
   in "User variables" select `Path` → Edit → New → `C:\php`.
5. Open a NEW PowerShell window and check: `php -v` should print 8.4.x.

## Step 2 — Install Composer and Node.js

1. Composer: download and run **Composer-Setup.exe** from
   https://getcomposer.org/download/ (it finds your PHP automatically).
2. Node.js: download and install the **LTS** version from
   https://nodejs.org.
3. In a new PowerShell window check: `composer -V` and `node -v`.

## Step 3 — Get the code

On GitHub open `atifqadeer/real-estate-crm` → green **Code** button →
**Download ZIP**. Extract it to `C:\real-estate-crm`
(so you have `C:\real-estate-crm\backend` and `...\frontend`).

## Step 4 — Start the backend (PowerShell window 1)

```powershell
cd C:\real-estate-crm\backend
copy .env.example .env
composer install
php artisan key:generate
New-Item database\database.sqlite -ItemType File
php artisan migrate:fresh --seed
php artisan storage:link
php artisan serve
```

Leave this window open. The API now runs at http://127.0.0.1:8000.

## Step 5 — Start the background worker (PowerShell window 2)

```powershell
cd C:\real-estate-crm\backend
php artisan queue:work
```

Leave it open — publishing, notifications and Meta/WhatsApp jobs run
through this worker. (Optional, only for scheduled posts and the daily
token refresh: a third window with `php artisan schedule:work`.)

## Step 6 — Start the frontend (PowerShell window 3)

```powershell
cd C:\real-estate-crm\frontend
npm install
npm run dev
```

Open **http://localhost:5173** in your browser.

## Demo logins (password for all: `password`)

- `admin@agency.demo` — agency super admin (sees everything)
- `owner@clienta.demo` — Client A owner
- `owner@clientb.demo` — Client B owner
- `multi@demo.pk` — member of both clients (tenant-isolation demo)

## Using MySQL instead of SQLite

Skip the `New-Item database\database.sqlite` line in Step 4, and do
this instead:

1. **Install MySQL Server 8:** download the **MySQL Installer** from
   https://dev.mysql.com/downloads/installer/ and run it. Choose
   "Server only" (or Developer Default), install MySQL Server 8.x,
   keep port **3306**, set a root password (note it down), and let it
   install as a Windows Service that starts automatically.
2. **Enable the PHP driver:** in `C:\php\php.ini` also remove the `;`
   before `extension=pdo_mysql`, then close/reopen PowerShell.
3. **Create the database** — open PowerShell:
   ```powershell
   & "C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe" -u root -p
   ```
   (adjust `8.4` to your installed version), enter the root password,
   then run:
   ```sql
   CREATE DATABASE real_estate_crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'crm'@'localhost' IDENTIFIED BY 'choose-a-password';
   GRANT ALL PRIVILEGES ON real_estate_crm.* TO 'crm'@'localhost';
   FLUSH PRIVILEGES;
   exit
   ```
4. **Point the app at MySQL:** in `backend\.env` set:
   ```
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=real_estate_crm
   DB_USERNAME=crm
   DB_PASSWORD=choose-a-password
   ```
5. **Check and migrate** (in the backend folder):
   ```powershell
   php artisan db:show
   php artisan migrate:fresh --seed
   ```
   `db:show` should report a MySQL connection; then start the three
   windows exactly as in Steps 4–6. Everything else (logins, data) is
   the same — your data now lives in MySQL, and switching back to
   SQLite later just means changing `.env` again (the two databases do
   not share data).

## Notes

- **Stop everything:** Ctrl+C in each PowerShell window.
- **Start again later:** repeat only steps 4 (`php artisan serve`),
  5 and 6 — installs and migration are one-time. Your data stays in
  `backend\database\database.sqlite`.
- **Start from an empty database:** `php artisan migrate:fresh --seed`
  wipes and re-seeds the demo data.
- Facebook/Instagram/LinkedIn/WhatsApp connecting needs the provider
  app credentials in `backend\.env` (see docs/07, docs/10, docs/12) —
  everything else works locally without them.
