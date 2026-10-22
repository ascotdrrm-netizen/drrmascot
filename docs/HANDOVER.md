# Handover: ASCOT DRRMS

This guide moves full control of ASCOT DRRMS to the project owners. When it is
done, only the owners can sign in to the services, change the code or reach the data.
Do the parts in order and tick each box.

Live site: https://drrmascot.onrender.com
Code: https://github.com/ascotdrrm-netizen/drrmascot

## What runs where

| Service | What it does | Free-plan limits |
|---|---|---|
| **GitHub** `ascotdrrm-netizen/drrmascot` | The code. A push to `main` runs the tests (Actions), then Render redeploys. | — |
| **Render** `drrmascot` (Singapore) | Runs the website, queue worker and scheduler in one Docker container. | Sleeps after 15 min idle; the first visit takes about a minute. |
| **Supabase** (database + `drrms` bucket) | All records, photos, PDFs and backups. | 500 MB database, 1 GB files. |
| **Brevo** | Sends email: verification, password reset, broadcasts, SOS email. | 300 emails a day. |
| **TextBee** + an Android phone | Sends SOS, verification and broadcast texts from the phone's SIM. | 50 texts a day, 300 a month. Load is paid by the SIM owner. |

The app does not use Supabase Auth, Firebase or Vercel.

---

## Part 1: Before the handover (developer)

- [ ] All fixes are merged to `main`, the Actions check is green and Render shows **Deploy live**.
- [ ] Brevo and TextBee accounts are made with the **owners' own email** (not the developer's). If they were made with another email, change the login email in each account's settings, or make new accounts and replace the keys in Part 4.
- [ ] Give the owners, in person or in a private message, never in a group chat:
  - the GitHub, Render and Supabase sign-in details, if the developer was holding them

---

## Part 2: GitHub (owners)

Sign in as **ascotdrrm-netizen**.

1. **Change the password.** Go to Settings → Password and authentication.
2. **Turn on two-factor authentication** on the same page, and save the recovery codes somewhere safe.
3. **Check who else can push.** Go to the repository → Settings → Collaborators, and remove anyone who should no longer change the code.
4. **Revoke old tokens.** Go to Settings → Developer settings → Personal access tokens (both *Fine-grained* and *Tokens (classic)*), and delete tokens you did not make.
5. **Check sessions.** Go to Settings → Sessions, and revoke sessions on computers you don't know.
6. **Keep Render connected.** Settings → Applications should still list **Render**. Removing it stops deploys.

Past commits keep the name of the person who wrote them; that is normal git history.

---

## Part 3: Supabase (owners)

Sign in to https://supabase.com/dashboard.

1. **Change the account password and turn on two-factor authentication.** Go to Account → Security.
2. **Check members.** Go to Organization → Team, and remove members who should no longer have access.
3. **Reset the database password.** The old one has been shared in chats before. Go to Project Settings → Database → **Reset database password**, and save the new one.
   - Right after, update `DB_URL` in Render (Part 4, step 3), or the site stops working.
   - Get the new `DB_URL` from **Connect → Session pooler** (port 5432). Write special characters in the password as `%40` for `@` and `%23` for `#`.
4. **Replace the file storage keys.** Go to Storage → S3 Configuration → Access keys.
   - Create a new key.
   - Put its two values in Render (Part 4, step 3).
   - Once the site works, delete the old key.
5. **Leave the `drrms` bucket private.** Don't delete it; every photo and backup is in it.

Do not run `migrate:fresh` or `db:seed` against this database. They erase or duplicate live records.

---

## Part 4: Render (owners)

Sign in to https://dashboard.render.com (with GitHub as ascotdrrm-netizen).

1. **Change the account password and turn on two-factor authentication.** Go to Account Settings.
2. **Check members.** Go to Workspace Settings → Members, and remove anyone who should no longer deploy.
3. **Update the secrets.** Go to **drrmascot → Environment**, update the values you replaced, then click **Save, rebuild, and deploy**:

| Key | Where it comes from | Change it? |
|---|---|---|
| `APP_KEY` | Already set | **Never.** Changing it signs everyone out and breaks encrypted data. |
| `APP_URL` | `https://drrmascot.onrender.com` | Only if the address changes. |
| `DB_URL` | Supabase → Connect → Session pooler | Yes, after the password reset (Part 3). |
| `SUPABASE_S3_ENDPOINT` | Supabase → Storage → S3 Configuration | No. |
| `SUPABASE_S3_ACCESS_KEY_ID`, `SUPABASE_S3_SECRET_ACCESS_KEY` | The new key from Part 3 | Yes. |
| `BREVO_API_KEY` | Brevo → SMTP & API → API Keys | Yes, if the developer made the key. |
| `MAIL_FROM_ADDRESS` | The sender email verified in Brevo | Only if the sender changes. |
| `SMS_GATEWAY` | `textbee` (or `log` to turn texts off) | — |
| `TEXTBEE_API_KEY` | TextBee dashboard → API Keys | Yes, if the developer made the key. |
| `TEXTBEE_DEVICE_ID` | TextBee dashboard → the phone | Only if more than one phone is linked. |
| `DATA_PRIVACY_PASSWORD_HASH` | Make your own (below) | **Yes.** Choose your own backup password. |

To make your own backup password hash, run this on a PC with PHP and type the password you choose:

```powershell
php -r "echo password_hash(readline('Password: '), PASSWORD_BCRYPT), PHP_EOL;"
```

Paste the whole `$2y$12$...` line as `DATA_PRIVACY_PASSWORD_HASH`. Backup, Restore
and Clear records will ask for that password.

4. **Check the deploy.** After the deploy, open the live site and sign in. Then open **Backup** and check that it accepts the new password.

---

## Part 5: Brevo and TextBee (owners)

**Brevo**
1. Sign in at https://app.brevo.com and change the password.
2. Click your name → **Senders, Domains & Dedicated IPs**, and check the sender email is one the owners control.
3. Go to **SMTP & API → API Keys** and make a new key. Put it in Render as `BREVO_API_KEY`, then delete the old key.

**TextBee**
1. Sign in at https://textbee.dev and change the password.
2. Make sure the linked phone is one the owners keep. Keep that phone on, charged, online and loaded with an unli-text promo.
3. Make a new API key, put it in Render as `TEXTBEE_API_KEY`, then delete the old key.

---

## Part 6: Accounts inside the app (owners)

1. Sign in as the current DRRMO Admin. Go to **Personnel** and create the real Admin account(s) for the DRRM Office, or confirm they exist.
2. Sign in with the new Admin account, then deactivate or delete any account that should no longer have access, including test accounts.
3. There are no demo accounts: they were removed for good (October 2026). `DEMO_ACCOUNTS_PASSWORD` is no longer used; remove it from Render if it is still set.
4. Every Admin and Coordinator puts a real mobile number in **My Profile** so SOS texts reach them.

---

## Part 7: Making changes after the handover

You need PHP 8.3, Composer, Node 24 and Git on the PC.

```powershell
git clone https://github.com/ascotdrrm-netizen/drrmascot.git
cd drrmascot
composer install
npm install
copy .env.example .env      # then set DB_CONNECTION=sqlite in .env for a local copy
New-Item database\database.sqlite
php artisan key:generate
php artisan migrate --seed   # local SQLite only, never the live database
npm run build
php artisan test             # all tests must pass
php artisan serve            # http://localhost:8000
```

To publish a change:
1. Commit and push to `main`.
2. GitHub Actions runs the tests on SQLite and Postgres.
3. Render deploys only when the tests pass. Watch **Render → drrmascot → Events** for **Deploy live**.
4. If a deploy breaks the site, open **Render → drrmascot → Events**, choose the last good deploy and click **Rollback**.

More detail is in [`RENDER_SUPABASE.md`](RENDER_SUPABASE.md) (hosting) and [`PLAN.md`](PLAN.md) (features).

---

## Part 8: Developer clean-up (developer, after Parts 2–6)

On the developer's PC:
- [ ] Sign out of GitHub in the browser and run `gh auth logout` if the GitHub CLI was used.
- [ ] Remove the saved GitHub login: Windows **Credential Manager** → Windows Credentials → delete entries for `git:https://github.com`.
- [ ] Delete `.env.render` (it holds live secrets).
- [ ] Sign out of Render, Supabase, Brevo and TextBee in the browser.
- [ ] Delete any local copy of a live backup file.

---

## Final checklist

- [ ] GitHub, Render, Supabase, Brevo and TextBee passwords changed by the owners, with two-factor on where offered
- [ ] Database password and S3 keys replaced, and the site still works
- [ ] Brevo and TextBee keys replaced
- [ ] Own backup password set (`DATA_PRIVACY_PASSWORD_HASH`)
- [ ] Real Admin accounts made; old and test accounts removed
- [ ] Developer's PC cleaned up (Part 8)
- [ ] An SOS sent from a Reporter account reaches the Admin and Coordinator by email and text
