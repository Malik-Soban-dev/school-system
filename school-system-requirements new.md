# School Management System — Requirements (Translated & Organized)

*Translated from Roman Urdu notes and reorganized into clear, structured points. Improvements/suggestions are marked with **[Suggestion]**.*

---

## 0. Global Requirements (Apply to Entire Application)

- **Every action across the entire site** (adding records, points, marks, assignments, etc.) should use **AJAX** — no full page reloads anywhere in the application. When something is added via AJAX, it should appear on the page immediately without a refresh.
- **[Suggestion]** Add a subtle success/error toast notification for every AJAX action so the user gets clear confirmation without needing a page reload.

---

## 1. Student Management

- Add student form should have a **"Current Class Section"** with dropdowns for:
  1. Academic Year
  2. Class
  3. Class Section
- Add student form should capture the student's **fee amount** (not a payment record at admission time — just how much their fee is).
- In the student's records, there should be an option to **increment/change the fee amount each year**, with a full **history/record of all past fee amounts and changes** kept over time.
- Ability to **change/update academic year** for a student (e.g., promotion to next year).

### Student Name & Roll Number
- Students (and other people/characters in the system) should have **separate First Name and Last Name** fields.
- **Roll number assignment**:
  - Admin decides/assigns roll numbers (not randomized).
  - Default logic: roll numbers follow admission order within a class — e.g., if a class has 20 students, a new admission becomes roll no. 21.
  - **[Suggestion]** Roll numbers should act like a unique ID and should **not be reused** even after a student leaves — keep the departed student's record/history intact under their original roll number rather than reassigning it to someone else.

### Admin's Student View (Dashboard)
The admin's view of a student should be a **comprehensive profile**, showing:
- Admission date (day/month/year)
- Current class and section (A/B/C etc.)
- Fee details (fee amount, admission fee, any fines/penalties)
- Parent/guardian name and phone number
- Teacher remarks on the student
- Academic performance/review — strong subjects vs. weak subjects
- Class behavior notes
- Optional activities (e.g., sports)
- Attendance:
  - Monthly attendance record
  - Overall attendance percentage
- Current class vs. the class/year the student originally joined in (admission history)
- **[Suggestion]** Add a single "Student 360 Profile" page that consolidates all of the above in tabs (Overview / Academics / Attendance / Fees / Behavior) instead of scattered pages — easier for admin to review quickly, especially before parent-teacher meetings.

---

## 2. Guardian / Parent Management

- A student can have **one or more guardians**. For each guardian, record:
  - Name
  - Relation to student
  - Phone number
  - WhatsApp number (optional, separate from phone)
  - Linked student's name, class, and roll number
- **[Suggestion]** Allow one guardian to be linked to multiple children (siblings) under one login, so they don't need separate accounts per child.

---

## 3. Points / Marks / Test & Result System

### Adding a Test/Exam Record
- When adding a test, exam, quiz, or paper, the form should be a **single structured entry** (not one generic option) capturing:
  - Type: single subject **or** multi-subject (e.g., term paper, semester exam)
  - Academic year
  - Class and Section (kept as **separate fields**, not combined)
  - Date of the test
  - Subject(s) involved
  - Teacher who conducted the test
- **[Suggestion]** Add a "Test Type" field (Quiz / Class Test / Term Paper / Semester Exam / Final) so reports can later be filtered by test type.

### Adding Marks/Results
- The marks-entry page should support:
  - Choosing whether it's a single-subject or multi-subject test
  - Separate fields for Year, Class, Section
  - Separate marks-entry field **per subject**
  - Recording which teacher conducted the test and on what date
- **[Suggestion]** Add automatic **grade/percentage calculation** once marks are entered, plus **class ranking/position** for the student (this was also requested separately below).
- Student's **exam position/rank** in class should be calculated and shown.

### Result Publishing & Notifications
- When a test is conducted, an automatic notification should go to student, parent, and teacher dashboards saying **"Test has been conducted"** with the subject name.
- If results aren't ready yet, dashboard should show **"Result will be shared soon"** for that subject, along with the date it was conducted.
- If there's an upcoming test, it should show on the dashboard in advance.
- **Results are only shared with students/parents once officially published** by admin/teacher — not automatically visible right after entry.
- Once published, the admin's student-overview should update automatically (for use in parent-teacher meetings).
- **[Suggestion]** Add a "Result Approval" step — teacher submits marks, but they stay in draft/pending state until admin or head teacher approves and publishes them. This avoids accidental early disclosure of incorrect marks.

