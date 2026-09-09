# School system delivery phases

Updated 2026-09-09. This expands the accepted roadmap; nothing from the earlier list is removed.
Each phase requires authorization checks, a mobile UI, contextual help, PHP tests,
Cypress workflow checks, GitHub backup, deployment verification and a PROGRESS.txt update.
No phase is complete solely because its code exists.

1. **Monthly money records:** invoice billing months; cash, bank, cheque and manually verified
   online receipts; partial/unpaid/paid balances; separate salary calculations and actual salary
   disbursements with month, method, reference and date; teachers see only their own pay.
2. **Account notifications:** private inbox for every role, unread indicators, read controls,
   announcements, fee receipts, salary payments and attendance alerts. Enforce current role and
   relationship access even after a notification was created.
3. **Exam reminders and delivery:** separate announced exam schedules from unpublished marks;
   default reminders seven days and one day before tests/papers; linked students and guardians;
   WhatsApp and email channels with contact preferences, consent, delivery tracking,
   retries and duplicate prevention. Real delivery requires provider configuration and reliable
   scheduling. Never claim a provider accepted message was delivered without evidence.
4. **Examination reports:** school-defined grading, subject weighting, GPA and full report cards.
5. **Student and teaching workflows:** medical/behaviour records, promotion history, syllabus,
   materials, assignments/submissions, subject attendance and substitute/automatic timetables.
6. **Finance and HR automation:** fee plans, scholarships, late fees, scheduled invoicing/payroll,
   payment gateway verification/reconciliation, contracts and staff documents.
7. **Library and inventory:** book loans/returns and school asset records.
8. **Security and release hardening:** recovery, MFA, sensitive-action verification, durable
   uploads, scheduled off-device encrypted backups/restore drills and production load tests.

Existing working modules remain available throughout. Provider-dependent tasks stay explicitly
pending until the school supplies its country, service choices and credentials through secure
environment configuration. Provider fees or hosting upgrades require a concrete user decision.

WhatsApp uses the official Business Platform, approved templates and recipient opt-in.
Email and WhatsApp delivery must be individually switchable; the account inbox remains
available to all active roles. Defaults for reminder lead times await the user's preference.

## External delivery configuration

- WhatsApp adapter: official Cloud API, with WHATSAPP_API_VERSION, PHONE_NUMBER_ID, TOKEN,
  TEMPLATE_NAME, TEMPLATE_LANGUAGE, APP_SECRET and VERIFY_TOKEN (all prefixed WHATSAPP_).
  Enable WHATSAPP_ENABLED only after verifying the sender and approved template in Meta.
- The approved template needs three body parameters in order: notification title, message
  summary, and secure account URL. Webhook GET/POST endpoint: /webhooks/whatsapp. POST status
  payloads require a valid Meta SHA-256 signature. Retain the app secret in environment settings.
- Email: configure Laravel MAIL_* credentials and sender, then SCHOOL_EMAIL_NOTIFICATIONS=true.
  Log/array mailers do not count as a connected delivery service. APP_URL must be the public
  HTTPS address; Render's RENDER_EXTERNAL_URL is used when APP_URL is absent.
- Recipients opt in under Notifications > Delivery preferences, confirming their current
  password. External delivery uses new eligible messages only; the inbox works without opt-in.
- The scheduler runs school:notifications every minute while the server is awake. The free
  Render service sleeps; production reminders require always-on hosting or an external scheduler.
- Rate limits retry with backoff. Ambiguous timeouts/5xx outcomes are marked unknown and are
  not automatically resent, preventing possible duplicate messages. Email transport acceptance
  is not mailbox delivery confirmation. WhatsApp delivery/read states require signed callbacks.
- Provider accounts, template approval, real delivery verification, operational retry/reconciliation
  controls and guaranteed scheduler availability remain prerequisites for phase 3 completion.

Technical references: https://github.com/laravel/docs/blob/13.x/notifications.md and
https://www.postman.com/meta/whatsapp-business-platform/documentation/wlk6lh4/whatsapp-cloud-api .
