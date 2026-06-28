# PRD Progress Audit — USM EAES

**Branch / snapshot:** `main` (local)  
**PRD source:** `PRD_V1.md` (v2.0 content, filename `PRD_V1.md`)  
**Last reviewed:** 2026-06-29  
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
| Backend Composer dependencies (`vendor`) | Done | Spatie permission, Laravel Socialite, Sanctum, DomPDF, and Laravel Excel installed |
| Web Node dependencies (`node_modules`) | Done | Dependencies installed and locked (alpinejs, html5-qrcode), Tailwind v4 configured, Vite production assets built on 2026-06-26 using local Node 20.19.4 |
| `APP_KEY` / env setup | Done | Key generated, environment configured in `Web/.env` |
| PostgreSQL 16 (PRD §7) | Done | Laragon PHP 8.4.5 now runs with `pdo_pgsql`; `artisan migrate:fresh` and `artisan test` pass against local PostgreSQL 16 |
| API layer + Sanctum (Epic 1) | Done | Sanctum API setup, token login, logout, session validate routes, and UUID-compatible token ownership created |
| Redis queues (PRD §3.3) | Off-PRD | Job processing active; database queue driver used locally as Redis fallback |
| Project `AGENTS.md` + Cursor rules | Done | Root `AGENTS.md`, `.cursor/rules/` |

---

## §1 Executive Summary & Objectives

| Item | Status | Evidence / Notes |
|------|--------|------------------|
| Scope: evaluation-focused (no digital semestral accomplishment report) | N/A | Confirmed in PRD §1.2 |
| PPA digitization | Done | Web dashboard and APIs capture proposal metadata, official PPA softcopy upload, hardcopy/signature status, submit/review/approve/reject, and scanner links |
| QR attendance + evaluation gate | Done | Sync controllers, EILO background job, pending QR dashboard, and student evaluation gate verified |
| Offline scanner + sync | Done | Flutter scanner has deep-link session parsing, mode switching, SQLite repository, camera/manual capture UI, API sync client, controller tests, and backend dashboard-link token coverage; Flutter format/analyze/test verified on 2026-06-26 |
| Swappable Gemini/Ollama NLP Assistant | Done | Container-bound AiServiceInterface supporting Gemini API and OpenAI-compatible Ollama Cloud (with connect & request timeouts, robust error logging, and dynamic UI branding) |

---

## §2 RBAC (Spatie)

| Item | Status | Evidence / Notes |
|------|--------|------------------|
| `spatie/laravel-permission` package | Done | Installed and customized to support UUID model_id |
| Roles & permissions seeders (§2.2 table) | Done | `RolePermissionSeeder` maps all permissions and roles for web/api guards, including ARO Admin |
| Tenant-scoped middleware (org/college) | Done | `TenantScopeMiddleware` intercepts routing to restrict organization boundaries, including ARO own-organization scope |
| User model aligned with PRD (UUID, `google_sub`, `organization_id`, etc.) | Done | `User.php` updated with UUID primary key, `HasUuids` trait, and fillables |

---

## §3 Offline-First & Sync

| Item | Status | Evidence / Notes |
|------|--------|------------------|
| Roster hydration API (§3.1) | Done | Hydrate route `/events/{id}/hydrate` returns compressed student roster |
| Unresolved scan + server resolution (§3.2) | Done | `raw_scans` supports nullable `student_id`; pending QR links table and resolve/flag views with audit logging implemented |
| Bulk sync + idempotency keys (§3.3) | Done | `/attendance/sync` API ingests logs, checks `dedup_key` duplicates, and saves scans |
| `ResolveAttendanceJob` + queue throttling | Done | Queueable job executes lockForUpdate transaction EILO mappings |
| Flutter offline SQLite scanner | Done | `sqflite`, `mobile_scanner`, `app_links`, `connectivity_plus`, and API client dependencies configured; local DB/sync classes, testable controller contracts, and offline/sync unit coverage verified |

---

## §4 Epics

### Epic 1: Authentication & Onboarding

