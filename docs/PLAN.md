# ASCOT DRRMS: Full Frontend Plan, Feature Gap Review & Tech Stack

## 0. Context
`ascot-drrms` is a working Laravel 12 + Blade system for the ASCOT DRRM Office. It covers 3 campuses: Zabali (Baler), Bazal (Maria Aurora) and Casiguran.

**What it has today (staff-only, roles `DRRMO Admin` / `Coordinator`):**
- Facility Hazard Auditor (buildings and inspections, with photo and GPS)
- Incidents with damage reports and measurements
- Per-incident SMS alerts (Semaphore)
- Disaster Advisories and the Drill Tracker (schedule, documentation, After Activity Report)
- Consolidated and module PDF reports
- Personnel approval, Activity Log, Trash/retention, Backup/Restore, and Help
- A public landing page with hotlines
- Over 60 feature tests

**Goal:** polish it into a MyNaga-style DRRM platform.
- Students and staff get an **installable phone app (PWA)**: SOS, hazard reports with photo and GPS, advisories, safety check-in.
- The DRRMO gets a **command center** to triage, dispatch, account for people and decide on suspensions.
- **Frontend first, on fixture data.** The backend comes in Phase B.

**Decisions so far:**
- Keep Blade and upgrade it.
- New `Reporter` role (students and staff). **Any email address is accepted**, not only ASCOT ones, because not every student has an edu account. The email **must be verified** by a confirmation link before the account can go further.
- **Database: hybrid.** MySQL remains the main database. Firebase (Firestore + FCM, optional phone OTP) adds realtime and push notifications.
- **Hosting: localhost first** (`php artisan serve` + `npm run dev`, MySQL via XAMPP or SQLite), then deploy later. `localhost` counts as a secure context, so the service worker, install, GPS and camera all work on the PC. To test on a phone, use a Cloudflare Tunnel or ngrok HTTPS link to the local server. Live updates use Tier A (polling) until a real host is chosen.
- Visual style from `docs/reference/inspo-dashboard-1.jpg` / `docs/reference/inspo-dashboard-2.jpg`: dark, orange accent, light toggle, applied to every surface.
- Google Material Symbols icons. The UI must not look AI-generated.
- Fully mobile responsive.

