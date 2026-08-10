# PRD Progress Audit — USM EAES

**Branch / snapshot:** `main` (local)  
**PRD source:** `PRD_V1.md` (v2.0 content, filename `PRD_V1.md`)  
**Last reviewed:** 2026-08-08
**Backend path:** `Web/` (Laravel 12 backend with UUID schemas)

> This file tracks implementation status only. It does not override `PRD_V1.md`.

## Status Legend

| Status | Meaning |
|--------|---------|
| **Not Started** | No meaningful implementation |
| **Partial** | Scaffold, config, or UI stub only; PRD behavior not met |
| **In Progress** | Active work; not yet verifiable end-to-end |
| **Done** | PRD behavior implemented with evidence |
| **Blocked** | External dependency or decision needed |
| **Off-PRD** | Implemented deviation from PRD (document reason) |
| **N/A** | Out of scope per PRD (e.g. removed accomplishment reports) |

---

## Foundation & Tooling

| Item | Status | Evidence / Notes |
|------|--------|------------------|
| Laravel 12 app in `Web/` | Done | Skeleton integrated with UUID migrations, configs, and route mappings |
| Backend Composer dependencies (`vendor`) | Done | Spatie permission, Laravel Socialite, Sanctum, DomPDF, and Laravel Excel installed; `composer audit` clean after 2026-08-08 security patch update |
| Web Node dependencies (`node_modules`) | Done | Dependencies installed and locked (alpinejs, html5-qrcode), Tailwind v4 configured, Vite production assets built on 2026-06-26 using local Node 20.19.4 |
| `APP_KEY` / env setup | Done | Key generated, environment configured in `Web/.env` |
| PostgreSQL 16 (PRD §7) | Done | Laragon PHP 8.4.5 now runs with `pdo_pgsql`; `artisan migrate:fresh` and `artisan test` pass against local PostgreSQL 16 |
| API layer + Sanctum (Epic 1) | Done | Sanctum API setup, token login, logout, session validate routes, and UUID-compatible token ownership created |
| Redis queues (PRD §3.3) | Partial | Redis 7 local Compose service, production Redis queue/cache templates, priority Supervisor workers, queue health checks, and a durable scanner-resolution outbox are implemented; local runtime intentionally remains on the database fallback until Redis is started and `.env` is switched |
| Project `AGENTS.md` + Cursor rules | Done | Root `AGENTS.md`, `.cursor/rules/` |

---

## §1 Executive Summary & Objectives

| Item | Status | Evidence / Notes |
|------|--------|------------------|
| Scope: evaluation-focused (no digital semestral accomplishment report) | N/A | Confirmed in PRD §1.2 |
| PPA digitization | Done | Web dashboard and APIs capture proposal metadata, official PPA softcopy upload, hardcopy/signature status, submit/review/approve/reject, participant-scope PPAs, and account-based scoped scanner assignments |
| QR attendance + evaluation gate | Done | Sync controllers, EILO background job, pending QR dashboard, and student evaluation gate verified |
| Offline scanner + sync | Done | Flutter scanner has Google mobile login, assigned-event listing/activation/resume, platform-backed device identity, participation-scope display, mode switching, SQLite repository, camera/manual capture UI, scope-stamped offline scans, API sync client, restart-safe scanner-session snapshots, offline restore after app close, controller tests, scanner-session phase indicators, and assignment-activation token coverage |
| Event lifecycle + scanner sessions | Done | `events.lifecycle_phase`, `events.event_domain`, `event_participation_scopes`, `event_scanner_assignments`, `scanner_sessions`, assignment/scope-aware hydrate/sync guards, revocation, device binding, idempotent same-device activation resume, cross-device transfer blocking, scan-window validation, scheduled lifecycle updates, and Event Proposals lazy lifecycle refresh implemented |
| Swappable Gemini/Ollama NLP Assistant | Done | Container-bound AiServiceInterface supporting Gemini API and OpenAI-compatible Ollama Cloud (with connect & request timeouts, robust error logging, and dynamic UI branding) |

---

## §2 RBAC (Spatie)

| Item | Status | Evidence / Notes |
|------|--------|------------------|
| `spatie/laravel-permission` package | Done | Installed and customized to support UUID model_id |
| Roles & permissions seeders (§2.2 table) | Done | `RolePermissionSeeder` maps all active permissions and roles for web/api guards |
| Tenant-scoped middleware (org/college) | Done | `TenantScopeMiddleware` intercepts routing to restrict organization and college boundaries |
| User model aligned with PRD (UUID, `google_sub`, `organization_id`, etc.) | Done | `User.php` updated with UUID primary key, `HasUuids` trait, and fillables |

---

## §3 Offline-First & Sync

| Item | Status | Evidence / Notes |
|------|--------|------------------|
| Roster hydration API (§3.1) | Done | Hydrate route `/events/{id}/hydrate` returns compressed roster scoped to the activated `event_participation_scope_id`, including student, multi-membership, non-academic, and HR roster paths |
| Unresolved scan + server resolution (§3.2) | Done | `raw_scans` supports nullable `student_id`; pending QR links table and resolve/flag views with audit logging implemented |
| Bulk sync + idempotency keys (§3.3) | Done | `/attendance/sync` preserves scan-window and scope guards while `AttendanceSyncPersistenceService` atomically inserts with `insertOrIgnore`, re-queries request UUID winners, creates unresolved pending links only for winners, and writes a durable resolution outbox record in the same transaction; PostgreSQL/SQLite partial indexes remain final duplicate authority |
| `ResolveAttendanceJob` + queue throttling | Done | Queueable EILO job uses scoped `WithoutOverlapping`, bounded retries/backoff/timeout, participation-scope row locking (event-row fallback for legacy jobs), attendance identity indexes as final duplicate defense, PII-safe failure logging, and the dedicated `attendance` queue; the scheduled outbox dispatcher retries Redis handoff after recovery |
| Flutter offline SQLite scanner | Done | `sqflite`, `mobile_scanner`, `connectivity_plus`, and API client dependencies configured; local DB/sync classes, persisted scanner-session snapshots for offline app restart, global session feedback, duplicate-scan cooldown, student-ID manual entry, scope-stamped SQLite v3 offline scans, sync status panel with failed/retry states, auto-wipe after completed events, friendly error mapping, and expanded controller/widget tests verified |

---

## §4 Epics

### Epic 1: Authentication & Onboarding

| Feature | Status | Evidence / Notes |
|---------|--------|------------------|
| 1.1 Google OAuth + `@usm.edu.ph` + Sanctum tokens | Done | `GoogleAuthController` redirects with account selection + USM domain hint, checks domain, clears rejected sessions, and issues web auth |
| 1.2 Student profile + QR registration | Done | Student profile page supporting both onboarding/edit mode (with dependent College/Program/Organization/Year Level selects and webcam QR capture) and a clean read-only view mode when complete. QR inputs collapse by default if already registered. |
| 1.3 OSA admin assignment governance | Done | OSA Admin Access page for direct organization management, term-bound `admin_assignments`, a filtered OSA-only Audit History tab for governance audit rows, and non-OSA scoped Organization Profile logo management; student admin-application routes/UI removed; `AdminScopeService` assignment-scoped admin boundaries; OSA assignment creation restricted to USG, LSG, Society, Academic Unit, and Office Event roles; Super Admin (OSA) remains bootstrap-controlled and HR Admin provisioning remains independent; `AdminAssignmentService` role-only permission projection sync; legacy `admin_applications` deprecated |