| Feature | Status | Evidence / Notes |
|---------|--------|------------------|
| 1.1 Google OAuth + `@usm.edu.ph` + Sanctum tokens | Done | `GoogleAuthController` redirects with account selection + USM domain hint, checks domain, clears rejected sessions, and issues web auth |
| 1.2 Student profile + QR registration | Done | Student profile page with dependent college-to-organization select dropdowns and html5-qrcode webcam capture/manual entry fallback |
| 1.3 Admin applications + assignment governance | Done | Student portal application flow, OSA review console, term-bound `admin_assignments`, new Society approval, and `AdminAssignmentService` as the only admin-role/scope projection writer |

### Epic 2: PPA

| Feature | Status | Evidence / Notes |
|---------|--------|------------------|
| 2.1 Digital PPA submission | Done | Event proposal creation saves location details, budgets, days, official proposal softcopy, resolution number, and hardcopy/signature status before submission/approval |
| 2.2 OSA review pipeline | Done | OSA review, approval, rejection status routes completed; OSA lists now exclude organizer-private drafts and expose proposal detail metadata for review |
| 4.2 Dual-threshold statuses | Done | Computes society status and general competition status against thresholds |
| 4.3 Multi-day session management | Done | EILO groups scans by day and maps to specific `event_day_id` records |

### Epic 5: Evaluation Portal & Gate

| Feature | Status | Evidence / Notes |
|---------|--------|------------------|
| 5.1 Digital evaluation form | Done | Student portal view with centralized v1 categories (objective attainment, speaker mastery, venue comfort), Likert radios, comments, window status indicator, and confirmation modals |
| 5.2 Evaluation gate (`valid` flag, 24h window) | Done | Toggles record validity on evaluation store; validates 24h time-limit |

### Epic 6: Analytics & Export

| Feature | Status | Evidence / Notes |
|---------|--------|------------------|
| 6.1 Aggregated analytics dashboards | Done | Two-level analytics implemented: (1) per-event modal in events dashboard with attendance, evaluation, sentiment, and demographic breakdowns; (2) Organization Performance Analytics page supporting program-level filtering under each organization (cascade filters: SY, Sem, College, Org, Program, Event Type), detailed late/absent/left-early indicators, and a Program Comparison Leaderboard — all scoped by Spatie RBAC. |
| 6.2 PDF/Excel export engine | Done | Dashboard export routes generate scoped PDF/XLSX attendance and evaluation reports with event metadata, PPA hardcopy status, validity, ratings, comments, sentiment fields, timestamp, and generated-by user |
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
| `users` (PRD shape) | Done | Custom user columns migrated; college_id and program_id UUID foreign keys added |
| `programs` | Done | UUID schema representing academic programs under colleges, migrated with unique college+code constraint |
| `organization_programs` | Done | Pivot mapping many-to-many relationship between organizations and programs, migrated |
| `admin_applications` | Done | OSA-reviewed admin access and new Society requests with duplicate pending-application guard |
| `admin_assignments` | Done | Source-of-truth admin authority table with active primary admin uniqueness by role/scope/academic year |
| `events` | Done | Custom PPA columns (location, budget, target) migrated with UUID |
| `event_days` | Done | UUID schema defined and migrated |
| `raw_scans` | Done | Nullable student, QR, dedup_key columns migrated with UUID |
| `attendance_records` | Done | EILO statuses, validity, and override columns migrated with UUID |
| `evaluations` | Done | Score JSONB, comment, sentiment metrics migrated with UUID |
| `audit_logs` (NFR) | Done | Action, UUID targets, and change details migrated with UUID |
| `pending_student_links` (§3.2) | Done | UUID table added for unresolved QR review, resolution, and flagging |

---

## §8 Non-Functional Requirements

| Item | Status | Evidence / Notes |
|------|--------|------------------|
| Bulk sync concurrency / queue protection | Done | Pessimistic `lockForUpdate` prevents database write deadlocks |
| PII / HTTPS expectations | Done | Deployment templates, security headers, HTTPS/session checks, dev-login gating, demo seeder, deployment check command, and PII hardening guide completed |
| Immutable `audit_logs` for admin resolution actions | Done | `audit_logs` schema migrated; pending QR resolve/flag actions are recorded |

---

## Off-PRD / Deviations

| Date | Description | Reason | Approved by |
|------|-------------|--------|-------------|
| 2026-05-30 | Database Queue Fallback | Redis database extension missing on local environment; fallback used for jobs | Auto-approved for Dev |