---

## 4. Test/Assignment Notifications (WhatsApp, Email, Dashboard)

- When a test/assignment is scheduled, a notification banner should appear on dashboards for:
  - Students
  - Parents
  - Teachers (if they have dashboard access)
- Notification should mention: date, subject, and type of test.
- Same notification should also be sent via **email and WhatsApp**.
- **Notification timing rules**:
  - One notification the day the test is scheduled/assigned.
  - One reminder notification 1 day before the test.
  - **Exception**: if the test is scheduled on the same day it's meant to happen (last-minute), send only **one** notification (no separate "day before" reminder, since there's no time for it).
- **[Suggestion]** Let admin/teacher configure the reminder schedule per school (e.g., some schools may want a 3-day-before reminder too) rather than hardcoding "1 day before."

---

## 5. Events / School Activities

- Add a **separate "Events" tab** on the dashboard for school activities/events (distinct from tests/assignments).
- This tab should support multiple event types/options (e.g., sports day, parent-teacher meeting, holiday, trip, cultural event).
- **[Suggestion]** Let events also trigger optional notifications (email/WhatsApp) similar to tests, with an RSVP option for parents where relevant (e.g., sports day attendance confirmation).

---

## 6. Teacher / Assignment Management

- **Teacher-subject assignment**: if a teacher teaches multiple subjects, use **checkboxes** (multi-select) instead of a single dropdown, so multiple subjects can be assigned to one teacher at once.
- **Homework/Assignment creation** should include:
  - Assign date and due/last date
  - Format field: is the assignment **physical (paper)** or **digital/online (file upload)**?
  - If digital: student dashboard should have a **"Submit Your Assignment"** upload field where students upload files from their device.
- **Assignment notifications**:
  - When a teacher assigns homework, students in that class should get a **WhatsApp notification**.
  - It should also appear on the **student dashboard**.
- **Teacher's assignment tracking view**:
  - After the due date (or as submissions come in), teacher should see which students **have submitted** vs. **have not submitted**.
  - Teacher should be able to add **remarks** on each submission — either as a **percentage** or a **numeric score**.
- **[Suggestion]** Add a late-submission flag/indicator (submitted before/after due date) so teachers can quickly spot late work without checking timestamps manually.

---

## 7. Timetable

- Timetable module needs additional fields:
  - School start/end timing
  - Period-wise timing (start/end for each period)
  - Lunch/break timing
- **[Suggestion]** Allow different timetables per class/section (since younger and older grades often have different schedules), and let each teacher see a personal consolidated timetable across all classes they teach.

---

## 8. Staff Attendance (renamed from "Staff Leave")

- Rename **"Staff Leave"** module to **"Staff Attendance"**, and include both leave and attendance in one place:
  - Date, time, leave type/reason, etc.
- **Late-coming** should be **auto-recorded** if a staff member checks in after school start time.
- At **month-end**, generate a salary-relevant summary showing:
  - Number of days late
  - Number of off/holiday days
  - Number of leave days
  - Number of days left early
- **[Suggestion]** Tie this into a basic payroll deduction calculator — e.g., auto-flag if late days or unapproved leaves exceed a threshold that affects salary, so admin doesn't have to calculate manually.

---

## 9. Staff Directory

- Needs changes/improvements, but no specific requirements yet — **to be defined later**.

---

## 10. School Settings

- Add options for:
  - School logo/image upload
  - Other general school-related settings/branding options
- **[Suggestion]** Include contact info, official school name/address, timezone, and academic year start/end dates here too, since these affect date logic throughout the system.

---

## 11. Developer / Super Admin Dashboard (Multi-Tenant Control Panel)

Since this system will be **sold/rented to multiple schools** (SaaS-style business model), there needs to be a separate **Dev/Super Admin dashboard**:

- Access link known only to you (the developer/owner) — not visible to schools.
- Full control over **all schools' data** from one place.
- Per-school configuration:
  - Which features/modules are enabled for that school
  - Which access levels are granted
  - Whether the "Branches" feature is enabled for that school
- **Billing/payment tracking**:
  - How much each school has paid you
  - For rented/subscription schools: track how much each one owes/pays and when
