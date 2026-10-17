# ASCOT DRRMS — Full Code Audit

**Date:** 2026-10-17 · **System:** https://drrmascot.onrender.com · **Repo:** `ascotdrrm-netizen/drrmascot` (main)
**Stack:** Laravel 12 · PHP 8.3 · Blade + Tailwind v4 + Alpine · PostgreSQL + Storage (Supabase) · Docker on Render (free)
**Reviewer role:** Senior full-stack review (frontend, backend, UI/UX, login and accounts, logging, data, deployment, testing)

---

## 1. Summary

The core of the system is **well built**. Roles and campus access are checked on every record, file links are private and expire, uploads are validated, the Supabase public API is locked, accessibility scores are high, and 573 automated tests pass. Most problems are **not in the main features**. They are in **live configuration and operations**: email is not sent, CI is broken, there are no security headers, secrets need rotating, the database is in a different region from the server, and two copies of the code exist. A few real code bugs affect data safety and login UX.

| Severity | Count | Meaning |
|---|---|---|
| 🔴 Critical | 4 | Blocks real use or risks losing data or secrets. Fix now. |
| 🟠 High | 9 | Security or reliability problem a panel or an attacker would notice. Fix before the defense if possible. |
| 🟡 Medium | 21 | Should be fixed before real use. |
| 🟢 Low / UX | 11 | Polish, small risks, documentation. |
| ✅ Fixed today | 2 | Recorded so they are not reported again. |

### Top 5 to fix before the defense

1. **C1/C2 — Turn on real email (Gmail SMTP) in Render.** Without it, no Reporter can ever log in and nobody can reset a password.
2. **C3 + H9 — Rotate the exposed secrets** (S3 keys and DB password), and **recreate Supabase in Singapore** at the same time while the database is still empty.
3. **H1 + H2 + H8 — Patch the Symfony CVE, fix CI (Node 24), and make Render deploy only when tests pass.**
4. **H4 + M14 — Add "Remember me"** (needs a `remember_token` migration) so Reporters aren't logged out before an SOS.
5. **C4 — Keep one copy of the code.** Merge the work in `C:\DRRM\ascot-drrms` into the deploy repo.

---

## 2. Scope and method

**Checked:**
- Source code: routes, controllers, middleware, models, migrations, Blade views, JavaScript, service worker, Docker/Render config, and CI workflow.
- Live site probes: pages, status codes, response headers, cookies, response times, console errors.
- Lighthouse (mobile) on `/` and `/app/register`.
- `composer audit` and `npm audit` (production dependencies).
- GitHub Actions run history and failure logs.
- Supabase: tables, migrations count (53/53), bucket settings, and a public-API read attempt (denied ✅).

**Not checked (needs accounts or real devices):** logged-in dashboards for each role, SOS and report flows end to end, PDF output, backup/restore on S3, broadcast delivery, the offline queue on a real phone, and installing the PWA on Android/iOS. See the **Manual QA checklist** in section 6.

---

## 3. Findings table