---

## Sprint Notes

- **2026-05-29:** Repository contains Laravel 12 skeleton in `Web/` and PRD only. No domain migrations, packages, API, or mobile app. Next recommended milestone: `composer install`, PostgreSQL env, `php artisan install:api`, Spatie + initial UUID schema per PRD §7.
- **2026-05-30:** 
  - Installed `spatie/laravel-permission`, `laravel/socialite`, and `laravel/sanctum` packages.
  - Implemented all UUID-based database migrations (universities, colleges, organizations, users, events, event days, raw scans, attendance records, evaluations, audit logs).
  - Seeded Spatie roles and permissions via `RolePermissionSeeder` for both web and api guards.
  - Built custom `TenantScopeMiddleware` to enforce organizational tenancy boundaries on administrative actions.
  - Implemented Google Socialite web routes/redirection and Sanctum scanner API token validation controllers.
  - Built PPA proposal submission, OSA review, and multi-organization event linking APIs.
  - Implemented hydration endpoint (`/hydrate`) and batch synchronization endpoint (`/sync`) chunking with client-side idempotency checks.
  - Implemented background `ResolveAttendanceJob` resolving raw scans via EILO algorithms and checking dual thresholds.
  - Created `EvaluationController` supporting Likert scale questionnaire submissions and locking attendance validity.
  - Integrated `GeminiService` client wrappers for batch comment sentiment analysis and NLP query parsing.
  - Built `AnalyticsController` for stats reports and Gawad Parangal organizational membership active ratios.
  - Written and validated full E2E pipeline test suite (`UsmEaesTest.php`) executing all modules successfully.
- **2026-06-25:**
  - Added ARO organizer scope for Recognition and Graduation events per PRD §2.2 and Epic 2; backend role, tenant checks, admin routes, parent-event linking, analytics audience counts, and pending QR resolution now support `organizations.type = aro`.
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
  - Advanced PRD Epic 3 by making dashboard-generated scanner deep links usable by the Flutter scanner API path:
    - Added scanner-token ability authorization for Sanctum-protected hydrate/sync routes without granting scanner access to student tokens.
    - Added backend feature coverage for dashboard-generated scanner-link validation, roster hydration, sync ingestion, same-batch duplicate dedup handling, cross-tenant rejection, unresolved QR handling, and EILO attendance resolution.
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
    - Added `AnalyticsOverviewController` with full RBAC tenant scoping: OSA sees all orgs, LSG sees college orgs, Society/USG/ARO see own org only.
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
  - Added `AdminAssignmentService` to centralize all admin role grants/removals plus `users.organization_id` / `users.college_id` compatibility projection sync; controllers no longer assign admin roles directly.
  - Added official master-data seeding for Colleges, Programs, USG, ARO, and per-college LSG shells while keeping Society registration OSA-reviewed.
  - Added constraints for duplicate pending admin applications, active primary admin assignments, program college+code identity, and organization type+college+acronym identity.
  - Added an OSA proposal-detail modal showing schedule, event days, venue, offices, target participants, funding, budget, resolution, softcopy, hardcopy, and signature metadata before review actions.
  - Improved proposal form defaults so start date pre-fills end date and D1 date without overwriting deliberate multi-day edits, and added a shared sign-out confirmation modal.
- **2026-06-28 (Google auth guardrail):**
  - Hardened Google OAuth redirect with forced account selection and an `hd=usm.edu.ph` hint so a rejected personal Gmail session does not trap the next login attempt.
  - Rejected non-institutional callbacks now clear local auth session state before returning to login; verified with `php artisan test --filter=GoogleAuthControllerTest`.
- **2026-06-29 (scanner-link token schema):**
  - Corrected Sanctum `personal_access_tokens.tokenable_id` from bigint to UUID for PostgreSQL so dashboard-generated scanner links can create event-scoped tokens for UUID users.
  - Updated the fresh-install migration to use `uuidMorphs('tokenable')`, added a one-time PostgreSQL conversion migration, and verified the local database column reports `uuid`.
  - Verified with `php artisan test --filter=test_scanner_link_generation` and `php artisan test --filter=test_dashboard_generated_scanner_link_drives_hydrate_sync_and_eilo_resolution`.
