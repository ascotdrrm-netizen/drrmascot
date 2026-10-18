# ASCOT DRRMS — User Testing Checklist (UAT)

**Site:** https://drrmascot.onrender.com · **Version tested:** ____________ · **Tester:** ____________ · **Date:** ____________

How to use: do each step, compare with **Expected**, tick **✅ Pass** or **❌ Fail**. For every fail, fill in a bug report (section 9).

---

## 0. Before you start

| # | Check | Done |
|---|---|---|
| 0.1 | Open the site **5 minutes early**. The free plan sleeps after 15 minutes idle; the first load takes about 1 minute. | ☐ |
| 0.2 | Demo accounts and their password: `C:\DRRM\DEMO-ACCOUNTS.txt` (same password for all five). | ☐ |
| 0.3 | Brevo email set in Render (`MAIL_MAILER=brevo`, `BREVO_API_KEY`, `MAIL_FROM_ADDRESS`). Without it, sections 1.5, 1.6 and 1.9 will fail. | ☐ |
| 0.4 | Devices ready: 1 Android phone (Chrome), 1 iPhone (Safari) if available, 1 laptop (Chrome or Edge). | ☐ |
| 0.5 | Know the limits of the demo: SMS is **simulated** (nothing is sent), push notifications are **not built yet**, the server **sleeps** when idle. | ☐ |

**Accounts**

| Role | Email | Login page |
|---|---|---|
| DRRMO Admin | `demo.admin@drrms.test` | /login |
| Coordinator (Zabali) | `demo.zabali@drrms.test` | /login |
| Coordinator (Bazal) | `demo.bazal@drrms.test` | /login |
| Coordinator (Casiguran) | `demo.casiguran@drrms.test` | /login |
| Reporter (Zabali) | `demo.reporter@drrms.test` | /app/login |
| New Reporter | your own real email (created in 1.3) | /app/register |

---

## 1. Public pages, sign-up and login (no account)

| # | Steps | Expected | ✅ | ❌ |
|---|---|---|---|---|
| 1.1 | Open the landing page on a phone and on a laptop. | Loads with logos, hotlines and styling; nothing scrolls sideways. | ☐ | ☐ |
| 1.2 | Open **Hotlines** (`/app/hotlines`). Tap a number. | Numbers are listed; tapping opens the phone dialer. | ☐ | ☐ |
| 1.3 | **Reporter sign-up** (`/app/register`): Student, real email, choose a campus. | The School/course list fills for that campus; privacy consent is required. | ☐ | ☐ |
| 1.4 | Submit the form with mistakes (weak password, empty fields, a different confirm password). | Clear red message under each wrong field; typed data is kept. | ☐ | ☐ |
| 1.5 | Submit a valid form. | "Check your email". A verification email arrives (check spam). | ☐ | ☐ |
| 1.6 | Click the email link, then try to log in. | "Email verified…"; login says it is waiting for coordinator validation. | ☐ | ☐ |
| 1.7 | **Staff registration** (`/register`). | Can only become a Coordinator; the account waits for Admin approval. | ☐ | ☐ |
| 1.8 | Log in with a wrong password 6 times. | "Too many login attempts…" with a wait time. | ☐ | ☐ |
| 1.9 | **Forgot password** with a real account. | A reset email arrives; the new password works; the old one does not. | ☐ | ☐ |
| 1.10 | Log in with **Keep me signed in** ticked, close the browser, reopen the site. | Still logged in. | ☐ | ☐ |
| 1.11 | Log out, press Back. | No private page is shown; you are sent to login. | ☐ | ☐ |
| 1.12 | Open a page that does not exist (`/abc`). | A friendly "Page not found" page. | ☐ | ☐ |

---

## 2. Reporter — phone app (`demo.reporter@drrms.test`)

**Install and basics**

| # | Steps | Expected | ✅ | ❌ |
|---|---|---|---|---|
| 2.1 | Android Chrome: menu → **Install app** / Add to Home screen. iPhone Safari: Share → Add to Home Screen. | App icon appears; opens full screen without the browser bar. | ☐ | ☐ |
| 2.2 | Home screen. | Name, campus status, report shortcuts, recent reports, advisories. | ☐ | ☐ |
| 2.3 | Tap every bottom tab and "See all" / "All types". | Each opens the right screen; buttons are easy to tap. | ☐ | ☐ |
| 2.4 | **Me** screen. | Correct name, campus, school, phone, email. | ☐ | ☐ |

**SOS (most important)**

| # | Steps | Expected | ✅ | ❌ |
|---|---|---|---|---|
| 2.5 | Press **SOS**, allow location, choose a type (Medical…), send. | Confirmation and a status screen (Sent → Acknowledged → …). | ☐ | ☐ |
| 2.6 | Send an SOS with location **denied**. | Still sends; says location not shared. | ☐ | ☐ |
| 2.7 | Staff side: the Coordinator opens **SOS Console**. | The SOS appears with the reporter's name, phone and map pin. | ☐ | ☐ |
| 2.8 | Staff acknowledges and resolves it; reporter refreshes the status screen. | Status updates on the reporter's phone. | ☐ | ☐ |