### Epic 2: PPA

| Feature | Status | Evidence / Notes |
|---------|--------|------------------|
| 2.1 Digital PPA submission | Done | Livewire, web fallback, and API proposal writes share `EventProposalDraftService` for location details, budgets, ordered event days, evaluation snapshots, official proposal softcopy, resolution number, hardcopy/signature status, and organizer-scope synchronization before submission/approval |
| 2.2 OSA review pipeline | Done | OSA review, approval, request-revision, and rejection status routes completed; returned proposals carry OSA revision remarks, remain scanner-locked, and can be edited/resubmitted by the organizer |
| 2.3 Participant PPAs and distributed attendance ownership | Done | `event_participation_scopes` model/migration, organizer attendance scopes, Organized/Participating Events dashboard tabs, in-app invitation notifications/acknowledgements/reminders, participant PPA submit/review/approve/request-revision/reject routes, scope-aware scanner assignment/revocation, runtime hydrate/sync gating, and roll-up analytics implemented |
| 2.4 HR event domain | Done | `events.event_domain`, HR Admin role/permissions, employee roster models, non-student Google onboarding separation, HR no-PPA event creation, and HR roster hydration path implemented; HR Admin provisioning is not handled through OSA Admin Access |
| 4.2 Organizer-owned late cutoff | Done | Revised from OSA-owned dual thresholds to one official late-cutoff policy: organizer event cutoff is managed from separate Attendance Rules UI, applies first, locks after event start, and falls back to participant scope cutoffs only when unset; legacy status columns are preserved as compatibility mirrors |
| 4.3 Multi-day session management | Done | EILO groups scans by day and maps to specific `event_day_id` records |

### Epic 5: Evaluation Portal & Gate

| Feature | Status | Evidence / Notes |
|---------|--------|------------------|
| 5.1 Digital evaluation form | Done | Event proposals now store locked event-level evaluation form snapshots with organizer-drafted dynamic 1-5 Likert questions plus one open comment; OSA proposal review shows the form before approval; student validation, analytics, and exports resolve question labels from the event snapshot with legacy default fallback |
| 5.2 Evaluation gate (`valid` flag, 24h window) | Done | Toggles record validity on evaluation store; validates 24h time-limit |

### Epic 6: Analytics & Export

| Feature | Status | Evidence / Notes |
|---------|--------|------------------|
| 6.1 Aggregated analytics dashboards | Done | Two-level analytics implemented: (1) full per-event analytics workspace at `/dashboard/events/{event}/analytics` backed by shared page/API metric logic, with overview, attendance drilldown, Program and Year Level demographics, evaluations, sentiment, participant scopes, scanner/sync, and export context; quick modal previews use `/dashboard/events/{event}/analytics/data`; (2) Organization Performance Analytics page supporting program/year-level filtering under each organization (cascade filters: SY, Sem, College, Org, Program, Year Level, Event Type), detailed late/absent/left-early indicators, and a Program Comparison Leaderboard — all scoped by Spatie RBAC. |
| 6.2 PDF/Excel export engine | Done | Dashboard export routes generate scoped PDF/XLSX attendance and evaluation reports with event metadata, participant scope, PPA hardcopy status, student Program/Year Level columns, validity, ratings, comments, sentiment fields, timestamp, and generated-by user |
| 6.3 Gawad Parangal metrics | Done | Computes active membership, average attendance rate, eval score averages, and program comparison indicators |

---

## §5 Algorithms

| Item | Status | Evidence / Notes |
|------|--------|------------------|
| EILO sync resolution (§5.1) | Done | Validated by PHPUnit feature test case `UsmEaesTest` |

---

## §6 AI Engine

| Item | Status | Evidence / Notes |
|------|--------|------------------|
| Gemini 1.5 Pro integration (§6.1) | Done | `GeminiService` uses configurable timeout, validates JSON, clamps sentiment output, and falls back from Gemini 1.5 Pro to Gemini 2.0 Flash |
| Sentiment pipeline (§6.2) | Done | `AnalyzeEventSentimentsJob`, hourly `eaes:analyze-closed-evaluations` command, and manual dashboard action process anonymized closed-window comments |
| NLP query assistant (§6.3) | Done | Dashboard AI Insights page and API route share whitelisted table/field mappings, capped results, ignored unsafe filters, and role-based tenant scope |

---

## §7 Database Schema

| Table / concern | Status | Evidence / Notes |
|-----------------|--------|------------------|
| `universities` | Done | UUID schema defined and migrated |
| `colleges` | Done | UUID schema defined and migrated |
| `organizations` | Done | UUID schema defined and migrated; status/logo fields and unique type+college+acronym identity added |
| `users` (PRD shape) | Done | Custom user columns migrated; college_id and program_id UUID foreign keys plus nullable controlled `year_level` profile field added |
| `programs` | Done | UUID schema representing academic programs under colleges, migrated with unique college+code constraint |
| `organization_programs` | Done | Pivot mapping many-to-many relationship between organizations and programs, migrated |
| `organization_memberships` | Done | UUID multi-membership table added for primary academic, secondary org, non-academic, and officer membership attribution, including mirrored profile Year Level |
| `employee_profiles` / `employee_roster_entries` | Done | UUID HR/faculty/staff profile and roster tables added for employee verification and HR scanner hydration |
| `admin_applications` | Deprecated | Legacy student-application table retained for data preservation only; routes, controllers, and UI removed in favor of OSA-direct assignment governance |
| `admin_assignments` | Done | Source-of-truth admin authority and tenant scope table with active primary admin uniqueness by role/scope/academic year; user profile organization/college/program fields remain student membership |
| `events` | Done | Custom PPA columns (location, budget, target), lifecycle phase, and event domain migrated with UUID |
| `event_days` | Done | UUID schema defined and migrated |
| `event_participation_scopes` | Done | UUID organizer/participant scope table added with PPA status, capture mode, delegated owner, analytics roll-up fields, and participant late-cutoff fallback |
| `event_scanner_assignments` | Done | UUID scanner assignment table separates admin intent from runtime sessions, with event/scope/user/assigned-by, audience type, status, revocation, expiry, max devices, notes, and last activation fields |
| `scanner_sessions` | Done | UUID scanner-session table added with event/scope/user/token, assignment link, label, device binding, status, expiry, revocation, and last-seen fields |
| `raw_scans` | Done | Nullable student, QR, dedup_key, scanner-session/user audit, participation scope, captured-by organization/admin scope, server received, and processed markers migrated with UUID |
| `attendance_records` | Done | EILO official attendance status, compatibility status mirror, participation scope, validity, and override columns migrated with UUID |
| `evaluations` | Done | Score JSONB, comment, sentiment metrics migrated with UUID |
| `audit_logs` (NFR) | Done | Action, UUID targets, change details, admin-assignment lifecycle records, organization-management records, and OSA Admin Access history visibility implemented with UUID |
| `pending_student_links` (§3.2) | Done | UUID table added for unresolved QR review, resolution, and flagging |

---

## §8 Non-Functional Requirements