| ID | Sev | Area | Title | Where | Effort |
|---|---|---|---|---|---|
| C1 | 🔴 | Login | Reporters can never sign in (email not sent) | `render.yaml:76-77`, `AuthController.php:61`, `PersonnelController.php:118` | S |
| C2 | 🔴 | Login | Password reset never arrives | `PasswordResetController.php:25` | S (same fix as C1) |
| C3 | 🔴 | Security | Secrets exposed in chat; weak DB password | Supabase, Render env, `.env.render` | S |
| C4 | 🔴 | Process | Two diverging copies of the code | `C:\DRRM\ascot-drrms` vs `C:\DRRM\drrmascot-upload` | M |
| H1 | 🟠 | Security | CVE-2026-108082 ReDoS in symfony/http-foundation 7.4.18 | `composer.lock` | S |
| H2 | 🟠 | Testing | CI fails on every push; Postgres tests never run | `package.json:10`, `.github/workflows/ci.yml:43` | S |
| H3 | 🟠 | Security | No security headers (HSTS, frame, nosniff, CSP…) | middleware (missing) | S |
| H4 | 🟠 | Login/UX | No "Remember me"; 120-minute session | `config/session.php:35`, login views | S |
| H5 | 🟠 | DevOps | Free plan sleeps; queue and scheduler stop | Render plan | — (decision) |
| H6 | 🟠 | Logging | Logged client IP is probably Render's proxy | `bootstrap/app.php:19` | S |
| H7 | 🟠 | Data | Deleting a Reporter erases their reports, SOS and photos | migration `2026_10_16_000000…:33,69`, `Personnel.php:24-38` | M |
| H8 | 🟠 | DevOps | Render deploys even when CI is red | `render.yaml:11` | S |
| H9 | 🟠 | Performance | 1.6–2.7 s per page: app in Singapore, DB in Seoul | Supabase region | S now / L later |
| M1 | 🟡 | Logging | Campus status and Settings changes not logged | `CampusStatusController.php:14`, `SettingsController.php:30,43,55` | S |
| M2 | 🟡 | Logging | Activity log never pruned; failed-login spam | `activity_logs` | S |
| M3 | 🟡 | Privacy | Failed-login rows store the typed email | `AuthController.php:49` | S |
| M4 | 🟡 | Backend | Phone numbers not validated at input | `ReporterAuthController.php:27` and 3 more | S |
| M5 | 🟡 | Backend | Student/employee ID not unique or format-checked | `ReporterAuthController.php:24` | S |
| M6 | 🟡 | UX | Staff not notified of new sign-ups | registration controllers | S |
| M7 | 🟡 | DevOps | One bad migration takes the site down | `docker/start.sh:7,18` | S (doc) |
| M8 | 🟡 | DevOps | `/up` doesn't check DB/storage | `bootstrap/app.php:13` | S |
| M9 | 🟡 | Code | Inventory/Requisition module unreachable | `InventorySupplyController`, `RequisitionController`, views | M |
| M10 | 🟡 | Performance | Heavy PDFs on 512 MB instance | `docker/php.ini:4` | S (test) |
| M11 | 🟡 | Code | Vercel leftovers and serverless code paths | `vercel.json`, `api/`, … | S |
| M12 | 🟡 | Code | Sample `Fixtures` mixed into real controller | `AppPreviewController.php` | M |
| M13 | 🟡 | Security | Local files holding secrets | `.env.render`, `.env.vercel`, zips | S |
| M14 | 🟡 | Login | No `remember_token` column (blocks H4) | `PasswordResetController.php:86-90` | S |
| M15 | 🟡 | Security | Password change doesn't end other sessions | `ProfileController.php:71`, `PasswordResetController.php:69` | S |
| M16 | 🟡 | Ops | Backup/restore disabled on live | `BackupController.php:165` | S |
| M17 | 🟡 | Feature | Web Push not implemented | `composer.json`, no usage | L |
| M18 | 🟡 | Performance | 15-second polling + DB session writes | `resources/js/modules/live.js:27` | M |
| M19 | 🟡 | Privacy | Photo EXIF (GPS, device) not stripped | upload handling | M |
| M20 | 🟡 | Security | No 2FA for Admin | — | M |
| M21 | 🟡 | Performance | Missing indexes on `activity_logs` | migration `2026_09_08_000000` | S |
| L1–L11 | 🟢 | Various | See section 4.10 | — | S |

Effort: **S** = under 1 hour · **M** = half a day to a day · **L** = several days

---

## 4. Detailed findings

### 4.1 Login and accounts

#### C1 🔴 Reporters can never sign in on the live site
- **Evidence:** `render.yaml:76-77` sets `MAIL_MAILER=log`, so emails are written to the log and never sent. Login refuses unverified Reporters (`AuthController.php:61`). The Admin cannot approve a Reporter whose email isn't verified (`PersonnelController.php:118` → 422). Yet the sign-up page still says *"Check your email for a verification link"* (`ReporterAuthController.php:54`).
- **Impact:** Every student or staff member who signs up is stuck forever. The main purpose of the phone app (SOS, reports, roll call) is unusable.
- **Fix:** Add Gmail SMTP to Render env: `MAIL_MAILER=smtp`, `MAIL_HOST=smtp.gmail.com`, `MAIL_PORT=587`, `MAIL_USERNAME`, `MAIL_PASSWORD` (a Google **App Password**), `MAIL_FROM_ADDRESS`. Optional safety net: an Admin-only "Mark email as verified" button for walk-in verification during the demo.

