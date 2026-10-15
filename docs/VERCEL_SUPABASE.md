# Free demo: Vercel + Supabase

This is the selected hosting setup for `ascotdrrm-netizen/drrmascot`. Live database migrations remain deferred. Nothing in the build or HTTP entry point migrates or seeds a database.

## 1. Prepare Supabase

1. Create a dedicated demo project, preferably in Singapore. Keep the database password in your password manager.
2. Open **Connect**, choose **Session pooler**, and copy its PostgreSQL URI on **port 5432**. Use that exact host and username for `DB_URL`. Percent-encode reserved characters in the password. Set `DB_SSLMODE=require`. Do not use port 6543 with this app's PDO prepared statements. [Supabase Laravel guide](https://supabase.com/docs/guides/getting-started/quickstarts/laravel)
3. In **Storage**, create the bucket **drrms** with **Public OFF** and set its **maximum file size to 10 MB**. This storage-side limit is required: S3 signed PUTs do not enforce the client-declared size. Laravel separately checks the actual size and type before accepting a record. Allow `application/octet-stream` for staging. This limit also caps the stored ZIP/PDF artifacts in this demo bucket.
4. In **Storage > S3 Configuration**, generate server-side S3 credentials and copy the access key, secret, endpoint and region. Prefer the direct storage endpoint displayed there, such as `https://PROJECT-REF.storage.supabase.co/storage/v1/s3`. These are S3 credentials, not the anon key. [Supabase S3 authentication](https://supabase.com/docs/guides/storage/s3/authentication)

Laravel continues to manage login, roles and approvals. Supabase Auth setup is unnecessary. The migration listener locks down application tables from Supabase's public Data API; do not grant anon/authenticated access to them.

## 2. Import into Vercel

Push the prepared source, then import **ascotdrrm-netizen/drrmascot**:

| Setting | Value |
| --- | --- |
| Framework preset | Other |
| Root directory | Repository root (leave blank) |
| Node.js | 22.x |
| Install command | `npm ci` |
| Build command | `npm run build` |
| Output directory | `public` |
| Function region | Singapore (`sin1`, in vercel.json) |

`api/index.php` uses the pinned community runtime `vercel-php@0.9.0` (PHP 8.5). Its Composer build hook discovers packages and rebuilds assets inside the PHP function bundle so Laravel can read its Vite manifest and icons, even when the static and function builders use separate working folders. The normal Vercel build supplies the browser's static assets. Runtime working files go to `/tmp`; uploaded records and stored ZIPs go to Supabase. Static PHP files, including `public/index.php`, are blocked from direct delivery. [Runtime documentation](https://github.com/vercel-community/php)

The community runtime is a deployment dependency. Its README does not list GD or GMP among enabled extensions. Image-heavy PDF generation and spreadsheet/QR features must be checked on the actual deployment; local PHP passing does not prove those extensions exist there.

## 3. Set environment variables

Use [deploy/vercel.env.example](../deploy/vercel.env.example) as the checklist. Fill every blank required for your demo in **Vercel > Settings > Environment Variables**. Add actual values through the dashboard, not GitHub or chat.

- `APP_KEY`: generate a new stable key on your PC with `php artisan key:generate --show`. Keep it unchanged across redeploys. Do not use the local testing key.
- `APP_URL`: the actual HTTPS production domain; update it when the domain changes.
- `APP_ENV=production`, `APP_DEBUG=false`, `SERVERLESS_HOSTING=true`, `DIRECT_UPLOADS=true`.
- `DB_CONNECTION=pgsql`, `DB_URL`, `DB_SSLMODE=require`.
- `FILES_DISK=s3`, `BACKUP_DISK=s3`, and all five `SUPABASE_S3_*` values.
- `SESSION_DRIVER=database`, `SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true`, `CACHE_STORE=database`.
- `CRON_SECRET`: a fresh random string of at least 32 characters. Vercel includes it as a Bearer token when calling the daily maintenance route. Never put it in a browser variable.
- Set real SMTP credentials before testing Reporter email verification or password reset. `MAIL_MAILER=log` sends nothing. Use a verified sender, `MAIL_TIMEOUT=20`, and check inbox/spam on the live demo.
- `SMS_GATEWAY=log` is simulation. Phone OTP remains unavailable until a real SMS provider is configured.
- Set `DATA_PRIVACY_PASSWORD_HASH` to a bcrypt hash of a separate secret to enable backup/restore. Keep `BACKUP_SNAPSHOT_DIR` empty on Vercel.

Do not set `SESSION_DOMAIN` to a Supabase domain. Host-only cookies are appropriate. Do not expose database, S3, SMTP or service credentials with a `VITE_` prefix.

Keep Preview deployments disconnected from the production database/bucket. Give previews a separate Supabase project and key if you need interactive preview tests.

## 4. Database initialization — later, when approved

Until migrations are approved and applied, `/up` can confirm PHP boots, but database-backed pages are not ready. A green deployment is not evidence that login works.

Vercel has no persistent Artisan shell for this PHP function. After authorization, use the local checkout with PHP and Composer installed. Create an ignored **.env.vercel** containing the same backend configuration, pointing to the dedicated demo Supabase database. Never commit it.

Run these only when the migration step is authorized:

```powershell
cd C:\DRRM\drrmascot-upload
php artisan migrate --env=vercel --force
php artisan app:create-admin --env=vercel
```

The Admin command prompts for a full name, email and a hidden strong password, creates the campus/school catalog, and refuses to overwrite an existing Admin. The development `DatabaseSeeder` is blocked in production because it contains a known default password. Do not use `migrate:fresh`, `composer setup`, or the default seeder on the live database.

`PresentationDemoSeeder` is deliberately limited to local/testing environments. To add its labeled synthetic incident to a **dedicated disposable demo database** later, make a separate ignored `.env.vercel-demo` with the same demo connection/storage values and `APP_ENV=local` for this local console operation only:

```powershell
php artisan db:seed --env=vercel-demo --class=PresentationDemoSeeder --force
```

Keep `APP_ENV=production` on Vercel itself. This is a separate deferred step. Existing local records and photos are not automatically copied by a GitHub deployment. Migrating existing records/files needs a separate reviewed transfer.

## 5. How the Vercel demo behaves

### Uploads and downloads

Vercel Functions limit request/response bodies to **4.5 MB**. The browser uploads each file to private Supabase staging using a short-lived signed PUT. The record request carries an encrypted ticket bound to the account, campus, form path, method and field. Laravel downloads a temporary copy, checks size and actual file type with the existing validators, then stores the final private record. Up to 10 MB per file; staging requires JavaScript. The offline report queue keeps its original files until server acknowledgement. [Vercel limits](https://vercel.com/docs/functions/limitations)

Authenticated file/backup downloads redirect to one-minute Supabase URLs. These URLs are temporary bearer links; someone who obtains one can use it until expiry. The app's original signed file route still checks its viewer and session. Generated PDF attachments of 3 MB or larger are also offloaded to private storage. Restore uploads are capped at 10 MB in this demo.

The daily protected maintenance route removes staging/export files older than two hours when it next runs. Monitor bucket usage, especially after interrupted uploads. No bucket lifecycle policy is assumed.

### Broadcasts and scheduled work

In-app notices appear immediately on publication. While an approved staff dashboard is open and visible, it posts every 15 seconds to process one pending external delivery. Coordinator processing is limited to that campus. Overlapping requests use database delivery claims; interrupted sends become **Unknown**, never automatically resent. Email/SMS status remains visible in delivery history.

When all staff dashboards close, immediate external broadcast delivery pauses. The daily maintenance invocation advances one additional delivery. Vercel Hobby cron runs at most once daily with approximate timing; it is not a continuous queue worker. For an unattended demonstration you can run the existing worker/scheduler on your PC against the demo database. Do not set `QUEUE_CONNECTION=sync` and recursively send an entire audience in one HTTP request. [Vercel cron limits](https://vercel.com/docs/cron-jobs/usage-and-pricing)

Automatic trash purge and local snapshot mirroring are not called by the Vercel daily route. Existing manual commands remain available from a configured local console. Always-on emergency operation requires a worker/scheduler arrangement beyond this free demo.

## 6. Verify the deployed demo

After the deferred setup:

1. Open `/up`, the landing page, login and the dashboard on desktop and a phone. Check CSS, icons, charts, map and service worker.
2. Log in as Admin, log out, and try the protected page again. Reload and check database sessions persist correctly.
3. Upload an incident photo larger than 4.5 MB and a multi-photo Reporter report. Confirm PUT goes to Supabase and the final form carries tickets. If the browser reports a CORS/signature error, inspect that actual storage response before changing permissions; keep the bucket private.
4. Try an invalid image, another account's copied app file link, and an expired link; verify refusals.
5. Download a backup, redeploy, then download/restore it from Supabase. Confirm successful downloads appear in the activity log. Use the separate privacy secret.
6. Generate a PDF containing campus logos and incident photos. Confirm actual runtime extensions and output rendering; do not present a broken PDF feature as verified.
7. Register a Reporter with a real inbox, verify email, validate/approve the account, submit a report and SOS, then check staff notifications.
8. Publish a small broadcast and leave a staff dashboard open. Confirm final delivery history; log providers must say **Simulated**.

Local security/workflow tests cover upload tickets, file validation, private redirects, backup durability logic and bounded campus broadcast work. Actual Vercel build, Supabase connectivity/CORS, PHP 8.5 extension compatibility, SMTP delivery and mobile browser behavior still require the live smoke checks above.