| Item | Status | Evidence / Notes |
|------|--------|------------------|
| Bulk sync concurrency / queue protection | Done | Fail-fast migration rejects pre-existing duplicate attendance or active pending-link identities before DDL; PostgreSQL/SQLite partial unique indexes enforce canonical attendance and one-active-pending-link identities, with lock coordination, a transactional scanner-resolution outbox, priority Redis queues, and an opt-in isolated PostgreSQL contention suite |
| API abuse protection | Done | Named Laravel limiters protect public password/Google authentication, authenticated API traffic, scanner sync, writes, and NLP queries with configurable thresholds, user/token plus IP keys, cache-backed state, standard throttle headers, and stable JSON `429` responses |
| PII / HTTPS expectations | Done | Deployment templates, security headers, HTTPS/session checks, dev-login gating, demo seeder, deployment check command, and PII hardening guide completed |
| Immutable `audit_logs` for admin resolution and governance actions | Done | `audit_logs` schema migrated; pending QR resolve/flag actions, admin assignment create/revoke/expire actions, admin-application review actions, and organization create/update/logo actions are recorded; OSA can filter Admin Access governance audit history by action, actor/search text, and date range |

---

## Off-PRD / Deviations

| Date | Description | Reason | Approved by |
|------|-------------|--------|-------------|
| 2026-05-30 | Database Queue Fallback | Redis database extension missing on local environment; fallback used for jobs | Auto-approved for Dev |

---

## Sprint Notes

- **2026-07-29 (proposal revision workflow):**
  - Added `revision_requested` as a first-class PPA review state for organizer proposals and participant PPAs, with OSA-required revision remarks and final rejection semantics.
  - Added organizer full-proposal edit/resubmit support while draft or revision-requested, preserving scanner locks until approval and keeping organizer scope packet fields synchronized.
  - Added dashboard/Livewire, web fallback, and API revision actions plus focused coverage for main proposal and participant PPA revision loops.
- **2026-07-21 (event-specific evaluation forms):**
  - Added locked `evaluation_form_versions` snapshots linked to events and evaluation submissions, with backfill/fallback support for existing events.
  - Extended proposal creation and API event creation to require 1-10 organizer-drafted Likert questions, and blocked OSA approval when a locked event form is missing.
  - Updated student evaluation validation, per-event analytics, and evaluation exports to use event-specific question IDs and labels while preserving the evaluation gate.
- **2026-08-08 (tiered API rate limiting):**
  - Added configurable cache-backed Laravel limiters for public authentication, authenticated API reads, scanner sync, authenticated writes, and NLP queries.
  - Applied user/token-plus-IP keys to protected routes and IP keys to public authentication, preserving scanner batch size and existing successful response contracts while returning stable JSON `429` responses with retry headers.
- **2026-07-15 (Admin Access audit history):**
  - Added an OSA-only Audit History tab to Admin Access for governance audit rows covering admin assignment lifecycle, organization management, and legacy admin-application review actions.
  - Added action, actor/search, and date filters while rendering only safe allowlisted details from `audit_logs.details`.
  - Expanded `AdminOnboardingTest` coverage for assignment create/revoke/expire audit details, organization update audit details, OSA-only visibility, and audit action/search filtering.
- **2026-07-15 (Organization Profile logo management):**
  - Added a non-OSA scoped Organization Profile page where active organization-scoped admins can upload, replace, and remove their assigned organization logo using the existing `organizations.logo_path` field.
  - Kept OSA out of the self-service profile page while preserving OSA Admin Access organization-management controls.
  - Added audit actions for organization logo update/delete and focused `AdminOnboardingTest` coverage for scoped access, OSA/student blocks, file validation, replacement cleanup, and removal cleanup.
- **2026-07-10 (student Year Level profiling and analytics filters):**
  - Added controlled student `year_level` profiling for portal onboarding/edit, mirrored it into profile-sourced `organization_memberships`, and kept it separate from admin assignment tenant scope.
  - Added Year Level filtering to organization analytics and per-event participant drilldown, plus per-event Year Level demographic chart data.
  - Added Year Level columns to attendance/evaluation export payloads and updated focused profile, analytics, and export tests.
- **2026-07-10 (OSA assignment role restriction):**
  - Restricted OSA Admin Access assignment creation to USG, LSG, Society, Academic Unit, and Office Event roles while keeping `AdminAssignmentService::ADMIN_ROLES` intact for existing/bootstrap Super Admin (OSA) and HR Admin projection.
  - Removed Super Admin (OSA) and HR Admin creation options from the assignment modal and blocked forged OSA Admin Access POSTs for those roles.
  - Clarified that HR role support remains implemented, but HR Admin provisioning is independent from OSA governance.
- **2026-07-09 (per-event analytics workspace):**
  - Replaced the Events table analytics modal entry point with a full per-event analytics page at `/dashboard/events/{event}/analytics`, while keeping quick preview JSON at `/dashboard/events/{event}/analytics/data` and the existing API route backward-compatible.
  - Added shared `EventAnalyticsService` metric logic so web page, web JSON, and API analytics share participation-scope denominators via `ParticipationScopeService::audienceQuery`, including daily EILO counts, valid attendance, evaluation category distributions, sentiment summaries, participant drilldown rows, scanner/session/raw-scan health, pending QR counts, and export context.
  - Added feature and metric coverage in `EventAnalyticsPageTest` plus the existing global analytics API denominator regression.
- **2026-07-10 (participant invitation notifications):**
  - Added database-backed in-app notifications for participant-scope invitations, explicit invited-org acknowledgement, participant PPA submission/review receipts, global/sidebar badges, dashboard needs-action summaries, and a daily reminder command that keeps organizer-private drafts hidden until parent submission.
  - Added WebPortalTest coverage for immediate vs deferred invitation notifications, acknowledgement receipts, OSA review notifications, and idempotent daily reminders.
  - Tightened OSA event-page role boundaries so OSA keeps proposal/participant-PPA review visibility but cannot add participant scopes or assign/revoke scanner access through UI controls or direct POST routes.
  - Replaced single-org participant scope selection with a dashboard audience builder that batch-creates separate organization or College participant scopes, including an all-Colleges mode, duplicate-scope skipping, notifications, and WebPortalTest coverage for the new paths.
- **2026-07-03 (participant PPA, distributed attendance ownership, and HR domain):**
  - Added `event_participation_scopes`, `organization_memberships`, employee profile/roster tables, and event-domain columns, with backfills from existing organizer events and linked child PPAs.
  - Scoped scanner assignment/session/hydrate/sync/runtime guards to approved participant scopes; sync now rejects out-of-scope scans and EILO resolution preserves `event_participation_scope_id`.
  - Added dashboard participant-scope management, participant PPA submit/review/approve/reject actions, scope-aware scanner assignment, per-scope analytics roll-up UI, and Organized/Participating Events tabs so invited organizations can find submitted parent events without exposing organizer drafts.
  - Added Academic Unit Admin, Office Event Admin, and HR Admin role support across seeders, route guards, scanner access, organization management, and auth redirects. HR Admin remains supported internally, but provisioning is independent from OSA Admin Access.
  - Separated student vs faculty/staff onboarding defaults, added HR no-PPA event creation, and introduced HR roster hydration for faculty/staff attendance events.
  - Updated Flutter scanner contracts to persist/display assignment participation scopes and stamp offline scans with `event_participation_scope_id` in SQLite v3 and sync payloads.