**Reports**

| # | Steps | Expected | ✅ | ❌ |
|---|---|---|---|---|
| 2.9 | **Report** → Flood: place, description, 3 photos (one over 4 MB), captions, send. | Sent; appears under **My reports** as Submitted. | ☐ | ☐ |
| 2.10 | Report **Bullying**, **Harassment**, **Violence / fight**, **Threat / security concern** (one each). | Each sends and appears in the staff Reports Inbox with the right label. | ☐ | ☐ |
| 2.11 | Try sending without a place or GPS, and a description under 10 characters. | Clear error; nothing is sent. | ☐ | ☐ |
| 2.12 | Try attaching a non-image file as a photo. | Refused with a clear message. | ☐ | ☐ |
| 2.13 | **Offline:** turn on airplane mode, fill a report, send; turn the internet back on with the app open. | The report waits as "queued", then sends by itself; no duplicate appears. | ☐ | ☐ |
| 2.14 | Open a sent report. | Timeline, photos and status are shown. | ☐ | ☐ |

**Alerts, roll call, map**

| # | Steps | Expected | ✅ | ❌ |
|---|---|---|---|---|
| 2.15 | Staff publishes an advisory or broadcast for Zabali; the reporter opens **Alerts**. | It appears; the unread count clears after opening. | ☐ | ☐ |
| 2.16 | Staff starts a **Roll Call**; the reporter answers **Safe**, then changes to **Need help**. | Both answers are saved; staff see the latest one. | ☐ | ☐ |
| 2.17 | **Map** screen. | The map loads with the campus; zoom works. | ☐ | ☐ |
| 2.18 | Campus status set to **Evacuate** by staff. | The reporter home shows the evacuate status and instruction. | ☐ | ☐ |

---

## 3. Coordinator (`demo.zabali@drrms.test`)

| # | Steps | Expected | ✅ | ❌ |
|---|---|---|---|---|
| 3.1 | Dashboard. | Numbers and charts match the Zabali records; only Zabali is shown. | ☐ | ☐ |
| 3.2 | **Pending reporters** → validate the reporter from 1.6. | Moves to "waiting for Admin approval". | ☐ | ☐ |
| 3.3 | **Incidents** → Report incident: type, date, place (Pick on map / Use my location), photos, save. | Saved; visible in the list and on the detail page. | ☐ | ☐ |
| 3.4 | Edit the incident; add a **damage report**. | Changes saved. | ☐ | ☐ |
| 3.5 | **Download PDF** of the incident. | The PDF opens with logos, details, photos and the signatory. | ☐ | ☐ |
| 3.6 | Delete the incident → **Trash** → Restore. | It disappears, then comes back unchanged. | ☐ | ☐ |
| 3.7 | **Buildings/Areas**: add a building with a photo, add a hazard inspection, PDF. | All saved; the PDF is correct. | ☐ | ☐ |
| 3.8 | **Drills**: schedule → documentation → after-activity report → each PDF. | Each step saves; the calendar shows the drill. | ☐ | ☐ |
| 3.9 | **Advisories**: create, view memorandum, delete/restore. | Works; shows on dashboards while active. | ☐ | ☐ |
| 3.10 | **Reports Inbox**: open a report, verify / respond / resolve it. | Status changes; the reporter sees it in their timeline. | ☐ | ☐ |
| 3.11 | **Dispatch**: add a responder, assign to a report/SOS, mark arrived, complete. | Assignment flow works; the responder becomes free again. | ☐ | ☐ |
| 3.12 | **Roll Call**: start, watch answers, close, export CSV. | The CSV opens in Excel with names and answers. | ☐ | ☐ |
| 3.13 | **Campus Status** → Advisory / Suspended / Evacuate with an instruction. | Saved; reporters see it; it appears in the Activity Log. | ☐ | ☐ |
| 3.14 | **Campus broadcasts**: write, publish to Zabali. | Delivery history shows In-app, and email/SMS as Simulated or Accepted. | ☐ | ☐ |
| 3.15 | **Consolidated Report** with filters; download the PDF. | Totals match the lists; the PDF downloads. | ☐ | ☐ |
| 3.16 | **Activity Log**: filter by date and action. | Your actions are listed with the right time (Philippine time). | ☐ | ☐ |
| 3.17 | **Profile**: change photo, name, phone; change password. | Saved; other devices are logged out after the password change. | ☐ | ☐ |
| 3.18 | **Security:** open a Bazal record's address (change the number in the URL). | "You don't have access" (403), never Bazal data. | ☐ | ☐ |

