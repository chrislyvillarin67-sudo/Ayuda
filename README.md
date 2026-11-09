# Ayuda Distribution and Monitoring System (PHP + MySQL)

A full web-based rebuild of the Ayuda Distribution and Monitoring mobile app for Davao City,
built with PHP, MySQL (PDO), vanilla JS, Chart.js, and Leaflet (OpenStreetMap — no API key needed).

## Roles
- **District Admin** — sees all districts/barangays, reviews barangay requests, generates
  citywide reports, imports beneficiary data via CSV, drills into inventory per barangay,
  approves self-registered Barangay Staff accounts, and creates new Staff/Admin accounts
  directly under **Manage Users**. **District Admin accounts are never self-registered** —
  they only come from an existing admin creating one directly.
- **Barangay Staff** — can either be created directly by a District Admin (instantly active),
  or **self-register** ("Register as Barangay Staff" on the login page) by picking their
  barangay and setting a username/password. Self-registered accounts start **pending** and
  can't log in until a District Admin approves them under Manage Users. Once active, staff
  manage beneficiaries and inventory for their own barangay, approve registrations, record
  distributions, and submit aid requests to the admin.
- **Beneficiary** — self-registers by choosing their own **Beneficiary ID** (like a username)
  and password — the registration form logs them straight into their dashboard. They can log
  back in anytime afterward (Login page → Role: Beneficiary → their chosen ID + password).
  Checks approval status, ayuda history, a map of distribution centers, and can chat with the
  built-in FAQ assistant for help.