- **2026-05-29:** Repository contains Laravel 12 skeleton in `Web/` and PRD only. No domain migrations, packages, API, or mobile app. Next recommended milestone: `composer install`, PostgreSQL env, `php artisan install:api`, Spatie + initial UUID schema per PRD §7.
- **2026-05-30:** 
  - Installed `spatie/laravel-permission`, `laravel/socialite`, and `laravel/sanctum` packages.
  - Implemented all UUID-based database migrations (universities, colleges, organizations, users, events, event days, raw scans, attendance records, evaluations, audit logs).
  - Seeded Spatie roles and permissions via `RolePermissionSeeder` for both web and api guards.
  - Built custom `TenantScopeMiddleware` to enforce organizational tenancy boundaries on administrative actions.
  - Implemented Google Socialite web routes/redirection and Sanctum scanner API token validation controllers.
  - Built PPA proposal submission, OSA review, and multi-organization event linking APIs.
  - Implemented hydration endpoint (`/hydrate`) and batch synchronization endpoint (`/sync`) chunking with client-side idempotency checks.
  - Implemented background `ResolveAttendanceJob` resolving raw scans via EILO algorithms and checking the organizer-owned late cutoff, with participant-scope fallback when the event cutoff is unset and Attendance Rules locked after event start.
  - Created `EvaluationController` supporting Likert scale questionnaire submissions and locking attendance validity.
  - Integrated `GeminiService` client wrappers for batch comment sentiment analysis and NLP query parsing.
  - Built `AnalyticsController` for stats reports and Gawad Parangal organizational membership active ratios.
  - Written and validated full E2E pipeline test suite (`UsmEaesTest.php`) executing all modules successfully.
- **2026-06-25:**
  - Added the initial university-level organizer scope for Recognition and Graduation events per PRD §2.2 and Epic 2. This scope was later retired in favor of the active USG/LSG/Society admin model and OSA-controlled proposal workflow.
  - Added backend scanner lock enforcement so hydrate, sync, and scanner validation reject unapproved event proposals.
  - Added `pending_student_links` migration/model/API for unresolved QR records, including resolve/flag routes and audit logging.
  - Standardized created API responses from nonstandard `210` to HTTP `201`.
  - Hardened NLP query execution with whitelisted query tables/fields and explicit tenant joins for events, attendance records, and evaluations.
  - Expanded Laravel feature coverage to 7 tests / 51 assertions.
  - Enabled `pdo_pgsql` in the Laragon PHP runtime, switched local `.env` back to PostgreSQL, and verified Laravel against PostgreSQL 16 with `artisan migrate:fresh` and `artisan test`.
  - Fixed the `events.parent_event_id` self-referencing foreign key migration so PostgreSQL can create the constraint after the `events` table exists.
  - Implemented Flutter scanner foundations: app-link session parsing, dual scan mode state, camera/manual capture surface, local SQFlite storage, connectivity-aware sync, and Laravel API client.
  - Ran `flutter pub get`, `dart format`, `flutter analyze`, and `flutter test`; mobile analysis and widget test passed.
- **2026-06-25 (continued):**
  - Fully implemented the Laravel Web Portal MVP milestone:
    - Moved layouts to `resources/views/components/layouts` to allow standard Blade component routing.
    - Completed student portal features: index dashboard, profile completion with webcam QR capture/manual input, and evaluation page with Likert scales.
    - Completed admin dashboard features: KPI stats cards, events proposal list with OSA submit/review/approve/reject pipeline, pending student QR link resolve/flag interfaces, and Super Admin admin provisioning.
    - Handled auth redirection for guest and role-based access.
    - Standardized test suites, increasing coverage to 24 tests / 101 assertions passing cleanly.
  - Re-themed the design system, CSS color variables, and login pages to utilize the University of Southern Mindanao (USM) academic identity (deep green primary, gold accent indicator lines, and forest dark-mode color scheme).
- **2026-06-26:**
  - Added official PPA packet handling to the admin dashboard: proposal softcopy upload/download, hardcopy submission tracking, organization-head/adviser signature flags, and resolution number capture.
  - Hardened the OSA pipeline so draft proposals require a softcopy and hardcopy before submission/approval, matching the current hardcopy-to-OSA workflow.
  - Added tenant-scoped pending QR resolve/flag protections, centralized v1 evaluation questions, and stricter student profile organization/program validation.
  - Served `html5-qrcode` locally from `public/vendor` as a profile-page fallback for environments where the Vite bundle is stale.
  - Verified with `php artisan test` (26 tests / 115 assertions), dashboard and portal route listings, and `npm run build` using local Node 20.19.4 from the XAMPP Node path.
- **2026-06-26 (continued):**
  - Completed PRD Feature 6.2 export engine for the web dashboard:
    - Added DomPDF and Laravel Excel dependencies, then updated vulnerable transitive Composer packages reported by `composer audit`.
    - Added tenant-scoped attendance and evaluation PDF/XLSX export routes for event records, including event metadata, official PPA/hardcopy status, attendance validity, evaluation ratings, comments, sentiment fields, generated timestamp, and generated-by user.
    - Added compact export links to the event proposal table without changing the proposal modal workflow.
  - Verified with `php artisan test` (31 tests / 128 assertions), `composer audit` (no advisories), and `npm run build` using local Node 20.19.4 from the XAMPP Node path.
- **2026-06-26 (scanner hardening):**
  - Advanced PRD Epic 3 by hardening scanner session tokens for the Flutter scanner API path:
    - Added scanner-token ability authorization for Sanctum-protected hydrate/sync routes without granting scanner access to student tokens.
    - Added backend feature coverage for scanner-session validation, roster hydration, sync ingestion, same-batch duplicate dedup handling, cross-tenant rejection, unresolved QR handling, and EILO attendance resolution.
    - Added Flutter scanner controller contracts and tests for session activation, hydration, known QR capture, unresolved/manual capture, duplicate local dedup keys, offline sync blocking, and successful sync unresolved counts.
    - Improved scanner UI state visibility with active event, mode, pending count, and last scan result panels.
  - Verified backend with `php artisan test` (34 tests / 145 assertions) and `composer audit` (no advisories). Verified Flutter from the user's PowerShell with `flutter pub get`, `dart format --set-exit-if-changed .`, `flutter analyze --no-pub` (no issues), and `flutter test --no-pub` (9 tests passing).
- **2026-06-26 (AI engine completion):**
  - Completed PRD §6 AI engine:
    - Added reusable evaluation-window logic, hourly `eaes:analyze-closed-evaluations` dispatch, and tenant-scoped manual sentiment analysis from the dashboard.
    - Hardened `GeminiService` with request timeout config, primary/fallback model handling, strict JSON validation, sentiment label/score clamping, and neutral local fallback when no API key is configured.
    - Added the Blade/Tailwind AI Insights dashboard page for scoped NLP queries and sentiment queue review; API and web query paths now share the same whitelisted query executor.
    - Preserved anonymized sentiment payloads by sending only evaluation IDs and comment text to Gemini.
  - Verified with `php artisan test` (46 tests / 174 assertions), `composer audit` (no advisories), and `npm run build` using local Node 20.19.4 from the XAMPP Node path.
- **2026-06-26 (organization analytics overview):**
  - Implemented Organization Performance Analytics Overview (PRD Epic 6.1 second level):
    - Added `AnalyticsOverviewController` with full RBAC tenant scoping: OSA sees all orgs, LSG sees college orgs, Society/USG see own org only.
    - Aggregated per-org metrics: total completed events, avg attendance rate, avg evaluation score, eval response rate, active member ratio, sentiment positive/negative percentage, and per-event breakdown list.
    - Ranked leaderboard table (composite score = 50% attendance + 50% eval score normalized) visible when multiple orgs are in scope.
    - Full-page blade view at `/dashboard/analytics` with summary KPI strip, responsive org profile cards, and expandable per-event detail rows linking to per-event analytics modal.
    - Added "Analytics" sidebar link (all admin roles) and quick-action card to the dashboard home.
    - Registered `GET /dashboard/analytics` route and extended `proposal-modal.css` loader to include the new route.
  - PHP lint check confirms no syntax errors. Tests blocked by local PHP 8.2/Composer 8.4.1 mismatch (pre-existing).
