# Backend handoff

Database migrations and presentation seeding are pending at the user's request. Feature tests run against an isolated in-memory SQLite database.

## Selected demo hosting

The user selected Vercel + Supabase with a free demo plan. See [VERCEL_SUPABASE.md](VERCEL_SUPABASE.md) and `deploy/vercel.env.example`. Source preparation includes the PHP entry point, private direct upload tickets, cloud-backed stored ZIPs/large downloads, and one external broadcast delivery per staff dashboard poll. Real deployment/build and service smoke checks are pending; no live migration has been run. The worker/scheduler instructions below apply to a conventional host or a separate local worker.

Hosting preparation verification: `composer test` passed 571 tests / 28,069 assertions on isolated SQLite (430.76 seconds); `npm run test:queue` passed 9 tests. PHP lint, new-file Pint checks, JavaScript syntax, Blade compilation and `git diff --check` passed. Composer's test script now disables its 300-second subprocess timeout so the complete PDF suite can finish. `npm run build` reached asset copying, then the managed Windows environment denied the esbuild subprocess (`spawn EPERM`); no production frontend build or browser/cloud smoke check is claimed.

## Connected workflows

- Private uploads use signed links bound to their viewer; protected pages and file responses use `no-store`.
- Reporter registration requires email verification, campus validation and Admin approval. An Admin can promote an approved student officer to Coordinator and assign their campus/school.
- Reporter reports and SOS have idempotency keys, campus permissions, staff notifications and status transitions.
- Staff Roll Call: `/admin/roll-calls` or `/coordinator/roll-calls`. One open call per campus. Its roster contains approved, verified Reporters at start; later account changes preserve the expected total. Reporters can update Safe/Need help until staff closes it. Help responses notify campus staff; CSV export is logged.
- Dispatch: `/admin/dispatch` or `/coordinator/dispatch`. Register responders, set duty status, assign a verified report or acknowledged SOS, record arrival, complete/cancel. Each responder can have one active assignment on their campus. Completing an assignment releases the responder; staff closes the case separately. Closing a case cancels its remaining active assignments.
- Incident list uses photo cards and includes an AJAX upload form. Uploads roll back on failed storage; retries use an idempotency key. The grid, metrics, roll-call totals and notification bell update every 15 seconds while visible. SOS screens poll every 10 seconds.
- Successful new/stored database backup downloads appear in the activity log.
- Building/area descriptions appear on forms, detail pages and the building PDF.
- People metrics preserve unknown versus zero counts. Dispatch arrival metrics show their recorded sample size and time window.
- Offline report queue: the signed-in Reporter wizard saves text, photos, captions, proof and GPS accuracy in IndexedDB. Entries are bound to the account and campus, use upload leases across tabs, and keep their client UUID through retries. The sync endpoint rejects a different signed-in owner/campus before storing files. Files are released only after the server confirms a report ID. Local validation failures can be edited; authentication failures pause retries. An online submission can still send directly if browser storage is unavailable.
- Campus broadcasts: `/admin/broadcasts` and `/coordinator/broadcasts`. Draft → saved preview with recipient counts/SMS estimate → publish. A changed message/audience requires a new preview. Approved accounts on the selected campus receive in-app notices; verified email/mobile contacts can receive optional email/SMS. Duplicate destinations are excluded. Publication and explicit retries are logged.
- Broadcast delivery history distinguishes Pending, Processing, Accepted, Simulated, Failed, Unknown and Skipped. Only explicit rejections can be retried. Timeout/crash results and restored pending deliveries require provider reconciliation. A changed/revoked account or contact is skipped before sending. Restoring a backup never automatically resends its pending external messages.
- Reporters receive their own report/SOS status notifications under Alerts, with a live unread badge and links to the relevant case. Broadcasts are readable only by their original campus recipients.
- Phone verification uses a hashed six-digit code, five-minute expiry, five attempts, a resend cooldown and hourly/request throttles. It is available under Reporter Me and staff Profile once real SMS is configured. Changing a phone/email clears its verification. Staff can verify email from Profile; Reporters can resend a verification email from the login screen. Password reset continues to use the primary account email.

## Offline behavior and broadcast operations

- Open the report wizard while signed in before losing signal. Drafts and queued files survive closing the page. Reopen online with the same account to resume. Protected pages are not cached, so the wizard cannot be newly opened offline after a cold launch. This implements retry while the app is open; it does not promise operating-system background delivery after the app is closed. The pending list explains this behavior.
- Browser storage failures are shown; the app never claims an offline report was saved when its write failed. Local drafts/queued reports remain on that device across logout so the same account can resume them.
- After the deferred migrations and service setup, use a persistent queue connection and run `php artisan queue:work --tries=1 --timeout=90` plus Laravel's scheduler. `broadcasts:resume` recovers pending work and marks interrupted requests Unknown. Pause workers while restoring operational data.
- `MAIL_MAILER=log`/`array` and `SMS_GATEWAY=log` produce Simulated history, not real messages. OTP is disabled for the log driver. Configure a direct email transport and Semaphore credentials to test real acceptance. Fallback mail transports have an Unknown result because this adapter cannot determine which transport was used.
- Optional `SMS_PRICE_PER_SEGMENT` is a PHP amount from the actual provider agreement. A blank rate shows “Rate not configured.” The encoding estimate uses standard [SMS segment limits](https://www.twilio.com/docs/glossary/what-sms-character-limit); confirm actual credits against the provider. The adapter interprets [Semaphore response statuses](https://semaphore.co/docs) as request/network acceptance, without claiming handset delivery.

## Presentation data

See [demo setup and image prompt](../resources/demo/README.md). `PresentationDemoSeeder` is repeatable and limited to local/testing environments. It creates a fictional flood incident, its synthetic photo, a described building and a damage report. It has not been run on the live database.

## Verification and remaining work

The full PHP suite passes: **556 tests, 27,993 assertions**. After the final delivery changes, **24 focused tests, 234 assertions** also pass, including the additional delivery-job acceptance regression. **Seven JavaScript queue tests** cover offline files, interrupted acknowledgements, duplicate tabs, account/campus changes, validation, storage exhaustion and freeing confirmed uploads at quota. PHP lint passes for 205 changed PHP/Blade files; Blade compilation, JavaScript syntax checks, targeted Pint and `git diff --check` pass.

The Windows tool sandbox blocks Vite's child process with `spawn EPERM`, including an attempt using the native config loader and disabled minification. The existing build/manifest remains intact; it does not contain the new frontend code. A fresh `npm run build` outside this tool sandbox is required before the new report wizard can be used. The new queue tests are wired into CI as `npm run test:queue`.

Remaining roadmap work includes verified secondary recovery email flows, push/Firebase integration and real device/browser checks. Live PostgreSQL/S3, SMTP and paid SMS delivery still need deployment-environment verification after migrations and configuration are applied. The new migration `2026_10_16_000006_create_broadcasts_and_phone_verifications.php` remains pending with the earlier response migrations. No real SMS/email broadcasts or presentation seeders were run during this work.