**Problems found during the code review (fix in F0):**
- Tailwind is loaded from `cdn.tailwindcss.com` (not for production, no offline). Vite and Tailwind 4 are installed but unused.
- The design system lives inline in `layouts/partials/ui.blade.php`, and the motion CSS is copied into each layout.
- Chart.js and Leaflet load from jsDelivr.
- **No custom error pages** (`resources/views/errors/` doesn't exist).
- **No CI** (the GitHub repo `Sabaok/ascot-drrms` has no `.github/`).
- **Dead code:** `InventorySupplyController`, `RequisitionController`, the `inventory_supplies/` and `requisitions/` views, `InventorySupplySeeder` and 2 exports. Their tables were dropped (`2026_09_21_…drop_inventory_and_requisitions_tables`) and they have no routes. Delete them, or bring them back as the new Equipment module (§4).
- `README.md` is still the Laravel boilerplate.

---

## 1. Two Non-Negotiables

### 1.1 Mobile responsive everywhere
- 360px phones up to 1440px desktops, on every surface.
- Breakpoints: `<640` phone, `640–1024` tablet, `>1024` desktop.
- **Navigation:** desktop gets the full collapsible sidebar. Tablets get an icon rail (inspo 56). Phones get the drawer, and the Reporter app gets a **bottom tab bar**.
- **Tables** become stacked cards under 640px. **Forms** are one column on phones and two on desktop. **Modals** become full-screen bottom sheets on phones.
- 44px minimum tap targets, no sideways scroll, and `env(safe-area-inset-*)` padding for notched phones.

### 1.2 Installable app that opens the website (PWA, not an APK)
- Users open the site and tap **Install** (Android) or **Share → Add to Home Screen** (iPhone). The ASCOT DRRM icon lands on the home screen and opens full screen with a splash screen.
- **No Play Store.** Website updates reach the app automatically.
- **Manifest:**
  - `display: standalone`, theme and background colors, maskable icons.
  - **App shortcuts** (long-press the icon): *SOS*, *Report hazard*, *Hotlines*.
  - **Share target**: share a photo from the Gallery → opens the Report form with the photo attached.
- **Service worker:**
  - Precached app shell, plus hotlines, guides, the offline page and cached campus map tiles.
  - Reports and SOS are **queued offline** and sent automatically when back online.
  - An **"Update available, reload"** banner.
- **Install UX:** an in-app Install button on Android, a how-to sheet on iOS. After the first SOS or report, it asks the user to install.
- After login, each role is sent to its own home: Reporter → `/app`, staff → Command Center.
- **Later, optional:** a Play Store listing via a TWA (Bubblewrap) of the same PWA, with no code changes.

---

## 2. Tech Stack

| Layer | Choice | Why |
|---|---|---|
| Backend framework | **Laravel 12 / PHP 8.2** (keep) | Existing models, services and tests. |
| Database | **MySQL 8** in production, SQLite in dev/tests (keep) | `sql/schema.sql` and backups target MySQL. |
| Views | **Blade + anonymous components** | Server-rendered, fast on cheap phones, and keeps the HTML-asserting tests. |
| CSS | **Tailwind CSS v4 via Vite** | Tokens in `@theme`, dark/light switched by `[data-theme]`. |
| JS | **Alpine.js 3** (+ `focus`, `collapse`, `persist`, `intersect`) | Small (about 15 KB) and keeps working when the page reloads. |
| Build | **Vite 7** + `laravel-vite-plugin`, with code splitting | The map and charts load only on pages that use them. |
| Icons | **Google Material Symbols Rounded** (`@material-symbols/svg-400`) via `<x-icon>` | Inlined SVG: offline, no font flash, `currentColor`. `npm run icons` copies only the icons in use. |
| Fonts | **Inter** (UI) + **Barlow Condensed** (KPI numbers, alert headlines), self-hosted via `@fontsource` | Works offline. Has a signage character without looking generic. |
| Maps | **Leaflet 1.9** + `leaflet.markercluster` + `leaflet.heat`, OSM tiles | Reuses the existing picker and `MapSearchController`. GeoJSON hazard layers. |
| Charts | **Chart.js 4** | Already in use. Gets a shared dark/light theme. |
| Offline | Hand-written `public/sw.js` + **`idb-keyval`** queue | Works on any host, with root scope. |
| Images | **`browser-image-compression`** | About 1600px / 300 KB before upload. EXIF stripped except GPS. |
| Dates | **Day.js** (+ `relativeTime`, `tl` locale), Asia/Manila | "5 min ago", with the exact time on hover or tap. |
| i18n | Laravel `lang/en`, `lang/fil`; JS strings via a JSON bundle | English and Filipino toggle. |
| QR | **`qrcode`** (generate) + **`html5-qrcode`** (scan) | Building QR → prefilled report. Drill and evacuation check-in. |
| Database | **Hybrid: MySQL stays the source of truth + Firebase for realtime** | All records, reports, PDFs, backups and tests stay on MySQL/Eloquent. On every SOS, report, status change or broadcast, Laravel mirrors a small, safe-to-share document to **Firestore** (`kreait/laravel-firebase`). |
| Live updates | **Firestore listeners** (Firebase JS SDK v11, modular, loaded only on live pages) behind the `live.js` interface, with **polling as the fallback** | Instant SOS/report feed in the Command Center, live report status for Reporters, live campus status. No websocket server, works on any host. Browsers **only read** Firestore, limited by role and campus through Firebase custom tokens issued by Laravel. **All writes go through Laravel.** |
| Push notifications | **Firebase Cloud Messaging (FCM)** web push | Push to the installed PWA (Android, iOS 16.4+) and admin browsers. A new SOS or report goes to the Admin and the campus Coordinator. Advisories, suspensions and roll calls go to Reporters. Handled inside the single `public/sw.js`. SMS (Semaphore) stays for people without data. |
| Phone OTP (optional) | **Firebase Auth phone verification** | Verifies a Reporter's phone at registration (anti-prank). Laravel stays the login system; Firebase only proves the user owns the phone. |
| Quality | Pint (existing), **Prettier** + `prettier-plugin-blade`, **ESLint** | Consistent formatting. |
| Tests | PHPUnit (existing) + **Playwright** (E2E, viewports, PWA) + **axe** + Lighthouse CI | |
| CI | **GitHub Actions**: Pint, PHPUnit, `npm run build`, Playwright smoke | No CI exists today. |
| Hosting requirement | **HTTPS** | Needed for GPS, camera, the service worker and install. |

---

## 3. Visual Direction

**Taken from the inspo: structure and palette.** Dark warm charcoal, an orange accent, a collapsible sidebar / icon rail, a top bar with search, theme toggle, bell and avatar, a KPI row with delta pills, a chart card with segmented tabs and one highlighted bar, a right column with a hero card, quick actions and recent activity, and tables with status pills.

| Token | Dark (default) | Light |
|---|---|---|
| Background | `#0b0b0c` flat | `#f7f5f2` |
| Card / raised | `#161617` / `#1e1e20` | `#ffffff` / `#fbfaf8` |
| Border | `white/8%` hairline | `gray-200` |
| Text / muted | `#f4f4f5` / `white/60%` | `gray-900` / `gray-600` |
| Accent | Orange `#f97316`, hover `#ea580c`, small text `#fb923c` | Orange `#ea580c` |
| Danger / SOS | Red `#ef4444` | `#dc2626` |
| Success (Safe/Resolved) | Green `#22c55e` (the ASCOT green carry-over) | `#16a34a` |

- **Radius:** 12px cards, 8px inputs and buttons, full pills only for status chips and segmented controls.
- **Theme:** dark by default, follows `prefers-color-scheme`, remembered in `localStorage`, set by an inline `<head>` script before first paint.
- **Warnings never look like buttons.** Hazards use red/amber + an icon + a label. PAGASA rainfall Yellow/Orange/Red and TCWS 1–5 are **outlined chips with a warning icon and the level name**. The SOS button is **red**.

**Not-AI-looking rules (checked at the F1 design review):**
- No gradients, glows, blur or glassmorphism. Orange is a solid fill on primary buttons and the one highlighted chart bar.
- No filler copy ("Trusted by thousands", "Empower…"). Every card is real data or a real action in DRRMO language ("3 reports waiting for verification", "Zabali: Signal No. 2").
- No emoji, sparkle icons, stock 3D art or avatar rows. Use the real seals, campus photos and map tiles.
- Institutional and information-dense: hairline borders instead of shadows, a visible grid, tabular numbers. A control-room feel.
- One icon set and size (20px UI, 24px nav). Motion is functional only (≤180ms fade/slide), with no shimmer, parallax or floating.
- PDF reports stay as they are: light, printed letterhead.

---

## 4. Feature Gap Review (senior DRRM review)
This is organized by the **4 thematic areas of RA 10121** (Philippine DRRM Act), so the system covers the full DRRM cycle and not only incident logging.

Priority: **M** = must have for launch · **S** = should have · **C** = could have later.

### 4.1 Prevention & Mitigation
| Feature | Status | Pri |
|---|---|---|
| Building hazard inspections with risk levels | ✅ exists | n/a |
| **Hazard exposure map**: buildings over flood/landslide/storm-surge susceptibility layers (MGB / Project NOAH / SFES GIS GeoJSON) | new | M |
| **Hotspot heatmap** of past incidents and reports | new | S |
| **Building QR codes**: printable QR per building/room. Scanning one opens the Report form with the location prefilled | new | S |
| **Safety equipment register**: fire extinguishers, first-aid kits, AEDs, go-bags, with **expiry/inspection due dates** and overdue warnings (replaces the dead Inventory code) | new | S |

### 4.2 Preparedness
| Feature | Status | Pri |
|---|---|---|
| Drill Tracker | ✅ exists | n/a |
| Disaster Advisories | ✅ exists (staff-only) → **public read view** | M |
| **Preparedness guides**: Before / During / After per hazard, in English and Filipino, offline | new | M |
| **Hotlines page**, standalone and offline, tap to call | ✅ landing section → own page | M |
| **Evacuation plans**: assembly points and evacuation centers per campus, with capacity, routes, and a floor-plan image per building | new | M |
| **Weather & earthquake widget** per campus: Open-Meteo rain/wind forecast + USGS/PHIVOLCS quake feed within 150 km. Advisories are still issued by people | new | S |
| **Drill QR check-in**: students scan the QR at an assembly point, giving automatic participation counts for the After Activity Report | new | S |
| **Training records**: personnel first-aid/BLS/ERT trainings and expiry | new | C |
| **Document library**: contingency plans, SOPs, memos, downloadable and offline | new | S |

### 4.3 Response
| Feature | Status | Pri |
|---|---|---|
| Incidents + damage reports | ✅ exists | n/a |
| **Reporter accounts + hazard reporting** (photo, GPS, category, urgency) | new | M |
| **One-press SOS** (5 s cancel countdown), GPS, and an offline SMS/call fallback | new | M |
| **Reports Inbox triage**: verify, merge duplicates, reject, convert to Incident | new | M |
| **Responder dispatch**: assign to Security / ERT / Clinic, with **response-time tracking** (reported → acknowledged → on scene → resolved) | new | M |
| **SOS Console** with an audible alarm and escalation to BFP / MDRRMO / PNP (numbers from `config/hotlines.php`) | new | M |
| **Safety Check / Roll Call**: "I'm safe / I need help", plus a live tally by campus, building and college | new | M |
| **Campus Status & class suspension board**: Normal / Advisory / Classes suspended / Evacuate, shown everywhere | new | M |
| **Broadcast composer**: SMS + in-app (+ push in Tier B) to an audience by campus or role, with templates in English and Filipino | extends `AlertController` | M |
| **Evacuation center occupancy**: live headcount against capacity | new | S |
| **Vulnerable persons registry**: opt-in, self-declared PWD, pregnant or medical needs, so responders prioritize them during evacuation (DPA consent, restricted access) | new | S |
| **Missing / unaccounted list**, generated from roll-call non-responders | new | S |
| **Situation Report (SitRep)** generator: NDRRMC-style PDF from live data | new | S |
| **Incident timeline / log** (ICS-style entries per incident) | new | S |

### 4.4 Rehabilitation & Recovery
| Feature | Status | Pri |
|---|---|---|
| Damage reports | ✅ exists | n/a |
| **Recovery tracker**: damaged items → repair status, cost estimate, photos before and after | new | S |
| **Post-event survey** for students (psychosocial check-in, feedback) | new | C |

### 4.5 Platform & governance
| Feature | Status | Pri |
|---|---|---|
| **Data Privacy Act (RA 10173)**: consent at registration, explicit location consent, a privacy notice page, and a "Download/Delete my data" request | new | M |
| **Anti-prank controls**: phone OTP verification, rate limits, a reporter trust score, and a block/suspend action | new | M |
| **Notification center**: in-app inbox, unread badge, per-type preferences | new | M |
| **Global search / command palette** (`Ctrl K`): incidents, reports, buildings, advisories, people | new | S |
| **Emergency (lite) mode**: when Campus Status = Evacuate, the public homepage becomes a lightweight text page with status, instructions, assembly points and hotlines | new | S |
| **Analytics**: response times, reports by type/campus/month, drill participation, the existing dashboard analytics extended | partly | S |
| Activity log, trash, backup | ✅ exists | n/a |

### 4.6 Panel evaluation feedback (new items only; anything the plan already covered is left out)
| # | From | Panel suggestion | What we build | Phase |
|---|---|---|---|---|
| 1 | Educ | Accounts must use an ASCOT email | **Changed:** any email, but it **must be verified** by a confirmation link (not every student has an edu account) | F4 / B |
| 2 | Educ | Coordinator recommends their replacement | **Coordinator handover:** a Coordinator nominates a successor from approved users → Admin approves → the role transfers and the old account becomes a regular user. Logged in the Activity Log | F7 / B |
| 3 | Educ | Red alert email | **Email channel for Red-level alerts:** when an advisory is Red (rainfall) or TCWS 3+, or an Evacuate status is set, email goes to every verified user of the affected campus, alongside SMS | F6 / B |
| 4 | Accountancy | Coordinator activates first, then Admin approves | **3-step account approval:** (1) email verified → (2) **campus Coordinator validates** (confirms the person is really from that campus) → (3) **Admin approves**. Status chips on the Personnel/Reporters pages show the stage; each step notifies the next approver | F7 / B |
| 5 | Accountancy | SMS for every emergency, per campus | **Automatic campus-wide SMS** when an emergency is declared or an SOS is verified for a campus. A "Notify campus by SMS" toggle defaults to on, with a cost/recipient count preview | F6 / B |
| 6 | Accountancy | Accountancy missing from the course options | Add Accountancy to `School::CATALOG` (confirm the campus with the DRRMO). Better: **Schools/Courses become admin-editable** (Settings → Campuses & Schools) so a missing one never needs a code change | F0.5 / B |
| 7 | Accountancy | Help page wording | **Rewrite the Help page** in plain, step-by-step English + Filipino (numbered steps, screenshots, "What to do if…" FAQ) | F7 |
| 8 | Accountancy | Optional proof file upload | **Optional "Proof / supporting file"** (photo or PDF, up to 3 files) on reports, incidents, damage reports and SOS follow-up | F5–F7 / B |
| 9 | Accountancy | Change the typhoon advisory | **Typhoon advisory redesign** following PAGASA bulletin fields: storm name (local + international), category, TCWS per campus (campuses can differ), location of center, movement, max winds, gustiness, expected landfall, rainfall outlook, bulletin no. and time issued. The advisory PDF memorandum is restyled to match | F7 / B |
| 10 | Accountancy | A report of only one coordinator's records | **"Prepared by" filter in report generation:** a Coordinator's own records only, or Admin picks one coordinator. Uses the existing `created_by` columns | F7 / B |
| 11 | Engineering | Coordinator and Admin can edit hotlines | **Hotlines move from `config/hotlines.php` into the database.** Admin edits all hotlines; a **Coordinator edits their own campus's hotlines**. Changes are logged. The config file becomes the seeder | F6 / B |
| 12 | Engineering | Prepared by and Noted by must be different | The **PDF signature block** never shows the same person twice. "Noted by" becomes a **configurable official** (name + position, e.g. the DRRMO Head or the VP), set in Settings. If the preparer is that official, the next official in the list is used. Validated in tests | F7 / B |
| 13 | Engineering | Description for pictures | **Caption field on every uploaded photo** (incidents, inspections, buildings, drills, reports): "What happened in this photo". Shown under the photo and in PDFs | F5–F7 / B |
| 14 | Engineering | Map below the geotag coordinates | A **map preview under every latitude/longitude**, on forms (live pin) and on show pages and PDFs (static map image) | F7 |
| 15 | Engineering | Measurements optional | Incident measurements are **already optional** in code. Make the **advisory** ones optional too (today `wind_signal` and `magnitude` are required in `config/hazard_measurements.php`) | F0.5 |
| 16 | Engineering | Alert the Admin on every report | **Every new report and SOS notifies the Admin and that campus's Coordinator immediately**: in-app alarm + bell badge, SMS for SOS, email for urgent reports | F6 / B |
| 17 | Engineering | Contact number is required at registration but has no asterisk | **Bug fix:** add the required asterisk to `contact_number` in `auth/register.blade.php`. Audit every form so required fields always show `*` (one `x-label :required` component) | F0.5 |
| 18 | Arts & Sciences | Count of people in the accident | **Casualty counts on incidents and reports:** affected, injured, missing and dead (all optional, numbers). Shown in the incident detail, dashboard KPIs, SitRep and PDFs | F5–F7 / B |
| 19 | Bazal | Letters must be professional, headings at the top | **Professional letter formatting for every generated memo/letter PDF** (drill memorandum, advisory memorandum, requisition-style letters): letterhead and heading at the top, formal salutation/closing wording reviewed. Also applies to the thesis questionnaire documents | F7 |
| 20 | Bazal | No immediate action for transportation problems in Bazal | **Transportation emergencies:** a "Transportation / vehicle" emergency category, plus a **campus vehicle & ambulance register** (vehicle, driver, contact, available or not) so the DRRMO can dispatch transport immediately. Bazal transport and ambulance numbers go in the campus hotlines | F6 / B |
| 21 | IT experts | Notify and refresh when an upload doesn't finish | **Reliable uploads:** per-file progress bar, automatic retry ×3, a clear "Photo 2 failed to upload, tap to retry" message, **the form is never lost** (draft kept in IndexedDB), and a server-side check that every file arrived before showing success | F1 / F5 |
| 22 | IT experts | One Trash for all deleted items, with filtering | **One unified "Archive" page** replaces the 5 separate trash pages. It filters by module (Buildings, Inspections, Incidents, Drills, Advisories, Reports…), campus, deleted by and date, with restore and bulk restore. The retention rules in `TrashPurge` stay | F7 |
| 23 | IT experts | Password can't be copied into the confirmation field | Password fields **block copy and cut**, and the **Confirm password** field **blocks paste**. Paste stays allowed in the main password field so password managers still work | F0.5 |
| 24 | IT experts | Recovery email | **Recovery email** (a second, verified address) set in profile and registration. "Forgot email?" finds the account via recovery email or the verified phone (OTP) | F4 / B |

**Not system features; tracked for the thesis manuscript:**
- **Delimitations:**
  - No native APK / App Store app: it's an installable PWA (a store listing via TWA is possible later).
  - **With no cell signal or power, nothing can be transmitted.** The app still opens offline, showing hotlines, guides and assembly points, and queued reports send when signal returns. SMS fallback needs cell signal but no data.
- **Recommendations for future researchers:** native apps, AI triage of photos, IoT sensors (rain or water-level gauges on campus), LoRa/mesh messaging for no-signal SOS, integration with the NDRRMC and PAGASA APIs.
- **Copyright/patent:** ask Sir Sean whether to register a copyright (IPOPHL) for the system. Not a code task.

---

## 5. Screen Inventory (sitemap)

### A. Public site (`layouts.public`, no login)
| Route | Screen |
|---|---|
| `/` | Landing: campus status strip, active advisories, SOS/Report and Install buttons, hotlines, guides, weather |
| `/advisories`, `/advisories/{id}` | Public advisories, with share and OG tags for Facebook |
| `/hotlines` | Emergency hotlines (offline) |
| `/guides`, `/guides/{hazard}` | Preparedness guides (offline) |
| `/evacuation` | Assembly points and evacuation centers map + list per campus |
| `/status` | Campus status page (also the emergency lite-mode page) |
| `/privacy` | Data privacy notice |
| `/install` | How to install the app (Android / iPhone) |
| `/login`, `/register`, `/forgot-password`, `/reset-password` | Auth (restyled). Register adds the Reporter path, consent and OTP step |

### B. Reporter PWA (`layouts.mobile`, role Reporter). Bottom tabs: **Home · Report · Map · Alerts · Me**
| Route | Screen |
|---|---|
| `/app/welcome` | First-run onboarding: 3 short cards (what it is, location permission, notifications) + install prompt |
| `/app` | Home: campus status card, **SOS**, quick-report tiles, active advisories, roll-call banner, my recent reports, weather |
| `/app/sos` → `/app/sos/{id}` | **One press** of SOS starts a 5-second countdown with a big **Cancel** button, then it sends automatically (one press, but pocket taps can still be undone) → "Help is coming" tracker (timeline, elapsed time, call buttons, cancel with reason) |
| `/app/report` | Report wizard: What → Where (GPS / map / building / QR) → Details (photos, description, voice, injured?) → Review |
| `/app/report/scan` | Scan a building QR code |
| `/app/reports`, `/app/reports/{id}` | My reports + detail timeline |
| `/app/map` | Hazard map: layers, evacuation points, locate me, route to the nearest assembly point |
| `/app/alerts`, `/app/alerts/{id}` | Notification inbox: advisories, broadcasts, suspensions, report updates |
| `/app/check-in/{rollcall}` | Safety check-in: Safe / Need help (+ location, note) |
| `/app/drill/{drill}/check-in` | Drill QR check-in confirmation |
| `/app/guides`, `/app/hotlines` | Same content as public, inside the app shell |
| `/app/me` | Profile, campus, phone (verified badge), language, theme, notifications, vulnerable-person info (opt-in), privacy and data request, logout |
| `/offline` | Offline fallback page |

### C. Command Center (`layouts.app`, Admin / Coordinator; Coordinator limited to their campus)
| Route | Screen |
|---|---|
| `…/dashboard` | **Operations dashboard** (the inspo layout): KPIs, incidents/reports chart, live map, campus-status hero card, quick actions, recent activity, SOS alarm banner |
| `…/reports` | **Reports Inbox**: list + map split view, filters, detail drawer, triage actions, bulk actions |
| `…/sos` | **SOS Console**: active cards, timers, acknowledge, assign, escalate |
| `…/dispatch` | Responder board: on-duty responders, assignments, response-time stats |
| `…/roll-calls`, `…/roll-calls/{id}` | Start a roll call, live tally, needs-help list, unaccounted list, export |
| `…/campus-status` | Status and suspension board + hazard exposure map |
| `…/broadcasts`, `…/broadcasts/create` | Composer (audience, channel, template, preview, SMS cost estimate) + history |
| `…/evacuation-sites` | Manage assembly points and centers, capacity, live occupancy |
| `…/equipment` | Safety equipment register + due/overdue list |
| `…/sitreps` | Generate a SitRep PDF |
| `…/documents` | Document library |
| `…/reporters` | Reporter accounts: verify, block, trust score (tab in Personnel) |
| `…/vulnerable` | Vulnerable persons registry (Admin only, access logged) |
| Existing | Buildings/Inspections (+ QR print), Incidents (+ timeline), Drills (+ QR check-in), Advisories, Consolidated Reports, Personnel, Activity Log, Backup, Help, Profile: **restyled** |
| `…/archive` | **Unified Archive** (replaces the 5 trash pages): filter by module, campus, deleted by and date; restore and bulk restore |
| `…/hotlines` | Hotline editor: Admin edits all, Coordinator edits their own campus |
| `…/vehicles` | Campus vehicle & ambulance register (transport dispatch) |
| `…/settings` | Admin: campuses, schools/courses, report signatories ("Noted by" officials), SMS/email channels |
| `…/personnel/{id}/handover` | Coordinator nominates a successor → Admin approves |
| `/preview/*` | Fixture prototype + `/preview/ui` style guide (local only) |

### D. System pages
- `errors/403`, `404`, `419` (session expired: "Sign in again", which matters for a long-lived PWA), `429` (too many reports), `500`, `503` (maintenance).
- All are themed and give a clear way back, with hotlines on every error page.

---

## 6. Frontend Essentials Checklist (applies to every screen)
- **The 6 UI states:** loading (static skeleton, no shimmer), empty (with a next action), error (with retry), offline (banner + queued badge), permission denied (GPS, camera, notifications, with how to re-enable and a manual fallback such as picking a building), and success (toast + where to see it).
- **Forms**
  - Inline validation (server errors mapped to fields, plus HTML5 + Alpine client checks).
  - Disable on submit (no double submit), upload progress with retry.
  - **Draft autosave** for long forms (report, incident, advisory).
  - **Unsaved-changes guard.** Required fields marked. Phone input formatted `+63 9XX XXX XXXX`.
- **Navigation:** breadcrumbs on desktop. Back buttons that behave correctly in standalone PWA mode (no browser back button). Deep links from notifications to the exact record. Active state in the nav.
- **Session:** CSRF token refresh for long-open PWA tabs, a friendly 419 page, a remember-me option for Reporters, and session expiry warning before it happens for staff.
- **Live data:**
  - `live.js` polling that pauses when the tab is hidden.
  - **`aria-live`** announcements for new SOS and reports.
  - SOS alarm sound that needs one "Enable sound" click (browser autoplay policy), and `navigator.vibrate` on phones.
- **Accessibility (WCAG 2.2 AA):** keyboard reachable, focus trapped in modals and drawers, visible focus ring, labels on icon buttons, never color alone, 200% text zoom, reduced motion, contrast checked in **both themes**.
- **i18n:** every string goes through `__()`. English and Filipino. Asia/Manila time. Dates as "Okt 14, 2026 · 9:30 AM". Relative times in `fil`.
- **Performance budgets (slow 4G, mid-range Android):** LCP < 2.5 s on Reporter Home. JS < 100 KB gzipped on Reporter pages (map and charts loaded on demand). Images lazy-loaded with `srcset`. Fonts subset and `font-display: swap`.
- **Security (frontend side):**
  - Everything escaped (`{{ }}` only, no `{!! !!}` with user content).
  - A Content Security Policy header. Inline scripts move into Vite modules over time.
  - Uploaded photos served through authorized routes, as the existing `RecordAccess` does.
  - No personal phone numbers on public pages (keeps the existing `LandingOfficerTest` rule).
- **Sharing and SEO:** OG/Twitter meta on public advisories and status so Facebook shares show the DRRM card. `robots.txt` blocks `/app` and the command center.
- **Print:** print stylesheet for the roll-call list, SitRep preview and QR sheets.
- **Browser support:** Android Chrome 90+, iOS Safari 15.4+, current desktop Chrome, Edge, Firefox and Safari.
- **Error telemetry:** `window.onerror` / `unhandledrejection` → a small `/client-errors` log endpoint (Phase B).

---

## 7. Design System & Code Structure
- **`resources/css/app.css`:** `@theme` tokens (§3), base styles, the component classes moved out of `ui.blade.php`, motion, print.
- **Blade components** in `resources/views/components/`:
  - **Primitives:** `icon`, `button`, `icon-button`, `input`, `select`, `textarea`, `checkbox`, `radio-cards`, `phone-input`, `file-input`
  - **Layout:** `card`, `stat-card`, `section`, `page-header`, `top-bar`, `sidebar`, `icon-rail`, `bottom-nav`, `drawer`, `bottom-sheet`, `modal`, `tabs`, `segmented`
  - **Feedback:** `toast`, `alert-banner`, `empty-state`, `skeleton`, `offline-banner`, `update-banner`, `confirm-dialog`
  - **Data:** `table` (becomes cards on phones), `status-chip`, `advisory-level`, `risk-badge`, `timeline`, `avatar`, `filter-bar`, `pagination`
  - **Domain:** `sos-button`, `campus-status`, `report-card`, `map`, `photo-uploader`, `stepper`, `qr-code`, `weather-card`, `theme-toggle`, `lang-toggle`, `install-card`
  - The existing `card`, `badge`, `stat-card`, `empty-state`, `confirm-dialog`, `filter-bar`, `school-select` and `back-link` are restyled with the same APIs, so existing views keep working.
- **JS** in `resources/js/`:
  - `app.js` (Alpine bootstrap, theme, i18n)
  - `modules/`: `map.js` (pulled out of `building/_location-fields`), `charts.js`, `sos.js`, `report-wizard.js`, `offline-queue.js`, `image-compress.js`, `live.js`, `install-prompt.js`, `qr.js`, `command-palette.js`, `draft-autosave.js`, `geolocate.js`
- **Layouts:**
  - `app` (command center, restyled)
  - `public` (restyled)
  - `guest` (auth, restyled)
  - **`mobile`** (new Reporter shell)
  - `print` (new)

## 8. Fixture-First Approach
- `/preview/*` routes, local environment only, render every new screen from `app/Support/Preview/Fixtures.php`: realistic ASCOT data (campuses, buildings, reports, SOS, roll calls, statuses). This gives a clickable prototype on phone and desktop to show the DRRMO before any backend work.
- In Phase B each preview screen is wired to its real controller and the fixtures are deleted.

---

## 9. Build Order

**Phase F: Frontend** (each step ends with `composer test` green + `npm run build`)

| Step | Deliverable |
|---|---|
| **F0 Foundation** | Vite build in all layouts (CDN removed), CSS moved to `app.css`, npm Chart.js/Leaflet, Alpine, fonts, `<x-icon>` Material Symbols, Prettier/ESLint, **GitHub Actions CI**, dead Inventory/Requisition code removed. No visual change. |
| **F0.5 Panel quick fixes** | Small changes to show the panel right away: contact-number asterisk + `x-label :required` audit (#17), optional advisory measurements (#15), password copy/paste rules (#23), Accountancy added to the course list (#6). Each gets a regression test. |
| **F1 Design system** | Dark/light tokens + toggle, the full component library, `/preview/ui` style guide in both themes. Update the ~11 tests that assert old classes. **Design review against the not-AI rules and the inspo.** |
| **F2 Shells** | Restyled `app` shell (sidebar, icon rail, drawer, top bar, `Ctrl K` palette), the new `mobile` shell (bottom nav), `public` and `guest`. Error pages 403/404/419/429/500/503. |
| **F3 PWA** | Manifest, icons, shortcuts, share target, `sw.js`, offline page, install prompt and iOS sheet, update banner, offline queue. |
| **F4 Public site** | Landing redesign, advisories, hotlines, guides, evacuation, status/lite mode, privacy, install page, restyled auth + Reporter registration (consent, OTP screen). |
| **F5 Reporter app** | Onboarding, Home, SOS + tracker, report wizard + QR scan, my reports, map, alerts inbox, roll-call check-in, drill check-in, Me. |
| **F6 Command Center (new)** | Operations dashboard, Reports Inbox, SOS Console, Dispatch board, Roll Calls, Campus Status, Broadcasts, Evacuation sites, Equipment, SitRep, Documents, Reporters, Vulnerable registry. |
| **F7 Existing modules restyle** | Buildings (+ QR print), Incidents (+ timeline), Drills (+ QR check-in), Advisories, Reports, Personnel, Activity Log, Backup, Help, Profile: mobile cards, new components, both themes. |
| **F8 Hardening** | `lang/fil` complete, accessibility pass, performance budgets, Playwright suites, Lighthouse CI, print styles, README rewrite. |

**MVP cut**, if time is short (e.g. for a thesis defense): F0 → F3, then **M** items only: Landing, Hotlines, Guides, Reporter Home/SOS/Report/My Reports/Alerts, Reports Inbox, SOS Console, Roll Call, Campus Status, Broadcast. **Also all 24 panel items in §4.6**, since the panel will check for them at the next defense.

**Phase B: Backend** (planned in detail after Phase F)
- **Migrations:**
  - `reporters` (or Reporter role on `personnel`), `phone_verifications`
  - `citizen_reports`, `report_photos`, `report_events`, `sos_events`
  - `responders`, `assignments`
  - `roll_calls`, `roll_call_responses`
  - `campus_statuses`, `evacuation_sites`, `site_occupancy`
  - `broadcasts`, `notifications`
  - `equipment`, `documents`, `hazard_layers`, `vulnerable_profiles`, `recovery_items`
  - `drill_checkins`, `building_qr_tokens`
  - From the panel feedback:
    - `hotlines` (seeded from the config)
    - `vehicles`
    - `signatories`
    - Account approval stage columns: `email_verified_at`, `validated_by_coordinator_at`, `approved_at`
    - `recovery_email`
    - `coordinator_handovers`
    - Photo `caption` columns
    - `attachments` (polymorphic proof files)
    - Casualty count columns on incidents and reports
    - Admin-editable `schools`/courses
    - Typhoon advisory fields
- **Services:**
  - Reporter auth + OTP (SMS via `SmsGatewayInterface`) and rate limiting.
  - JSON endpoints for the PWA, plus offline-queue sync with idempotency keys.
  - `live` polling endpoint, SMS escalation.
  - Rule-based English/Filipino triage tags (#FlashFlood, #MedicalEmergency, #StructureDamage, #EvacuationNeed), with LLM triage optional later.
  - SitRep PDF (reuses the DomPDF report infrastructure).
  - Weather and quake fetch on a schedule.
  - **Firebase integration**
    - `kreait/laravel-firebase`, with the service-account file path in `.env` and the file never committed.
    - **`FirestoreMirror` service:** model observers write the live docs `sos/{id}`, `reports/{id}`, `campus_status/{campus}` and `broadcasts/{id}`, queued with a sync fallback.
    - **`FcmNotifier`**, with device tokens in a new `push_subscriptions` table.
    - A **custom-token endpoint** gives each signed-in user a Firebase token with role and campus claims.
    - `firestore.rules` and `firebase.json` are kept in the repo and tested with the Firebase Emulator.
- **Tests:** feature tests for every endpoint and permission.

---

## 10. Critical Files
- **Modify:**
  - `resources/views/layouts/{app,public,guest}.blade.php`, `layouts/partials/ui.blade.php` (its content moves to CSS)
  - `resources/css/app.css`, `resources/js/app.js`, `vite.config.js`, `package.json`
  - `resources/views/landing*.blade.php`, `{admin,coordinator}/dashboard.blade.php`, `dashboard/_*.blade.php`
  - `resources/views/components/*`
  - `routes/web.php`
  - The tests asserting old classes (`SidebarPhotoTest`, `ResponsiveLayoutTest`, `LandingSealsTest`, `GuestSealsTest`, `SchoolAttributionTest`, `DrillModuleTest`, …)
- **New:**
  - `layouts/{mobile,print}.blade.php`
  - `resources/views/{reporter,public,command,preview,errors}/**`
  - `resources/js/modules/*`, `resources/icons/`
  - `public/{sw.js,manifest.webmanifest,icons/*}`
  - `lang/{en,fil}/*`
  - `app/Support/Preview/Fixtures.php`
  - `.github/workflows/ci.yml`, `playwright.config.js`, `tests/e2e/*`
- **Delete:** `InventorySupplyController`, `RequisitionController`, `views/{inventory_supplies,requisitions}`, `InventorySupplySeeder`, `Exports/RequisitionsExport.php` (confirm with the DRRMO first; they may become Equipment).
- **Reuse:**
  - `config/{hotlines,incident_types,hazard_measurements,campus_map,map,glossary}.php`
  - `MapSearchController`, `building/_location-fields` (map picker)
  - `HandlesCampusScoping`, `RecordAccess`, `SmsGatewayInterface`
  - `DashboardAnalyticsService`, the PDF report partials

## 11. Verification
- **Every step:** `composer test` green, `npm run build` OK, and a test asserting no `cdn.tailwindcss.com` / jsDelivr references.
- **Responsive:** Playwright screenshots of every `/preview/*` page at 360, 390, 768, 1024 and 1440px, in dark and light, with no horizontal overflow. This extends `ResponsiveLayoutTest`.
- **Accessibility:** axe in Playwright with zero serious violations in both themes, plus a manual keyboard-only pass of SOS, the report wizard and the inbox.
- **PWA, on a real phone over HTTPS** (Cloudflare Tunnel or ngrok to `php artisan serve`):
  - Install, standalone launch, app shortcuts, share-to-report.
  - Airplane mode → submit a report → reconnect → it syncs.
  - The update banner appears after a deploy.
- **Lighthouse CI** on Landing, Reporter Home and the report wizard: performance ≥ 85, accessibility ≥ 95, best-practices ≥ 95, installable.
- **Design review:** the `/preview/ui` and dashboard screenshots are checked side by side with the inspo images and the not-AI rules before F4 starts.

## 12. Questions for the DRRMO (Mr. Abordo), in the codebase's "FOR DRRMO REVIEW" style
1. Who responds on each campus (Security, ERT, clinic), and what are their numbers? This drives the Dispatch board and SOS routing.
2. The official assembly points and evacuation centers per campus, with capacities.
3. Are hazard susceptibility GeoJSON files available from SFES or MGB?
4. Who may declare a class suspension or evacuation in the system: Admin only, or campus Coordinators too?
5. Should the Inventory/Requisition idea come back as the Safety Equipment register?
6. Is the vulnerable persons registry approved under the school's data privacy policy?
7. Which campus offers Accountancy, and what is the exact school or program name?
8. Who are the "Noted by" signatories for each report type (name and position)?
9. Campus vehicles, drivers and ambulance contacts, especially for Bazal.