- **2026-06-26 (program-level analytics filtering):**
  - Implemented program-level performance filtering under organizations/societies:
    - Added `programs` table (`id`, `college_id`, `name`, `code`) and many-to-many pivot `organization_programs` connecting organizations and programs.
    - Updated `users` table with `college_id` and `program_id` UUID foreign keys.
    - Updated `/dev/login/{role}` dev-helper to seed colleges, programs, and organization program associations.
    - Updated student portal profile view (`profile.blade.php`) and controller (`PortalController.php`) to utilize cascading selects (College → Program → Org/Society) and auto-map `program_code` string on save.
    - Updated `AnalyticsOverviewController` to handle query filters (SY, Semester, College, Org, Program, Event Type) with Postgres `EXTRACT` month fallbacks, and compute metrics (late, absent, left-early, valid attendance) scoped to selected programs.
    - Added a Program Comparison Leaderboard showing performance index comparisons across academic courses, scoped by RBAC (Super Admin sees all, LSG sees college courses, Society sees linked courses).
  - Verified with `php artisan test` (46 tests / 174 assertions passing cleanly) and compiled assets with Vite (`npm run build`).
- **2026-06-26 (deployment and PII hardening):**
  - Completed PRD §8 HTTPS/PII deployment expectations:
    - Added env-driven security toggles for forced HTTPS URL generation, global security headers, and explicit local-only dev-login opt-in.
    - Added `eaes:deployment-check` for demo/production readiness validation covering debug mode, HTTPS URL, secure/encrypted sessions, Sanctum domains, queue/cache posture, storage link, scheduler expectation, and Gemini key status.
    - Added `.env.demo.example`, `.env.production.example`, project-specific `Web/README.md`, and root `DEPLOYMENT_HARDENING.md` with PII data map, queue posture, exports/scanner privacy rules, and demo walkthrough.
    - Added optional `DemoDataSeeder` for deterministic local demo accounts and sample attendance/evaluation data; it is not called by `DatabaseSeeder`.
    - Cleaned duplicated audit Epic headings.
  - Verified with PHP 8.4.5 via `php artisan test` (53 tests / 214 assertions), `composer audit` (no advisories), and `npm run build` using local Node 20.19.4 from the XAMPP Node path.
- **2026-06-27 (OSA proposal review UX):**
  - Tightened PRD Epic 2 review boundaries so Super Admin (OSA) proposal lists, dashboard recents, document downloads, and exports exclude organizer-private `draft` proposals until submission.
  - Hid proposal creation controls from OSA, enforced `create-proposals` on organizer-only web actions, and mirrored non-draft OSA visibility in the API event list.
- **2026-06-27 (hybrid onboarding and admin governance):**
  - Replaced direct admin provisioning with one-account admin applications, OSA approval/rejection, and term-bound `admin_assignments` as the source of truth.
  - Added `AdminAssignmentService` to centralize all admin role grants/removals. `users.organization_id`, `users.college_id`, and `users.program_id` are student profile membership fields and are no longer overwritten by admin assignment sync.
  - Added official master-data seeding for Colleges, Programs, USG, and per-college LSG shells while keeping Society registration OSA-reviewed.
  - Added constraints for duplicate pending admin applications, active primary admin assignments, program college+code identity, and organization type+college+acronym identity.
  - Added an OSA proposal-detail modal showing schedule, event days, venue, offices, target participants, funding, budget, resolution, softcopy, hardcopy, and signature metadata before review actions.
  - Improved proposal form defaults so start date pre-fills end date and D1 date without overwriting deliberate multi-day edits, and added a shared sign-out confirmation modal.
- **2026-06-28 (Google auth guardrail):**
  - Hardened Google OAuth redirect with forced account selection and an `hd=usm.edu.ph` hint so a rejected personal Gmail session does not trap the next login attempt.
  - Rejected non-institutional callbacks now clear local auth session state before returning to login; verified with `php artisan test --filter=GoogleAuthControllerTest`.
- **2026-06-29 (scanner session token schema):**
  - Corrected Sanctum `personal_access_tokens.tokenable_id` from bigint to UUID for PostgreSQL so event-scoped scanner session tokens can be issued for UUID users.
  - Updated the fresh-install migration to use `uuidMorphs('tokenable')`, added a one-time PostgreSQL conversion migration, and verified the local database column reports `uuid`.
  - Verified with scanner session token coverage.
- **2026-06-30 (scanner UX and sync hardening):**
  - Flutter scanner: global session feedback banner with activation loading/success/error states and auto-navigation to Scanner tab; Scanner tab now shows session/roster/network/sync status; prominent saving/duplicate overlays; 5-second duplicate cooldown by event + scan type; student ID manual entry (`NN-NNNNN`) with roster lookup and unresolved fallback; friendly error mapping with debug-only technical details; SQLite v2 sync statuses (`pending`/`syncing`/`synced`/`failed`) with retry; auto-wipe of roster/session after completed events once all scans are confirmed synced.
  - Laravel: hydrate roster now returns `student_id_number`; pending QR links dashboard shows QR vs Manual ID source.
  - Verified with `flutter analyze`, `flutter test` (22 tests), and scanner-session backend coverage.
- **2026-06-30 (scanner UI declutter):**
  - Reduced post-update redundancy and clutter on the Flutter scanner per the ui-ux-pro-max design system (minimal single-column, one primary CTA, glanceable status): replaced the full sync panel on the Scanner and Live Scanner screens with a compact read-only `SyncSummaryStrip`, keeping the full `SyncStatusPanel` (counters, timestamps, retry) only on Offline Tools.
  - Simplified `EventReadyCard` from six status chips to a status badge plus a single network/roster meta row; folded network status into the sync panel header and removed the standalone Offline Tools connection card; collapsed redundant scanner action rows; removed the duplicate last-scan card from the Scanner tab and the now-unused `EaesMetricChip`.
  - Added toast-style auto-dismiss (4s) for success/warning global feedback banners.
  - Verified with `flutter analyze` (no issues) and `flutter test` (22 tests passing).
- **2026-06-30 (scan throttle + frame-exit guard):**
  - After a successful camera capture the Live Scanner now throttles detection for a short window (`AppConfig.postScanThrottleMs`, default 800ms, spec range 500–1000ms) to prevent rapid repeated reads of the next code; a green "Captured — ready for next" viewfinder state plus medium haptic confirms the capture during the pause.
  - Added an optional "QR must leave the frame" guard (`AppConfig.requireQrExitFrame`, default on): the same value is ignored while it lingers in view and is only re-armed after the camera reports no detections for `AppConfig.frameClearMs` (default 700ms). Frame-presence tracking updates on every detection, including during the throttle window, so the guard stays accurate.
  - These complement the existing per-record duplicate cooldown and `isSavingScan` guard without changing controller sync semantics.
  - Verified with `flutter analyze` (no issues) and `flutter test` (22 tests passing).