- **[Suggestion]** This is effectively a multi-tenant SaaS admin panel. Strongly recommend:
  - Each school gets fully isolated data (separate database or tenant ID scoping) for security and privacy.
  - Add subscription/plan management (trial, monthly, yearly, feature tiers) with automatic expiry/renewal reminders.
  - Add an audit log of what you (dev) changed on any school's account, for accountability.
  - Add basic usage analytics per school (active users, storage used) to help with support and upselling.

---

## Summary of Key Structural Improvements

1. **Consolidated student profile** view instead of scattered fields.
2. **Result approval workflow** before publishing marks to avoid mistakes reaching parents.
3. **Configurable notification timing** instead of fixed "1 day before" rule.
4. **Multi-tenant isolation + subscription management** for the dev dashboard, since this is a paid SaaS product for multiple schools.
5. **Late/absent submission flags** for both staff attendance and student assignments to reduce manual tracking.
6. Toast/inline confirmations for every AJAX action for better UX clarity.

---

## 12. Complete UI Redesign (Added September 15, 2026)

- Redesign the entire application: login, all role dashboards, navigation, forms, tables, profiles, reports, settings and mobile views. The current interface feels boring and needs a cohesive, modern design.
- Use a glassmorphism visual style with translucent surfaces, frosted-glass panels, subtle blur, layered backgrounds, soft borders and restrained shadows. Keep text, tables and forms readable with sufficient contrast and solid-surface fallbacks where blur is unavailable.
- Use clear visual hierarchy, readable typography, consistent spacing, purposeful icons, useful dashboard summaries and responsive layouts.
- Preserve existing functionality and role permissions throughout the redesign.
- Provide clear loading, empty, success and error states for asynchronous actions.

### Login Loading Animation for Every Role
- Show a polished loading animation during login and initial dashboard loading for every role, including school administrators and the platform superadmin.
- Connect the animation to actual authentication/loading progress; prevent duplicate submissions and restore the form with a clear message if login fails.
- Support reduced-motion preferences and accessible loading announcements. Do not add artificial waiting time.

### English / Urdu Language Switch
- Add a visible language button on login and throughout the application to switch the entire interface between English and Urdu for every role, including superadmin.
- Translate navigation, dashboards, forms, labels, buttons, validation errors, loading/empty states, notifications, dialogs and generated report interface text. Preserve user-entered names and records as entered.
- Use right-to-left layout for Urdu and left-to-right layout for English, with suitable Urdu typography and correct handling of mixed-direction emails, phone numbers and identifiers.
- Remember the selected language across navigation and future visits, using an account preference when signed in and a local preference before login.
- Apply language-aware dates and number formatting without changing stored dates or financial values. Check layouts in both languages on desktop and mobile.

### Light / Dark Mode Switch
- Add a visible light/dark mode button on login and throughout every role's interface, including superadmin.
- Apply the selected theme to the entire application, including glass panels, tables, forms, menus, dialogs, charts and notification states.
- Remember the selected theme across navigation and future visits. Use the device preference initially when the user has not selected a theme.
- Maintain readable contrast in both themes and avoid a flash of the wrong theme during initial loading.
- Verify English and Urdu in both light and dark themes, including mobile layouts.

## 13. Expanded Platform Superadmin Requirements (Added September 15, 2026)

- The application creator must have a separate platform superadmin account and dashboard to manage every school using the application.
- Provide a platform-wide overview and per-school drill-down covering all application modules and operational records: schools/branches, users/roles, students/guardians, staff, admissions, academics/results, attendance, assignments, events, timetables, fees/payments, payroll/disbursements, notifications and school settings.
- Include school onboarding, account status, module/feature access, branch availability, subscriptions/plans, amounts paid and outstanding balances, payment history and renewal dates.
- Include operational visibility: usage, available storage metrics, notification delivery status/failures, scheduled-job status, recorded application errors, backup status and audit history. Distinguish unavailable metrics from verified healthy states.
- Support search, filters and school switching, with the selected school clearly visible for all school-specific actions.
- Keep each school's data isolated from other schools. Platform access must use server-enforced superadmin authorization; an unlisted login URL alone is insufficient.
- Audit superadmin changes with actor, school, action and time. Sensitive operations need explicit confirmation in the application.
- Comprehensive access means business and operational information; never display plaintext passwords, authentication tokens, encryption keys or provider secrets in the dashboard.

### Implementation Continuity
- Preserve all original requirements above and existing unfinished report-card work.
- Implement and verify school isolation and platform permissions before enabling access for multiple schools.
- Keep credit usage efficient through focused inspection, reuse of existing components and targeted verification.