---

## 4. DRRMO Admin (`demo.admin@drrms.test`)

| # | Steps | Expected | ✅ | ❌ |
|---|---|---|---|---|
| 4.1 | Dashboard with the Campus filter (All / each campus). | Numbers change by campus. | ☐ | ☐ |
| 4.2 | **Personnel**: approve the reporter from 3.2. | The reporter can now log in and use the app. | ☐ | ☐ |
| 4.3 | Reject a pending account. | It is removed; the person cannot log in. | ☐ | ☐ |
| 4.4 | Change a user's role; try to remove your own Admin role / delete the last Admin. | Role change works; the last-Admin change is blocked with a message. | ☐ | ☐ |
| 4.5 | Delete a Reporter who has sent reports. | The account is gone; their reports stay and show "Former reporter". | ☐ | ☐ |
| 4.6 | **Settings**: add/rename a school; set the report signatory. | Saved; new PDFs show the signatory; both appear in the Activity Log. | ☐ | ☐ |
| 4.7 | **Hotlines** editor: change a number. | The public Hotlines page shows the new number. | ☐ | ☐ |
| 4.8 | **Archive / Trash**: filter, restore items. | Restored items reappear in their lists. | ☐ | ☐ |
| 4.9 | **Backup**: download (needs the privacy password set in Render). | A ZIP downloads; the action is logged. | ☐ | ☐ |
| 4.10 | **Restore** a backup (only on test data). | Data returns to the backup state. | ☐ | ☐ |
| 4.11 | **Clear records** screen (only on test data). | Asks for confirmation and the privacy password; clears as described. | ☐ | ☐ |
| 4.12 | All campuses are visible in Incidents, Reports Inbox and SOS Console. | Records from every campus are shown. | ☐ | ☐ |

---

## 5. Look and feel (every role)

| # | Check | ✅ | ❌ |
|---|---|---|---|
| 5.1 | **Dark** and **Light** theme (sun/moon button): every page is readable, nothing invisible. | ☐ | ☐ |
| 5.2 | Phone (portrait): no sideways scrolling; menus open; tables scroll inside their box. | ☐ | ☐ |
| 5.3 | Buttons and links are easy to tap with a thumb. | ☐ | ☐ |
| 5.4 | Every form shows a clear error message and keeps what you typed. | ☐ | ☐ |
| 5.5 | Loading: buttons show "Please wait…" while saving; no double submissions. | ☐ | ☐ |
| 5.6 | Empty lists show a helpful message, not a blank page. | ☐ | ☐ |
| 5.7 | Dates and times are Philippine time and readable. | ☐ | ☐ |
| 5.8 | Wording is clear for students and staff (no technical jargon). | ☐ | ☐ |

---

## 6. Speed and reliability

| # | Check | Result |
|---|---|---|
| 6.1 | First visit after 15+ minutes idle: seconds until the page shows. | ______ s |
| 6.2 | Normal page load while awake (dashboard, incident list). | ______ s |
| 6.3 | Sending an SOS: seconds until the confirmation. | ______ s |
| 6.4 | Uploading 3 phone photos in a report. | ______ s |
| 6.5 | Generating the Consolidated Report PDF. | ______ s |
| 6.6 | Any **502 / error page** seen? When and on which page? | ________ |

---

## 7. Security and privacy spot checks

| # | Check | ✅ | ❌ |
|---|---|---|---|
| 7.1 | A Reporter cannot open staff pages (`/admin/dashboard`, `/coordinator/dashboard`). | ☐ | ☐ |
| 7.2 | A Coordinator cannot open Admin pages (`/admin/personnel`). | ☐ | ☐ |
| 7.3 | A copied photo link stops working after a few minutes or in another account. | ☐ | ☐ |
| 7.4 | Logging out on one device does not log out another device (unless the password was changed). | ☐ | ☐ |
| 7.5 | The address bar always shows the padlock (https). | ☐ | ☐ |
| 7.6 | The privacy notice on sign-up is clear about location and data use (RA 10173). | ☐ | ☐ |

---

## 8. Sign-off

| Role tested | Device(s) | Passed / total | Tester | Date |
|---|---|---|---|---|
| Guest | | / | | |
| Reporter | | / | | |
| Coordinator | | / | | |
| Admin | | / | | |

**Overall result:** ☐ Ready for the defense · ☐ Ready after fixes listed below · ☐ Not ready

---

## 9. Bug report template (copy one per problem)

```
Checklist #:      (e.g. 2.13)
Account / role:   (e.g. demo.reporter@drrms.test)
Device / browser: (e.g. Samsung A15, Chrome)
Steps:            1. … 2. … 3. …
Expected:         …
What happened:    …
Screenshot:       (attach)
Time it happened: (date + time, for the server logs)
How bad:          Blocks the task / Annoying / Cosmetic
```