- **2026-06-30 (event lifecycle and scanner sessions):**
  - Added separate event lifecycle phase tracking from proposal status, scheduled lifecycle updates, scanner-session runtime tokens, assignment/session revoke dashboard actions, device binding, 72-hour sync grace enforcement, and per-scan rejection for offline timestamps outside the event scan window.
  - Flutter scanner now reads lifecycle/session state from validation, sends device identity during activation/hydration, blocks new local captures when the event is not active, and keeps sync available for pending scans during ended/evaluation phases.
- **2026-06-30 (mobile scanner authentication and assignments):**
  - Added `event_scanner_assignments` as the admin intent layer for event-scoped scanner access, plus assignment-aware scanner session activation APIs.
  - Added restricted mobile Google OAuth login (`scanner-assignments` token only), assignment listing, event activation, mobile logout, and assignment-aware hydrate/sync validation that prevents normal student tokens from scanning.
  - Added dashboard scanner-access modal controls for assigning/revoking student scanners and viewing assignment/session state.
  - Added Flutter login, assigned-events, activation, empty/revoked/offline messaging, secure auth/session persistence, and activation-to-hydration wiring.
- **2026-07-06 (mobile Google account picker hardening):**
  - Wrapped Flutter Google sign-in behind a testable gateway so Android initializes Credential Manager with the web `GOOGLE_SERVER_CLIENT_ID` while omitting Android `clientId`; `GOOGLE_HOSTED_DOMAIN` now defaults to empty so Android does not apply a native Workspace filter before Laravel performs `@usm.edu.ph` validation.
  - Android canceled credential results now surface as OAuth setup issues without reopening a second account picker.
  - Added mobile-login API tests for valid USM, non-USM, and unexpected-audience tokeninfo responses, plus Flutter coverage for Android initialization, explicit hosted-domain configuration, and canceled-result handling.
  - Captured this workstation's debug SHA-1 (`1E:F4:E2:DA:ED:C6:4F:33:E4:53:AE:32:03:6F:75:D9:CB:28:40:5A`) for Google Android OAuth registration with package `edu.usm.eaes.eaes_scanner`.
  - Documented Android OAuth setup for package `edu.usm.eaes.eaes_scanner`, signing SHA-1 registration, `GOOGLE_HOSTED_DOMAIN`, and backend `GOOGLE_MOBILE_CLIENT_IDS` alignment.
- **2026-07-10 (idempotent scanner activation):**
  - Changed mobile assignment activation from silent session replacement to safe activate/resume: same account + assignment + device reissues a token for the existing `scanner_sessions` row, while different-device activation returns `active_session_exists` until an admin revokes the old session.
  - Added platform-backed Flutter device identity for scanner assignment listing, activation, validation, hydrate, sync, and local offline scans so reinstall/reset can resume the same device-bound scanner session.
  - Added Flutter scanner-session snapshots and offline startup restore so a validated, hydrated scanner can reopen without internet, continue local capture inside the saved active scan window, and only clear the saved session after definitive backend invalidation.
- **2026-07-11 (hydrated roster visibility):**
  - Added a read-only Flutter Hydrated roster view from the scanner setup screen so assigned scanners can inspect the locally cached participant list for the active scope, search by allowed identity metadata, and refresh hydration without exposing QR values or changing backend hydrate contracts.
- **2026-07-11 (event schedule visibility):**
  - Surfaced event-day start/end times in the non-OSA-visible dashboard event list and Flutter scanner assignment/session views using existing scoped event-day payloads, without changing scanner authorization, hydration, or sync contracts.
- **2026-07-11 (scanner schedule timezone):**
  - Corrected scanner scan-window construction to treat stored event-day dates/times as USM local schedule time, made `APP_TIMEZONE` effective, and updated Flutter scheduled-event messaging to show date + time for future dates while keeping time-only copy for same-day events.
- **2026-07-17 (Livewire scanner access UX):**
  - Added `livewire/livewire` and converted the dashboard scanner-access modal into a Livewire component for no-reload scanner assignment, assignment revoke, and session revoke interactions with in-place loading feedback.
  - Preserved participation-scope authorization and scanner/session service boundaries while adding Livewire feature coverage for assignment and session revocation paths.
- **2026-07-17 (Livewire Events filter navigation):**
  - Replaced the Events workspace/status filters, search, rows-per-page control, event table, row-scoped modals, and pagination with a single Livewire workspace component for in-place list updates while preserving URL state.
  - Extracted the Events dashboard query/annotation logic into `DashboardEventWorkspaceService`, preserving tenant-scoped organized/participating boundaries, organizer-scope backfill, row actions, modals, and existing script initialization guards; added Livewire coverage for search, status filtering, and pagination.
- **2026-07-17 (Livewire notification inbox):**
  - Converted the dashboard notification bell into a Livewire component with in-place mark-read and mark-all-read actions, polling refresh, loading feedback, and `wire:navigate` action links while preserving the existing workflow inbox styling.
  - Added Livewire sidebar notification badges so unread and needs-action indicators refresh after no-reload notification actions; kept the full notification center POST routes available as fallback.
- **2026-07-17 (Livewire notification center page):**
  - Converted `/dashboard/notifications` into a Livewire notification center for in-place mark-read, mark-all-read, polling refresh, and Livewire pagination while preserving needs-action strips and notification routing.
  - Kept existing POST notification routes available as fallback and added Livewire coverage for center-page read actions and pagination.
- **2026-07-17 (Livewire Admin Access workspace):**
  - Converted the Admin Access organizations, admin assignments, and audit history workspace into a Livewire component with in-place filters, Livewire pagination, and no-reload assignment revoke/expire actions.
  - Preserved OSA-only access, OSA assignable-role limits, existing organization create/edit POST fallbacks, audit history filtering, and student profile membership separation; added Livewire coverage for organization filtering and assignment revocation.
- **2026-07-17 (Livewire Pending QR Links):**
  - Converted `/dashboard/pending-student-links` into a Livewire workflow with in-place QR/event/organization search, source filtering, pagination, and no-reload resolve/flag actions.
  - Preserved pending-link tenant scope, participant-scope student resolution checks, raw-scan student backfill, audit logging, attendance reprocessing dispatch, and existing POST routes as fallbacks; added Livewire coverage for filtering, resolve, and flag actions.
- **2026-07-17 (Livewire Events proposal workflow):**
  - Converted the Events table PPA packet indicators, proposal status chip, and primary submit/review/approve/reject/acknowledge CTAs into row-level Livewire components so common PPA workflow actions update in place without reloading the page.
  - Preserved organizer/OSA authorization, locked evaluation-form approval gating, participant invitation acknowledgement rules, overflow action dropdowns, and existing POST routes as fallbacks; added Livewire coverage for hardcopy recording, organizer submission, OSA review/approval, and missing-form approval blocking.
- **2026-07-17 (Livewire Attendance Rules):**
  - Converted the Events Attendance Rules modal body into a Livewire component for no-reload event late-cutoff saves, participant fallback cutoff saves, preset selection, inline validation, and in-modal feedback.
  - Preserved organizer-owned event cutoff precedence, participant-scope fallback gating, OSA read-only behavior, after-start locking, raw-scan reprocessing dispatch on scope cutoff changes, and existing POST routes as fallbacks; added Livewire coverage for event cutoff save/clear, scope fallback save, and locked-event blocking.
- **2026-07-22 (Livewire Participant Scopes modal):**
  - Converted the Events Participant Scopes modal into a Livewire component for no-reload invitation acknowledgement, participant PPA upload submission, OSA review/approve/reject decisions, and Add Participant Scope audience creation with inline loading, validation, search, and review-note feedback.
  - Preserved `ParticipationScopeService` ownership checks, OSA-only review authority, event-bound scope guards, duplicate/organizer audience skipping, notification dispatch, and existing POST routes as fallbacks; added Livewire coverage for acknowledgement, upload submission, OSA review decisions, organization audience creation, and College/all audience creation.