#### C2 🔴 Password reset never arrives
- **Evidence:** `PasswordResetController.php:25` uses the same mailer.
- **Fix:** Same as C1.

#### H4 🟠 No "Remember me"; sessions end after 2 hours idle
- **Evidence:** `config/session.php:35` (lifetime 120). Neither `auth/login` nor `reporter/login` has a `remember` checkbox, although `AuthController::login` already reads `$request->boolean('remember')`.
- **Impact:** A student opens the app during a typhoon and has to remember their password before pressing SOS.
- **Fix:** Add the checkbox (checked by default on `/app/login`). This needs **M14** first.

#### M14 🟡 The `personnel` table has no `remember_token` column
- **Evidence:** a comment at `PasswordResetController.php:86-90` confirms it.
- **Impact:** Turning on "remember" without a migration will crash login.
- **Fix:** Add a migration with `$table->rememberToken()` on `personnel`.

#### M15 🟡 Changing or resetting a password doesn't end other sessions
- **Evidence:** `ProfileController.php:71` and `PasswordResetController.php:69` don't call `Auth::logoutOtherDevices()` or delete the user's rows in `sessions`.
- **Impact:** A stolen or forgotten session on another device stays logged in after the password change.
- **Fix:** After a password change, delete the user's other sessions (`DB::table('sessions')->where('user_id', $id)->where('id', '!=', session()->getId())->delete()`).

#### M5 🟡 Student/employee ID isn't checked
- **Evidence:** `ReporterAuthController.php:24` only checks `string|max:40`.
- **Fix:** Add a format rule (e.g. `^\d{4}-\d{4,6}$`, confirm with the DRRMO) and `unique:personnel,id_number`.

#### M6 🟡 Staff aren't told when someone signs up
- Coordinators and the Admin only notice new Reporters and Coordinators by opening the pending lists.
- **Fix:** Create a `StaffNotification` for the campus Coordinator when a Reporter signs up, and for the Admins when a Coordinator registers.

#### M20 🟡 No two-factor authentication for Admin
- The Admin can back up, restore and clear all data. Add TOTP 2FA, or at least email OTP on Admin login, before real use.

#### L1 🟢 Two login pages
- `/login` (staff) and `/app/login` (phone app) both accept every role. Add "Staff? / Student or staff member?" links to each, so users don't get confused.

#### L2 🟢 Registration reveals whether an email exists
- `unique:personnel,email` tells a stranger which emails have accounts. This is acceptable for a campus app; note it.

#### L3 🟢 Reporter activation takes 3 steps
- Email verified → Coordinator validates → Admin approves. Consider auto-approving once the Coordinator validates, so the Admin isn't a bottleneck during an emergency.

#### L4 🟢 Login has no campus question (by design)
- The campus is chosen once, at sign-up, and stored on the account. Document this so testers don't report it as a bug.

---

### 4.2 Security

#### C3 🔴 Secrets were exposed; the DB password is weak
- The S3 access key and secret, and the database password, were pasted in a chat session. The password is short and guessable (a pattern of the system name plus a year).
- **Fix:**
  1. Supabase → Storage → S3 → create a new access key, then delete the old one.
  2. Supabase → Database settings → reset the password to a long generated one.
  3. Update Render env (`DB_URL`, `SUPABASE_S3_ACCESS_KEY_ID`, `SUPABASE_S3_SECRET_ACCESS_KEY`) and `.env.render`.
  4. Never paste secrets in chat. Copy them straight into Render.

#### H1 🟠 CVE-2026-108082: ReDoS in symfony/http-foundation
- **Evidence:** `composer audit` reports v7.4.18 (fixed in 7.4.19). Anyone can trigger it with a crafted `Cache-Control` request header, and it can hang PHP workers. With only 6 workers on the free plan, a few requests can take the site down.
- **Fix:** `composer update symfony/http-foundation`, run the tests, then deploy.

