# Free demo: Render + Supabase

Render runs the Laravel app from the `Dockerfile` (Apache, PHP 8.3, queue worker
and scheduler in one container). Supabase holds the database and the private
file bucket. Vercel is not used: its community PHP runtime currently fails every
request (vercel-community/php#650).

## 1. Supabase

1. Create a project in **Southeast Asia (Singapore)**, next to the Render server (the first one was in Seoul, `ap-northeast-2`; see section 5). `render.yaml` sets the S3 region to match. Keep the database password in your password manager.
2. **Connect > Session pooler**: copy the URI on **port 5432**. This is `DB_URL`.
   Percent-encode special characters in the password (`@` → `%40`, `#` → `%23`).
3. **Storage**: create bucket **drrms**, **Public OFF**, max file size **10 MB**.
4. **Storage > S3 Configuration**: copy the endpoint
   (`https://PROJECT-REF.storage.supabase.co/storage/v1/s3`) and create an access key.
   These go in `SUPABASE_S3_ENDPOINT`, `SUPABASE_S3_ACCESS_KEY_ID`, `SUPABASE_S3_SECRET_ACCESS_KEY`.

Laravel handles login and roles; Supabase Auth is not used. After every
migration the app locks its tables away from Supabase's public Data API.

## 2. Render

1. Sign in to Render with GitHub and allow it to read the repository.
2. **New > Blueprint**, pick this repository. Render reads `render.yaml`.
3. Render asks for the values marked `sync: false`. Copy them from the local,
   git-ignored `.env.render`:
   - `APP_KEY` (already generated; never change it once live, or sessions and encrypted data break)
   - `APP_URL`: `https://drrmascot.onrender.com`, or the address Render actually assigns
   - `DB_URL` and the three `SUPABASE_S3_*` values from step 1
4. **Apply**. The first build takes several minutes. On start the container runs
   `php artisan migrate --force` (`RUN_MIGRATIONS=true`).

Every push to `main` redeploys.

## 3. First administrator

Render's free plan has no shell. Fill `DB_URL` (and the S3 values) in
`.env.render` on this PC, then:

```powershell
cd C:\DRRM\drrmascot-upload
php artisan app:create-admin --env=render
```

It asks for name, email and a hidden password, creates the campuses and schools,
and refuses to run twice. Do not run `db:seed` or `migrate:fresh` against the live database.

## 4. Free-plan behaviour

- After 15 minutes without visitors the service sleeps; the next visit takes
  about a minute to wake. Open the site a few minutes before a demo.
- The queue worker and scheduler run only while the service is awake. Pending
  broadcast deliveries stay in the database and resume on wake.
- The container disk is wiped on every deploy and wake. All records, photos and
  backups are in the Supabase bucket. `BACKUP_SNAPSHOT_DIR` stays empty.
- `MAIL_MAILER=log` and `SMS_GATEWAY=log` send nothing. Set real SMTP
  (`MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`,
  `MAIL_FROM_ADDRESS`) in Render before inviting Reporters.

## 5. Speed: move Supabase to Singapore

The Render server is in Singapore and the current Supabase project is in Seoul,
so every database query and file check crosses that distance — the main reason
pages load slowly. A new Supabase project in **Southeast Asia (Singapore)**
removes it (and replaces the keys that were once pasted in chat).

1. In Supabase, **New project**, region **Southeast Asia (Singapore)**. Create
   bucket **drrms** (Public OFF, 10 MB) and an S3 access key, as in step 1.
2. Copy the database across. On this PC (PostgreSQL client tools installed),
   with the two Session-pooler URIs:
   ```powershell
   pg_dump --no-owner --no-privileges --format=custom --file=drrms.dump "OLD_SEOUL_DB_URL"
   pg_restore --no-owner --no-privileges --clean --if-exists --dbname="NEW_SINGAPORE_DB_URL" drrms.dump
   ```
   Delete `drrms.dump` afterwards: it holds personal data.
3. Copy the files (photos, attachments, stored backups). A normal backup holds
   the rows only, so copy the bucket itself with [rclone](https://rclone.org/s3/#supabase):
   add two S3 remotes (provider `Other`, the old and the new endpoint and keys),
   then `rclone copy seoul:drrms singapore:drrms --progress`.
4. In Render > Environment, replace `DB_URL`, `SUPABASE_S3_ENDPOINT`,
   `SUPABASE_S3_ACCESS_KEY_ID`, `SUPABASE_S3_SECRET_ACCESS_KEY`; in `render.yaml`
   change `SUPABASE_S3_REGION` to `ap-southeast-1` and push. Update `.env.render` too.
5. Check section 6, then pause or delete the Seoul project and revoke its S3 key.

## 6. Check after deploy

1. `/up` returns 200; landing page, login and dashboard load with CSS, icons, map and charts.
2. Log in as the Admin, log out, log in again.
3. Upload an incident photo and generate a PDF with logos and photos.
4. Download a backup.