- **2026-07-22 (Livewire Create Event modal):**
  - Converted the Events New Event Proposal modal into a Livewire component for no-reload proposal drafting with official PPA softcopy upload, schedule-adjacent generated daily attendance windows, same-time day syncing, compact event-specific evaluation questions, inline validation, and Livewire Navigate redirect after creation.
  - Preserved recognized-organizer checks, HR event softcopy exemption, parent-permit restrictions, event-day date-range validation, locked evaluation-form creation, organizer scope creation, storage cleanup on failure, and the existing POST route as a multipart/named-field fallback from the modal with old-input restoration; added Livewire coverage for successful draft creation, generated day rows, fallback field preservation, softcopy/question/date validation, and fallback form contract.
- **2026-07-22 (Livewire Events sentiment action):**
  - Converted the Events table AI sentiment queue action into a Livewire button with in-place queued/open-window feedback while preserving the manual POST route as fallback.
  - Preserved event tenant authorization, evaluation-window closure gating, pending-comment counting, and `AnalyzeEventSentimentsJob` dispatch; added Livewire coverage for closed-event dispatch and open-event rejection.
- **2026-07-22 (Livewire AI Insights query):**
  - Converted the AI Insights NLP query assistant into a Livewire component with no-reload query execution, one-click suggestions, inline validation, loading feedback, query errors, and scoped result tables.
  - Preserved the whitelisted `NlpQueryExecutor`, AI provider binding, admin-only access, existing POST query route as fallback, and tenant-scoped result filtering; reused the Livewire sentiment action in the AI queue and added Livewire query coverage.
- **2026-07-22 (Livewire Organization Analytics filters):**
  - Converted the Organization Performance Analytics overview into a single Livewire-owned workspace so Apply/Clear update the filters, KPI cards, leaderboards, and organization/program results in place without a full-page GET reload.
  - Preserved the existing `AnalyticsOverviewController` metric pipeline, admin assignment scope locks, shareable filtered analytics URLs, cascading college/organization/program options, mobile collapse behavior, active filter chips, loading feedback, and an explicit GET form fallback with named filter fields; added Livewire coverage for scoped filter application.
- **2026-07-22 (Livewire Event Analytics participant drilldown):**
  - Converted the per-event Participant Drilldown filter bar, row count, results table, and paginator into a Livewire component with in-place Apply/Clear, active filter chips, accessible labels, rows-per-page control, and loading feedback.
  - Preserved `EventAnalyticsService::participantRows`, event tenant authorization, shareable filtered event analytics URLs, and the standard GET route as fallback with named filter fields and Attendance-tab preservation for query-string reloads; added Livewire coverage for participant filter application, plain GET filtering, and pagination.
- **2026-07-22 (Analytics and Event Proposal filter UX):**
  - Refined per-event Attendance participant filters with visible labels, grouped controls, active count placement, and compact responsive search/rows controls while preserving Livewire Apply/Clear and named GET fallback fields.
  - Kept Event Proposal workspace/status controls as separate tab rows and reduced the list utility to a small debounced search field with Organization Profiles-style Alpine row filtering for immediate mobile typing feedback, plus Livewire-backed server search and plain GET fallback; removed the visible rows control and polished proposal modal focus/row states without changing PPA validation or approval flow.
- **2026-07-22 (Livewire frontend bootstrap repair):**
  - Replaced the separate Alpine startup with Livewire 4's Vite ESM bootstrap and `@livewireScriptConfig`, eliminating the duplicate Alpine runtime that left `wire:click` and `wire:submit` controls inert across Event Proposals, Attendance Rules, analytics filters, notifications, Admin Access, Pending QR Links, and AI Insights.
  - Added a bootstrap regression test and verified Event Proposal feedback, Hardcopy request dispatch, Attendance Rules presets, Create Event row actions, cross-page analytics filters, focused Livewire feature tests, and the production Vite build.
- **2026-07-23 (instant Event Proposal modal controls):**
  - Moved Create Event date-range/day-row editing and Attendance Rules preset selection to immediate Alpine state with deferred Livewire entanglement, removing per-click server round trips while preserving the existing Livewire validation and save flows for PRD Epic 2 and Epic 4.
  - Made cutoff highlighting reflect the actual selected value instead of permanently recommending 15 minutes, and made successful Event Proposal and Attendance Rules row feedback dismiss after four seconds. Added a frontend interaction contract test; verified all 184 tests/1,187 assertions, the production Vite build, and browser timings of 48 ms for Add Day and 58 ms for Delete Day with zero Livewire update requests.
- **2026-07-30 (Admin Access and participant scope modal responsiveness):**
  - Moved Admin Access assignment/organization Alpine helpers into the single Vite Livewire bootstrap, added typed email/student-ID fallback and modal-visible validation for Assign Admin, and kept `admin_assignments` creation under the existing OSA-scoped controller/service checks from PRD Feature 1.3.
  - Changed Add Participant Scope audience tabs, local search, checkbox selection, capture mode, and option count to immediate Alpine state with deferred Livewire entanglement; final scope creation still runs through `EventParticipantScopes::addParticipantScopes` and `ParticipationScopeService` for PRD Feature 2.3 boundaries.
- **2026-07-22 (Event lifecycle lazy refresh):**
  - Added an Event Proposals workspace safety refresh that recalculates accessible approved/completed event lifecycle phases once on page open before counts and rows render, so stale `evaluation_open` records advance toward processing/completed even if the scheduler was not running locally.
- **2026-07-22 (scanner scan-type windows):**
  - Split scanner capture validation into scan-type windows: configured early Time-In before scheduled start and configured post-event Time-Out checkout after scheduled end, while keeping Time-In closed after event end and Time-Out closed after checkout grace.
  - Exposed mode-specific scanner-session window metadata to Flutter, preserved legacy `scan_window_start/end` fields, and updated offline capture tests for early check-in and post-event checkout.
- **2026-06-30 (assignment-only scanner workflow):**
  - Removed the user-facing dashboard scanner-link generation route/action, deep-link flash card, Generate Link/Regenerate/Add Device controls, and mobile manual scanner-link entry flow.
  - Made assignment activation (`POST /api/v1/scanner/assignments/{assignment}/activate`) the only supported scanner session creation path; `scanner_sessions` remain as the internal runtime/device/audit layer for hydrate and sync.
  - Scoped dashboard scanner search/selection to the admin's authorized organization or college boundary and kept active device/session history visible for monitoring and revocation.
- **2026-07-01 (admin scope separation):**
  - Added `AdminScopeService` / `AdminScope` so dashboard, API, export, analytics, pending QR, scanner assignment, scanner access, event tenant, and NLP query boundaries resolve admin authority from active primary `admin_assignments`.
  - Changed admin assignment projection sync to grant/remove Spatie admin roles only; revoking or expiring an assignment no longer mutates the student's profile organization, college, or program.
  - Kept student portal profile/history, scanner eligibility, roster hydration, analytics denominators, and pending QR student resolution on the existing student profile membership columns.
  - Added admin-capable sidebar navigation back to Student Portal while preserving dashboard-first login for admin users.