#### H3 🟠 No security headers
- **Evidence:** the live response has only `Content-Type`, `Cache-Control` and `Server`.
- **Missing:** `Strict-Transport-Security`, `X-Frame-Options`/`frame-ancestors` (clickjacking), `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Permissions-Policy` (camera/mic off; geolocation self), and `Content-Security-Policy`.
- **Fix:** One middleware next to `PrivateResponses` (`bootstrap/app.php`) that adds these headers. Start the CSP in report-only mode, because the views use inline scripts.

#### M13 🟡 Local files holding secrets
- `.env.render` (live DB password, S3 keys, APP_KEY), `.env.vercel` (old), and the zip archives in `C:\DRRM\` (`drrmascot-source.zip`, `ascot-drrms (oct 12).zip`) may contain `.env` files. They're ignored by git, but they're on a shared PC.
- **Fix:** Keep `.env.render` only as long as needed, delete `.env.vercel`, and check the zips before sharing them.

#### L11 🟢 Backup restore trusts the file
- A restore inserts rows as they are in the ZIP. It's protected by Admin login plus the data-privacy password, so it's acceptable. Document it as "only restore backups made by this system".

**Verified safe ✅:** XSS (every raw `{!! !!}` output is escaped first with `e()`), SQL injection (interpolated SQL comes only from code constants), file access (signed, expiring, viewer-bound links in `FileController`, with non-images forced to download), CSRF on every form plus a 419 refresh, public registration can never create an Admin, CSV exports guarded against formula injection (`RollCallController.php:103`), backup ZIPs read in memory (no zip slip), and the Supabase public API denied on app tables.

---

### 4.3 Backend

#### H7 🟠 Deleting a Reporter erases their emergency history
- **Evidence:** `citizen_reports.reporter_id` and `sos_events.reporter_id` use `cascadeOnDelete()` (migration `2026_10_16_000000_create_reporter_response_tables.php:33,69`). `Personnel::booted()` (`app/Models/Personnel.php:24-38`) also deletes their photos and proof files.
- **Impact:** Removing one account permanently deletes incident reports, SOS records and evidence that the DRRMO may need for reports, audits or investigations.
- **Fix:** Change the foreign keys to `nullOnDelete()` (or block deletion while reports exist). Keep the files, and store an anonymized "Former reporter" label instead.

#### M4 🟡 Phone numbers aren't validated at input
- **Evidence:** only `string|max:20` in `ReporterAuthController.php:27`, `PersonnelController.php:169`, `ProfileController.php:38` and `DispatchController.php:52`. Normalization exists only at send time (`App\Support\PhoneNumber::normalize`).
- **Fix:** A `ValidPhilippineMobile` rule that reuses `PhoneNumber::normalize()` and rejects anything that doesn't normalize.

#### M9 🟡 Inventory/Requisition module can't be reached
- Controllers (`InventorySupplyController`, `RequisitionController`), models, views, migrations and a seeder exist, but there are **no routes**. Its views reference 15 route names that don't exist (`admin.inventory-supplies.*`, `coordinator.requisitions.*`).
- **Fix:** Either wire it up as the planned "Equipment & Supplies" module (with tests), or remove it to avoid confusion during the defense.

#### M12 🟡 Sample data mixed into a real controller
- `AppPreviewController` falls back to `App\Support\Preview\Fixtures` for guests (`shared()`, `sosStatus()`, `checkIn()`). The public views don't display this today (verified on live), but one template change could show "Juan dela Cruz" or a fake "Classes suspended" banner to the public. `Fixtures::categories()` is also used as the **real** report category list.
- **Fix:** Move categories to `config/report_categories.php`, and remove guest fixtures from production paths (or rename the controller to `ReporterAppController`).

#### M17 🟡 Push notifications are not implemented
- **Evidence:** `laravel-notification-channels/webpush` is in `composer.json`, but no code uses it, and the `VAPID_*` keys in `.env.example` are blank and not set on Render.
- **Impact:** Reporters only see advisories, broadcasts and roll calls while the app is open (or by SMS/email). A phone in a pocket gets nothing.
- **Fix:** Generate VAPID keys (`php artisan webpush:vapid`), store push subscriptions, send advisories and roll calls through the WebPush channel, and handle `push` in `public/sw.js`. Until then, remove the package or say "planned" in the thesis.

#### L5 🟢 `SchoolSeeder` matches campuses by keyword
- `whereLike('campus_name', "%Zabali%")->first()` attaches schools to the first match. That's fine with real data, but fragile. Match the full campus name instead.

**Verified ✅:** campus scoping on every record (`HandlesCampusScoping::authorizeRecordAccess` → `RecordAccess`); the last Admin can't be deleted or demoted; a self-delete guard; Reporter → staff role change requires full approval; SOS/report APIs validate GPS ranges, image type and size, limit to 3 photos, and are throttled (10/min); `DeliverBroadcast` uses `$tries = 1`, so SMS/email are never sent twice; the map search proxy is rate-limited to 1/second and cached; timezone is `Asia/Manila`.

---

### 4.4 Frontend and UI/UX

- **Lighthouse (mobile):** `/app/register` scores Accessibility **100**, Best Practices **96**, SEO **92**. `/` scores Accessibility **98**, Best Practices **96**, SEO **92**.
- **No console errors** on public pages, **no horizontal scroll**, and all landing links return 200.

#### L7 🟢 Lighthouse misses
- No `<meta name="description">` on the pages audited.
- The landing page has no `<main>` landmark.
- The landing header logo (`/images/drrm-logo-256.png`, class `h-12 w-12`) has the wrong aspect ratio. Use `object-contain` or set the real width and height.

#### L6 🟢 A few form fields have no `id`
- Some inputs have no `id`, so a `<label for>` can't point to them. Add ids.

#### L8 🟢 Search engines can index login and sign-up
- `robots.txt` allows everything. Add `<meta name="robots" content="noindex">` to the auth and app layouts.

#### L10 🟢 The service worker cache name is bumped by hand
- `public/sw.js:9` (`drrms-v2`). The offline hotlines page stays stale until someone bumps it. Generate the version at build time.

**Fixed today ✅:** the Campus and School/course dropdowns were empty on a fresh deploy. Now `php artisan app:ensure-campuses` runs on every start (`docker/start.sh`).

---

### 4.5 Logging and monitoring

#### H6 🟠 The logged IP is probably the proxy's
- **Evidence:** `bootstrap/app.php:19` trusts only `X-Forwarded-Proto`, not `X-Forwarded-For`. Behind Render/Cloudflare, `$request->ip()` returns the proxy address.
- **Impact:** `activity_logs.ip_address` is useless for investigations, and login throttling keys share a few proxy IPs.
- **Fix:** Log in once, check the IP in the Activity Log, then trust `X-Forwarded-For` from Render's proxy.

#### M1 🟡 Important actions are missing from the activity log
- Campus emergency status changes (Normal → **Evacuate**) in `CampusStatusController.php:14`.
- Settings: report signatory, add/rename schools (`SettingsController.php:30,43,55`).
- The main records are already logged automatically by `app/Traits/LogsActivity.php`.
- **Fix:** Use `LogsActivity` on `CampusStatus` and `School`, or add explicit `ActivityLog::create` calls.

#### M2 🟡 The activity log grows forever
- There's no pruning. Failed logins with random emails each add a row (the throttle is per email+IP), so a bot can fill Supabase's **500 MB free limit**.
- **Fix:** Make `ActivityLog` `Prunable` (e.g. 1 year, and 30 days for `login_failed`), and add a global login rate limit per IP.

#### M3 🟡 Failed-login rows store the typed email
- `AuthController.php:49` writes *"Failed login attempt for {email}"*. People sometimes type their password into the email box. Store a masked email (e.g. `ju***@gmail.com`).

#### M8 🟡 The health check doesn't test the database
- `/up` (`bootstrap/app.php:13`) only proves PHP boots. Add a `/health` that runs `select 1` and checks the bucket, then point Render's `healthCheckPath` at it.

#### No error monitoring
- Laravel logs go to Render's log viewer (`LOG_CHANNEL=stderr`, `LOG_LEVEL=warning`) and nobody gets alerted. Add a free error tracker (e.g. Sentry free tier) or at least a daily review. Failed queue jobs (`failed_jobs`) also have no alert.

---

### 4.6 Database and data

#### H9 🟠 The database is in a different region from the server
- **Evidence:** Render runs in **Singapore**, Supabase is in **Seoul (`ap-northeast-2`)**. Measured while awake: `/` 2.7 s, `/app/register` 2.0 s, `/login` 1.6 s (time to first byte). Every request also reads and writes the session and cache in the database.
- **Fix:** Create a new Supabase project in **Singapore** now, because the database is empty and nothing is lost. Update `DB_URL`, the S3 values and `SUPABASE_S3_REGION`. Optionally move cache to `file` (single container).

#### M21 🟡 Missing indexes
- `activity_logs` has no index on `created_at` or `action` (the Activity Log page filters by both). PostgreSQL doesn't index foreign keys automatically, unlike MySQL.
- **Fix:** Add indexes on `activity_logs(created_at)`, `activity_logs(action, created_at)` and frequently filtered foreign keys.

#### M16 🟡 Backup/restore is off on live
- `DATA_PRIVACY_PASSWORD_HASH` isn't set in Render, so `BackupController.php:165` disables the feature.
- **Fix:** Generate a bcrypt hash of a separate secret and add it to Render.

#### M19 🟡 Photo location data isn't removed
- Uploaded photos keep EXIF (GPS coordinates, phone model). Under the Data Privacy Act (RA 10173), strip EXIF on upload (re-encode with GD) unless the location is needed.

**Verified ✅:** 53/53 migrations applied on Supabase; app tables locked from the public API (permission denied); campuses and schools filled automatically.

---

### 4.7 Deployment and DevOps

#### C4 🔴 Two diverging copies of the code
- `C:\DRRM\ascot-drrms` (GitHub `Sabaok/ascot-drrms`, branch `backend/supabase`, **197 uncommitted changes**) and `C:\DRRM\drrmascot-upload` (GitHub `ascotdrrm-netizen/drrmascot`, the one that's **deployed**). **45 files differ.**
- **Impact:** Work done in one copy never reaches the live site, or gets overwritten.
- **Fix:** Pick `drrmascot` as the only repo, bring over any real work from `ascot-drrms` (review the diff), and archive the other copy.

#### H5 🟠 The free plan sleeps
- After 15 minutes with no visitors, the server sleeps. The first request takes about 50 seconds, and the queue worker and scheduler stop until someone visits (broadcast delivery and trash purge pause).
- **Defense:** Open the site 5 minutes before.
- **Real use:** A paid instance (always on), or at least an external uptime ping. An emergency system shouldn't sleep.

#### H8 🟠 Untested code goes live
- `render.yaml:11` has `autoDeploy: true`, so every push deploys even when CI fails (CI currently always fails, see H2).
- **Fix:** After fixing CI, set Render to deploy only after checks pass.

#### M7 🟡 One bad migration takes the whole site down
- `docker/start.sh:7` (`set -e`) together with `migrate --force` (line 18) means a failing migration stops the container before Apache starts.
- **Fix:** Document the rollback: Render → Deploys → redeploy the previous commit. Always test migrations on PostgreSQL first (blocked by H2).

#### M10 🟡 Heavy PDFs on a small server
- 512 MB RAM, `memory_limit=256M` (`docker/php.ini:4`), and up to 6 Apache workers. A consolidated report with many photos could run out of memory.
- **Fix:** Generate the heaviest real report on live, and lower the worker count or resize images before embedding them if needed.

#### M11 🟡 Vercel leftovers
- `vercel.json`, `api/`, `.vercelignore`, `docs/VERCEL_SUPABASE.md`, `deploy/vercel.env.example`, serverless code (`DirectUploads`, `ServerlessWorkController`, `ServerlessBroadcastWork`, `OffloadLargeDownloads`, `CloudDownload`, `serverless-work.js`, `direct-uploads.js`), and the test file `VercelHostingTest`.
- **Fix:** Remove them, or move them under an "alternative hosting" folder. Rename the test file to `HostingTest`.

#### L9 🟢 Unpinned Docker images
- `Dockerfile:7,19` and the extension installer use moving tags (`composer:2`, `node:22`, `mlocati/php-extension-installer:2`). Pin exact versions so builds are repeatable.

**Fixed today ✅:** Vercel's PHP runtime crashed on every request (vercel-community/php#650). The app was moved to a Docker deploy on Render.

---

### 4.8 Performance

- **H9** (region) is the biggest factor.
- **M18 🟡** Staff pages refresh data every **15 seconds** (`resources/js/modules/live.js:27`), and each request also writes the session row. Five open dashboards means about 20 DB writes per minute across regions. Fix: pause polling when the tab is hidden (check whether it already does), use 30–60 s on non-emergency pages, or move to Supabase Realtime pings.
- **M10** (PDF memory) and **M21** (indexes) also apply.
- Not measured yet: dashboard query counts with real data volume. Run Laravel Debugbar or `DB::listen` locally with seeded data.

---

### 4.9 Testing and CI

#### H2 🟠 CI has failed on every push
- **Evidence:** the last 5 runs all failed. The log says `node: bad option: --test-isolation=none`. `package.json:10` uses a flag that Node 22 rejects (`.github/workflows/ci.yml:43` sets `node-version: '22'`; it works locally on Node 24).
- **Impact:** The job stops **before the PHP tests**, so the **PostgreSQL test run has never actually run in CI**, even though live is PostgreSQL. Local tests use SQLite only.
- **Fix:** Set `node-version: '24'` (or remove the flag), push, and confirm both the SQLite and PostgreSQL jobs pass.

#### Coverage notes
- 573 PHP tests (28,089 assertions) and 9 JS tests pass locally.
- There are no browser end-to-end tests. Add a few Playwright smoke tests: login per role, Reporter sign-up, SOS, and an incident with a photo.
- Two tests were added today: campuses filled on deploy, and the demo accounts command.

---

### 4.10 Low-priority list (summary)

| ID | Item |
|---|---|
| L1 | Two login pages: add cross-links |
| L2 | Registration reveals existing emails |
| L3 | 3-step Reporter activation |
| L4 | No campus on login: by design, document it |
| L5 | `SchoolSeeder` keyword match |
| L6 | Inputs without `id` |
| L7 | Meta description, `<main>` landmark, logo aspect ratio |
| L8 | `noindex` on auth and app pages |
| L9 | Pin Docker image versions |
| L10 | Automatic service worker version |
| L11 | Restore trusts file contents: document it |

---

## 5. What is already done well

- **Access control:** role-based route groups (`role:`, `staff`), campus checks on every record, last-Admin protection, and public sign-up locked to Coordinator/Reporter.
- **Files:** private bucket, links that are signed, expiring and tied to the viewer, risky types forced to download, upload type and size validation.
- **Data protection:** Supabase public API locked automatically after every migration (`App\Support\Supabase\LockDown`), encrypted and secure httponly cookies, `APP_DEBUG=false`, privacy consent recorded at sign-up.
- **Reliability details:** no double-sending of broadcasts, Unknown/Retry delivery states, offline report queue with drafts, CSV injection guard, portable SQL (`DateBucket`).
- **UX:** accessibility 98–100, dark/light theme, PWA installable, custom error pages (403/404/419/429/500/503), password strength meter, clear pending-approval messages.
- **Testing:** 573 automated tests, including security behaviour (file links, campus scoping, upload tickets).

---

## 6. Manual QA checklist (needs real accounts and devices)

Create the accounts first: `php artisan app:create-demo-accounts --env=render`.

**Guest**
- [ ] Landing page, hotlines and offline page on a phone (360 px wide) and on desktop
- [ ] Reporter sign-up: campus → school list, validation messages, privacy consent
- [ ] Verification email arrives (after C1) and the link works
- [ ] Forgot password email arrives (after C2)

**Reporter (phone)**
- [ ] Install the PWA (Android Chrome, iOS Safari)
- [ ] Send an SOS with location allowed, and again with location denied
- [ ] Send a report with 3 photos (one larger than 4 MB) and a caption
- [ ] Turn on airplane mode, queue a report, go online, and confirm it syncs
- [ ] Answer a roll call (Safe / Need help)
- [ ] See advisories and a broadcast, and check the unread count clears
- [ ] Change the phone number → the OTP flow (after SMS is configured)

**Coordinator**
- [ ] Dashboard numbers match the records
- [ ] Validate a pending Reporter
- [ ] Incident: create with photos, edit, trash, restore; PDF report
- [ ] Building and hazard inspection with photo; PDF
- [ ] Drill: schedule → documentation → after-activity report; PDFs
- [ ] Reports Inbox / SOS Console: acknowledge, respond, resolve; dispatch a responder
- [ ] Try another campus's record ID in the URL → 403
- [ ] Campus status → Evacuate (then check it appears in the activity log after M1)

**Admin**
- [ ] Approve and reject accounts; change a role; try deleting the last Admin (blocked)
- [ ] Broadcast to one campus; check the delivery history (Simulated while SMS is `log`)
- [ ] Consolidated report PDF with many photos (check memory, M10)
- [ ] Backup download → restore (after M16)
- [ ] Activity log shows logins with real IPs (after H6)
- [ ] Settings: signatory and schools

**Cross-cutting**
- [ ] Dark and light theme on every main page
- [ ] Session expiry: leave a form open past 2 hours, then submit (419 handling)
- [ ] First visit after 15+ minutes idle (cold start time)

---

## 7. Fix roadmap

### Before the defense
1. C1/C2 Gmail SMTP on Render
2. C3 Rotate secrets + H9 recreate Supabase in Singapore (do both together)
3. H1 Composer update · H2 CI Node 24 · H8 deploy only on green
4. H4 + M14 Remember me + migration
5. C4 Single repo
6. H3 Security headers
7. M16 Set the backup privacy password
8. Run the manual QA checklist (section 6)

### Before real use
- H5 Paid always-on instance (or an uptime ping), and a real queue worker
- H6 Real client IPs · H7 Keep reports when an account is deleted
- M1, M2, M3 Logging gaps, pruning, masking · M8 Real health check · error monitoring
- M4, M5 Phone and ID validation · M6 Sign-up notifications · M15 End sessions on password change
- M19 Strip EXIF · M20 Admin 2FA · M21 Indexes · M10 PDF memory test
- Real SMS (Semaphore credits) and Web Push (M17)

### Later / cleanup
- M9 Inventory module decision · M11 Remove Vercel leftovers · M12 Remove fixtures from controller
- L1–L11 polish
- Playwright end-to-end tests

---

## 8. Fix log

| Date | Fixed | Commit |
|---|---|---|
| 2026-10-17 | H1 Symfony CVE · H2 CI (Node 24, Postgres job now runs) · H3 security headers · H4 + M14 "Keep me signed in" (remember_token) · H8 deploy only when CI passes · C1/C2 Gmail SMTP config (password still to be entered in Render) | a292f36 |
| 2026-10-17 | Empty campus/school lists on a fresh deploy · demo accounts from a dashboard password | 0a41dfc, 35a1b52 |
| 2026-10-17 | Report categories: Bullying, Harassment, Violence, Threat (one list in `config/report_categories.php`, part of M12) | a2e7d55 |
| 2026-10-17 | UI/UX: pagination readable in system dark mode, 44px phone tap targets, styled broadcast select, no empty avatar request, AA contrast in light theme, no side-stripe callouts | 5543212 |
| 2026-10-18 | H7 reports/SOS/photos kept when a Reporter is deleted ("Former reporter") · H6 visitor IP from `CF-Connecting-IP` (verify on live) · M15 password change/reset ends other sign-ins · M1 Campus Status, Schools, signatory logged · M2 log retention (failed logins 30 days, all 730 days) · M3 masked email in failed-login entries | this commit |

Still open: C3 + H9 (new Supabase in Singapore), C4 (retire `C:\DRRM\ascot-drrms`), H5 (paid instance for real use), M4–M6, M8–M13, M16–M21, L-items.