> **Already have this app installed from an earlier version?** Run these once in phpMyAdmin's
> SQL tab (skip both if you're installing fresh from `schema.sql`, which already includes them):
> - `sql/migration_beneficiary_code.sql` — adds the Beneficiary ID column
> - `sql/migration_staff_self_register.sql` — adds the staff account approval-status column


## 1. Requirements
- PHP 8.0+ with `pdo_mysql` extension enabled
- MySQL or MariaDB
- Any local server stack: **XAMPP**, **WAMP**, **MAMP**, or a shared host (e.g. InfinityFree, Hostinger)

## 2. Setup (XAMPP example)
1. Copy this project folder into your web root, e.g. `C:\xampp\htdocs\Ayuda`
   (the folder name is up to you — just remember to use it in the URL below).
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Open `http://localhost/phpmyadmin`, click **New**, and create a database named `ayuda_db`.
4. Select the new database, go to **Import**, choose `sql/schema.sql`, and click **Go**.
   This creates all tables and seeds:
   - 11 Davao City districts + sample barangays with map coordinates
   - 2 demo accounts (see below)
   - Sample inventory (including a "Cash Assistance" peso budget), announcements,
     and a few demo beneficiaries with distribution history so the dashboard,
     reports, and charts show real data right away
   > **Already have an existing `ayuda_db`?** Import `sql/demo_data.sql`
   > once to add demo beneficiaries + budget + distribution history without
   > touching your real records (it never overwrites anything).
5. Open `config/db.php` and confirm the credentials match your MySQL setup (defaults to
   `root` / no password, which is standard for XAMPP).
6. Visit `http://localhost/Ayuda/` in your browser (replace `Ayuda` if you named
   the folder something else). The app shows a logo splash, then the login page.

### 2b. Copying this project to another laptop

The project folder is fully self-contained, so you can just copy it (USB, zip,
Google Drive, LAN share) and it will work on another machine. The **only** thing
that does not travel with a file copy is the MySQL database itself, because that
lives in the other computer's XAMPP. So on the new laptop:

1. Install XAMPP, then copy this whole folder into `C:\xampp\htdocs\` (the folder
   name can be anything).
2. Start **Apache** and **MySQL**.
3. Open `http://localhost/<your-folder>/setup_database.php` and click
   **Set up the database**. It creates `ayuda_db` and imports
   `sql/ayuda_db_full.sql`, which contains the complete current database
   (all tables, all records, all demo accounts).
4. Open `http://localhost/<your-folder>/login.php` and sign in.

Fastest route: double-click **`install-ayuda.cmd`**. It finds XAMPP wherever it
is installed, starts MySQL + the PHP server, and opens the installer for you
(no Apache/phpMyAdmin clicks needed). `start-ayuda.cmd` starts the app normally
afterwards; to make the folder lock-free before zipping, run
`.\start-ayuda.ps1 -Stop` in PowerShell (the server holds its log files open).

Windows may show a blue **"Windows protected your PC"** box the first time you run
a downloaded `.cmd` (SmartScreen). Click **More info** &rarr; **Run anyway**. If a
window flashes and closes by itself, read the reason in `logs/services.log` (or in
`%TEMP%\ayuda\php.err.log` for the web server itself) before asking for help.

Equivalent manual route: phpMyAdmin &rarr; create database `ayuda_db` &rarr;
**Import** &rarr; `sql/ayuda_db_full.sql` &rarr; Go.

Notes:
- `sql/ayuda_db_full.sql` is the **current** database and is the recommended
  source. `sql/schema.sql` is the structure-only fallback (no records) if you
  want a blank install.
- `setup_database.php` refuses to touch a database that already has tables
  unless you tick **Replace existing data**, so it is safe to run twice.
- `config/db.php` defaults to `root` with an empty password, which is the XAMPP
  default. Change `DB_HOST` / `DB_USER` / `DB_PASS` there if the new machine
  uses different credentials.
- If the app ever reports a missing table again, `config/db.php` self-adds any
  missing *columns* on first run; a genuinely missing *table* means the database
  import did not complete, so re-run `setup_database.php`.
- Delete `setup_database.php` on the new machine once it succeeds, so nobody can
  re-trigger the install.

### 2c. Files inside this folder that are NOT part of the web app

Because the folder has to be copyable as a single unit, a few things that should never be
reachable over HTTP sit inside it. All of them are blocked from being served by
`.htaccess` under Apache and by `router.php` under the bundled PHP server, but the
files remain on disk so they still travel with the copy:

| Path              | What it is                                              |
|-------------------|---------------------------------------------------------|
| `sql/`            | Full DB dump `ayuda_db_full.sql` + migrations (every beneficiary/user row and password hash) |
| `_keep/`          | Capstone paperwork (`.docx`), old backups, scratch notes |
| `logs/`           | `services.log` - launcher/startup messages (the web server's own logs stay in `%TEMP%\ayuda`) |
| `setup_database.php` | The one-click installer (see below)                   |

Rules and caveats:
- `.htaccess` is honoured by **Apache**; PHP's built-in server (`php -S`) ignores it,
  so the launcher passes `router.php`, which applies the same rules (blocks `sql/`,
  `_keep/`, `logs/`, dotfiles and `.sql`/`.log`/`.bak` files). Use Apache
  (`http://localhost/Ayuda/`) for anything public.
- `setup_database.php` additionally refuses to run on any POST that didn't come from
  `127.0.0.1`, so it still installs locally but can't be triggered over the internet.
- If you ever move this folder outside a web root, you can delete `sql/`, `_keep/`,
  `logs/`, and `setup_database.php` entirely — the running app does not need them.

## 3. Demo accounts
| Role            | Username | Password  |
|-----------------|----------|-----------|
| District Admin  | `admin`  | `admin123`|
| Barangay Staff   | `staff`  | `staff123`|

The fresh `schema.sql` seed includes a few demo beneficiaries (so the dashboard charts and
reports have data). You can still register a brand-new one from the login screen's
"Register as a Beneficiary" button to try that flow. During registration they choose their own
Beneficiary ID (e.g. `juan.delacruz`, 4-30 characters — letters, numbers, `.`, `-`, or `_`) and
password, then use that same ID to log back in later (Role: Beneficiary on the login page).
If a beneficiary forgets their password, a Barangay Staff or Admin can reset it from that
beneficiary's detail page (open Beneficiaries → tap their name → Account Assistance).

New Barangay Staff can self-register from "Register as Barangay Staff" on the login page —
their account is created immediately but starts **pending** until the District Admin approves
it under Manage Users (they'll see a "Pending Staff Approvals" section there). District Admin
accounts are never self-registered — only an existing admin can create one, directly from
Manage Users, where it becomes active immediately (no approval step, since the admin already
vetted it at creation time). An admin can also reset any staff account's password from
Manage Users if they get locked out.

## 4. Folder structure
```
Ayuda/
├── config/db.php              database connection settings
├── .htaccess                  blocks sql/, _keep/, logs/ and dumps (Apache)
├── router.php                 same rules for the launcher's php -S server
├── setup_database.php         one-click DB installer (delete after a successful install)
├── _keep/                     capstone paperwork + implementation notes (not served)
├── logs/                      service and PHP error logs (not served)
├── sql/schema.sql             full schema + seed data (import this first)
├── sql/demo_data.sql          OPTIONAL: adds demo beneficiaries/distributions to an existing DB
├── sql/migration_beneficiary_code.sql       run only if upgrading an existing install
├── sql/migration_staff_self_register.sql    run only if upgrading an existing install
├── includes/                  shared PHP partials (app header, bottom nav, chatbot, functions, flash)
├── assets/css/style.css       navy-and-gold glassmorphism design system
├── lib/fpdf.php + lib/font/   bundled open-source PDF library (used by export_pdf.php)
├── beneficiary/               beneficiary-only pages (dashboard.php, map.php)
├── login.php, register.php    beneficiary auth entry points
├── register_staff.php         Barangay Staff self-registration (pending admin approval)
├── terms.php                  Terms & Conditions (linked from login/register)
├── dashboard.php               role-aware staff/admin dashboard
├── beneficiaries.php, add_beneficiary.php, beneficiary_action.php
├── beneficiary_detail.php     full profile, approval/distribution history, password reset
├── inventory.php, distribute_ayuda.php, distribute_action.php
├── barangay_requests.php      aid requests / shortages / emergencies workflow
├── reports.php, export_excel.php, export_pdf.php
├── import.php                 CSV bulk import with duplicate detection
├── monitoring.php             charts + timeline
├── map.php                    staff/admin GPS map (Leaflet)
├── manage_users.php           admin: create staff/admin accounts, approve pending staff
└── profile.php                account info + change password
```

## 5. Notes on design decisions
- **Duplicate detection** matches on name + barangay. When triggered, the person registering
  sees the existing record and can either cancel or confirm registering anyway (flagged with
  a duplicate badge for staff to review).
- **Distribution flow**: staff use the dedicated **Distribute Ayuda** screen (in the More menu, or via a Quick Action on the dashboard). They pick the barangay (locked for staff, free for District Admin), the assistance type/item (Cash, Food Packs, Relief Goods, Sponsored, or Other), enter the cash amount *or* quantity, optionally set a distribution location/sponsor, then choose an approved beneficiary. Submitting records a distribution, **immediately decrements the correct resource balance**, and prevents double-claiming.
- **Cash is tracked as a real peso budget**: a "Cash Assistance" inventory item stores its remaining budget in pesos (e.g. 50,000). Distributing ₱10,000 makes the balance become 40,000 right away, reflected on the dashboard's Budget & Statistics and the Distribute Ayuda screen. The system rejects distributions that would bring cash or stock below zero.
- **Ayuda categorization**: every inventory item and distribution is tagged with an assistance type (cash / food_pack / relief_goods / sponsored / other), so the dashboard, reports, and exports can be filtered and grouped by type.
- **Three-district pie chart**: the dashboard shows a live Chart.js pie chart of the top three districts by beneficiary count (no hard-coded values).
- **Recent activity** on the Dashboard only shows distribution events (item + barangay/district), never individual beneficiary names, to protect privacy.
- **Deduplication**: matches on name + barangay. When triggered, the person registering sees the existing record and can either cancel or confirm registering anyway (flagged with a duplicate badge for staff to review). Demo distribution above is idempotent against duplicates.
- **Reports** are reviewed on-screen first (numbers + category breakdown) before an export
  modal offers **Excel (.xls)** or **PDF** — both trigger a real file download to the device.
  PDF export uses the bundled FPDF library (MIT-style open-source, no external service calls).
- **Recent activity** feeds only ever show barangay/district names, never individual
  beneficiary names, to protect beneficiary privacy on shared dashboards.
- **Beneficiary detail page** (tap any name in the Beneficiaries list): shows the full profile,
  who approved them and when, their complete ayuda history with which staff member processed
  each item, a duplicate-match link if flagged, and a password-reset tool for staff/admin.
  Barangay Staff can only open beneficiaries in their own barangay; District Admin can open any.
- **Terms & Conditions** (`terms.php`) is a general template — required reading (checkbox) before
  a beneficiary can register, and linked from the login page. It should be reviewed by your
  LGU's legal office before official/production use; it is not a substitute for legal advice.
- **Built-in FAQ chatbot** (the chat bubble on every page) answers common questions about using
  the app — registering, Beneficiary IDs, approvals, distribution, reports, and more. It's a
  simple client-side keyword matcher with no external API calls, so it can't fail or time out
  regardless of your server setup.
- **The entire app now shares one consistent mobile-app interface** — Staff and District Admin
  no longer use a desktop sidebar admin panel. They get the same navy header + bottom
  navigation bar style as the Beneficiary side: Home, Beneficiaries, Inventory, and Reports
  sit in the bottom nav, with a **More** button opening a sheet for Barangay Requests, Map,
  Monitoring, Import Data, Manage Users, and Profile/Settings. The layout still stretches to
  use extra space on wider screens/tablets, so nothing feels cramped on a desktop browser —
  it just always *feels* like a native app rather than a website with a sidebar.
- **Glassmorphism visual theme**: cards, the header, bottom nav, chips, modals, and the chatbot
  panel all use a frosted, semi-transparent "glass" look (soft blur + light borders) over a
  subtly colored blurred background, instead of flat solid white panels. Form inputs stay
  fully opaque on purpose — glass effects on fields you're actively typing into would hurt
  readability, so only container/panel surfaces got the glass treatment.
- **Staff self-registration** mirrors the beneficiary flow but with a safety gate: anyone can
  submit a Barangay Staff application, but it can't be used to log in until a District Admin
  approves it. This keeps onboarding easy without letting someone grant themselves staff
  access unsupervised. District Admin accounts skip this entirely — they're only ever created
  directly by an existing admin, never through public self-registration.

## 6. Security features
Security controls implemented per the IT111 security assessment:
- **Strong password policy** — new beneficiaries, Barangay Staff, and password resets must use
  at least 8 characters with an uppercase letter, a lowercase letter, a number, and a special
  character (`isPasswordStrong()` in `includes/functions.php`).
- **CSRF protection** — every POST form and fetch-based action (login, registration, profile,
  distribution, approvals, imports, password reset, etc.) includes and validates a per-session
  CSRF token. Requests with a missing/invalid token are rejected.
- **Session hardening** — cookies are `HttpOnly` + `SameSite=Lax` (and `Secure` when accessed
  over HTTPS); the session ID is regenerated on every successful login (prevents session
  fixation); sessions auto-expire after **30 minutes of inactivity**.
- **Login rate limiting** — after 5 failed login attempts for the same username/Gmail within
  15 minutes, further attempts from that browser session are blocked with a lockout message.
- **Security headers** — every response sends `X-Content-Type-Options: nosniff`,
  `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`, and a
  Content Security Policy that only allows the app's own resources plus Chart.js/Leaflet CDNs.
- **Secure logout** — clears the session and expires the session cookie.
- **SQL injection / XSS** — all queries use PDO prepared statements; all output is escaped
  with the `e()` helper; uploads validate real MIME type, extension, and size.
- **Secrets hygiene** — passwords are stored with PHP's `password_hash()` (bcrypt); the demo
  passwords in `schema.sql` are provided for local development only — change them before any
  real use.

## 7. Deploying to shared hosting (e.g. InfinityFree)
1. Upload all files via FTP into your `htdocs`/`public_html`.
2. Create a MySQL database from your host's control panel (note the prefixed DB name/user
   they assign you).
3. Import `sql/schema.sql` through their phpMyAdmin.
4. Update `config/db.php` with the host, database name, username, and password provided.