- **2026-07-01 (admin role cleanup):**
  - Retired the unused records-office admin role and organization shell from role seeders, institutional master-data seeding, admin applications, route guards, dashboard/API scope checks, scanner access roles, parent-event linking, analytics audience logic, and tests.
  - Kept OSA/Super Admin as a staff/admin-only account: OSA no longer gets Student portal navigation or `/portal` access unless the account explicitly has the Student role.
- **2026-07-01 (OSA-only admin and organization onboarding):**
  - Removed student portal admin-application routes, sidebar links, controller, and view; students manage profile, attendance history, evaluations, and scanner access only.
  - Redesigned OSA Admin Access as the primary workflow: user search, direct assignment creation, organization management, and assignment revoke/expire actions.
  - Added `OrganizationManagementService` and OSA-only organization create/update routes for society, LSG, and USG records.
  - Deprecated `admin_applications` table usage in routes/UI while preserving the physical table for later cleanup.
- **2026-07-01 (Admin Access UI Refinement):**
  - Replaced the two-column grid layout in the OSA Admin Access page with a single full-width card layout managed by Alpine.js segmented tabs.
  - Implemented independent filter and search controls for both the Organizations and Admin Assignments tabs.
  - Added a search filter, role filter, status filter, and term filter to the Admin Assignments tab, wiring them up in the backend controller.
  - Preserved each tab's active and query state using URL parameter sync and tab-aware backend redirects.
- **2026-07-07 (mobile aesthetic overhaul):**
  - Overhauled the presentation layer in Flutter app with dark-themed, green+gold branded designs.
  - Forced dark mode and customized App themes, AppBar themes, NavigationBar themes, cards, buttons, text fields, chips, and dividers with USM Gold (#D4A843), USM Green (#1B4D3E), and near-black surfaces.
  - Implemented custom staggered entrance animations on the Assigned Events screen.
  - Refactored 14 presentation files (screens and widgets) to align with this new premium aesthetic and cleanly pass static analyzer checks.
- **2026-07-07 (event workflow scanner scope repair):**
  - Clarified that the organizing organization is automatically included through the organizer attendance scope and must not submit a duplicate participant PPA for its own event.
  - Made dashboard scanner candidate search membership-aware, added a scoped scanner-candidate lookup endpoint, auto-healed missing organizer attendance scopes for displayed legacy events, and relabeled scanner assignment selection to attendance scope to reduce organizer-vs-participant confusion.
  - Synced student profile updates into `organization_memberships` so organizer-affiliated student registrations remain visible to participation-scope rosters and scanner assignment search.
  - Improved the scanner assignment modal with a compact multi-select tray, outside/focus-leave dropdown closing, and one-submit bulk assignment while preserving existing scope validation.
  - Added explicit start and end time metadata to the OSA event details modal using stored `event_days` times.
  - Changed Flutter scanner hydration to replace the local roster for the active session instead of accumulating stale roster rows from prior event/scope hydrations.
- **2026-07-08 (Redis queue/cache readiness):**
  - Added `predis/predis` so Redis-backed queue/cache can run without the Windows PHP Redis extension.
  - Switched Redis env templates to `REDIS_CLIENT=predis` and documented the local Redis server/env switch needed before replacing the database queue fallback.
  - Hardened `eaes:deployment-check` to fail clearly when Redis is configured but the selected PHP Redis client is unavailable.
- **2026-07-30 (Livewire Blaze removal):**
  - Removed `livewire/blaze` after review because the app currently has a small anonymous Blade component surface and the main layout relies on a `View::composer`.
  - Removed the `Blaze::optimize()` provider configuration and kept the standard Laravel view-composer path.
- **2026-07-30 (Composer security patch update):**
  - Updated vulnerable Composer transitive dependencies with dependency movement allowed: `dompdf/dompdf` 3.1.6, `guzzlehttp/guzzle` 7.15.2, and `phpoffice/phpspreadsheet` 1.30.6.
  - Verified `composer audit` reports no advisories and the Laravel backend test suite passes with 189 tests / 1,246 assertions.
- **2026-08-08 (CommonMark security patch update):**
  - Updated the Laravel framework's transitive `league/commonmark` dependency from 2.8.2 to 2.9.0 to resolve six newly reported security advisories.
  - Verified `composer validate` and `composer audit` pass, with the Laravel backend test suite passing 190 tests / 1,250 assertions.
- **2026-08-08 (shared proposal persistence):**
  - Routed Livewire, multipart web fallback, and API proposal create/update operations through `EventProposalDraftService`, centralizing transactions, proposal-document cleanup/replacement, ordered event days, evaluation snapshots, parent-permit rules, HR exemptions, and organizer-scope packet synchronization for PRD Feature 2.1 and Feature 2.4.
  - Aligned student-affairs API proposal creation with the digital PPA contract by requiring a PPA category and official proposal document, retained HR exemptions, returned domain failures as `422` without exposing unexpected exceptions, and kept `event_late_cutoff_min` under the separate Attendance Rules workflow from PRD Feature 4.2.
  - Added multipart API coverage for proposal metadata, missing documents, HR creation, parent and tenant boundaries, legacy document requirements, revision-requested updates, document replacement cleanup, ordered day numbering, evaluation snapshots, organizer-scope synchronization, and attendance-cutoff immutability; verified 192 Laravel tests / 1,280 assertions plus clean Composer validation and security audit.
- **2026-08-08 (participant-scope concurrency protection):**
  - Added fail-safe PostgreSQL/SQLite partial unique indexes for active organizer, organization participant, College participant, and linked-event participant identities; migrations now stop before DDL when duplicate active audiences require manual reconciliation.
  - Made `ParticipationScopeService` use `firstOrCreate()` and `wasRecentlyCreated` for idempotent organizer/audience creation, first-writer configuration retention, and single invitation delivery; the Livewire flow and web fallback both use this shared path.
  - Centralized linked-event scope synchronization, reusing unlinked manual invitations, preserving existing scope configuration, and returning web redirect/API `422` domain feedback with parent-link rollback for conflicting linked events. Added database, service, web, API, and migration-preflight coverage while keeping Epic 2 / Feature 2.3 `Done`.
- **2026-08-08 (scanner sync concurrency hardening):**
  - Added fail-fast PostgreSQL/SQLite partial unique indexes for all nullable canonical attendance identity variants and active unresolved QR links; existing duplicate data now stops migration before any DDL and requires manual reconciliation.
  - Routed raw-scan winner detection and pending-link creation through `AttendanceSyncPersistenceService`, so replays and competing scanner uploads count only persisted winners and never dispatch duplicate attendance resolution work.
  - Hardened `ResolveAttendanceJob` with scoped overlap coordination, database parent-row locks, retry/timeout policy, PII-safe failure logs, and queue visibility timeouts above job runtime. Added SQLite feature coverage plus an opt-in `phpunit.pgsql.xml` suite targeting only `eaes_concurrency_test` for real PostgreSQL contention.
- **2026-08-08 (Redis queue resilience and load readiness):**
  - Added a transactional `queue_outbox_entries` handoff for persisted scanner winners, immediate safe dispatch plus scheduled recovery dispatch, `attendance`/`default`/`ai` worker separation, bounded AI job retry/overlap policy, queue health checks, production Redis connectivity checks, and Supervisor worker definitions.
  - Added a local Redis 7 Compose service and a guarded k6 scanner-sync harness for an isolated 20-scanner, 200-record batch workload. The Flutter scanner now honors `Retry-After` and retries transient rate-limit, Redis-coordination, and network sync failures while preserving local scans.
