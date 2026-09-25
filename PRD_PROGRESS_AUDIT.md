# PRD Progress Audit — USM EAES

**Branch / snapshot:** `main` (local)  
**PRD source:** `PRD_V1.md` (v2.0 content, filename `PRD_V1.md`)  
**Last reviewed:** 2026-09-24
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
| PPA digitization | Partial | Existing web and API flows now create a structured `canonical_ppa_packets` source for organizer/co-organizer proposal drafts while preserving compatibility projections into `events`; they also capture official PPA softcopy, legacy hardcopy/signature status, venue evidence, OSA review, immutable permit versions, amendments, participant PPAs, and scoped scanner assignments. Revised Epic 2 still requires participant packet reuse, generated proposal/permit views, configurable templates, Workflow Runs and Tasks, Institutional Approver Accounts, the Organizer Checklist, Approval Trails, and Permit Copy Owners. |
| QR attendance + evaluation gate | Done | Sync controllers, EILO background job, pending QR dashboard, and student evaluation gate verified |
| Offline scanner + sync | Done | Flutter scanner has Google mobile login, assigned-event listing/activation/resume, platform-backed device identity, participation-scope display, mode switching, SQLite repository, camera/manual capture UI, scope-stamped offline scans, API sync client, restart-safe scanner-session snapshots, offline restore after app close, controller tests, scanner-session phase indicators, and assignment-activation token coverage |
| Event lifecycle + scanner sessions | Done | `events.lifecycle_phase`, `events.event_domain`, `event_participation_scopes`, `event_scanner_assignments`, `scanner_sessions`, assignment/scope-aware hydrate/sync guards, revocation, device binding, venue-amendment session revocation, idempotent same-device activation resume, cross-device transfer blocking, scan-window validation, scheduled lifecycle updates, and Event Proposals lazy lifecycle refresh implemented |
| Swappable Gemini/Ollama NLP Assistant | Done | Container-bound AiServiceInterface supporting Gemini API and OpenAI-compatible Ollama Cloud (with connect & request timeouts, robust error logging, and dynamic UI branding) |

---

## §2 RBAC (Spatie)

| Item | Status | Evidence / Notes |
|------|--------|------------------|
| `spatie/laravel-permission` package | Done | Installed and customized to support UUID model_id |
| Roles & permissions seeders (§2.2 table) | Done | `RolePermissionSeeder` maps all active permissions and roles for web/api guards |
| Tenant-scoped middleware and Authority Projection | Partial | Existing `TenantScopeMiddleware` and `AdminScopeService` restrict organization/college boundaries through active `admin_assignments`. Revised PRD requires School-Year-aware Authority Projections sourced from verified Officer Rosters, Department scope, explicit multi-organization context selection, and audited OSA overrides. |
| User model aligned with PRD (UUID, `google_sub`, Student Academic Path, etc.) | Done | `User.php` has UUID identity, current College/Department/Program/Organization profile projections, Year Level, user type, and profile flow validation for College -> Department -> Program -> eligible Organization/Society relationships. |

---

## §3 Offline-First & Sync

| Item | Status | Evidence / Notes |
|------|--------|------------------|
| Roster hydration API (§3.1) | Partial | Hydrate route `/events/{id}/hydrate` is correctly scoped to the activated `event_participation_scope_id` and supports student, multi-membership, non-academic, and HR paths. Revised PRD still requires School Year, Department, Program, eligible Organization/Society, membership status, participant-PPA status, and Officer-Derived Authority to feed the roster and scanner-assignment resolvers. |
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
| 1.2 Student profile + QR registration | Done | Student profile onboarding/edit mode supports dependent College -> Department -> Program -> eligible Organization/Society -> Year Level selects, webcam/manual QR capture, backend cascade validation, user profile projections, and active-School-Year primary `organization_memberships` persistence. |
| 1.3 School Year and officer governance | Partial | Existing OSA Admin Access, `admin_assignments`, audit history, organization management, and scoped `AdminScopeService` provide reusable enforcement infrastructure. Missing revised scope: OSA-controlled School Years and transition periods, Officer Rosters selected from registered members, Adviser Verification, Officer Change Requests, automatic President/Secretary Authority Projections, explicit scope selection for multiple authorities, and OSA-only advanced overrides. Current `admin_assignments` remain the source of truth and direct OSA assignment path, which the revised PRD supersedes for normal student-organization authority. |

### Epic 2: PPA

| Feature | Status | Evidence / Notes |
|---------|--------|------------------|
| 2.1 Digital PPA packet and workflow binding | Partial | Existing Livewire, web fallback, and API proposal writes share `EventProposalDraftService` for draft-safe venue/PPA capture and now synchronize organizer/co-organizer draft data into `canonical_ppa_packets` with activity role, proponent, schedule, venue, budget, objective, methodology, activity, output, and beneficiary fields while preserving legacy `events` projections. The current submission guard enforces venue/PPA evidence and documented HR/participant exemptions. Missing revised scope: participant packets do not yet reuse this table, generated proposal/permit views are not rendered from the packet, and first submission does not select an applicable immutable Published Template Version or create a proposal Workflow Run. |
| 2.2 Proposal Process Template governance | Not Started | No OSA-managed template, immutable version, step/dependency, applicability-rule, or assignment-rule models and administration surfaces exist. |
| 2.3 Institutional approval tasks and dashboard decisions | Not Started | Current approval remains an OSA-only event transition and legacy `head_organization_signed` / `adviser_signed` flags remain in use. Institutional Approver Accounts, School-Year/Department/Officer-Roster-aware task resolution, distinct Chief Security and OSA entities, shared approver work queues, canonical task decisions, controlled reassignment, and dashboard decisions as the signature replacement are not implemented. |
| 2.4 Organizer checklist and revision loop | Partial | Existing proposal create/edit and OSA revision/resubmission paths preserve remarks and event status transitions. Missing revised scope: the selected two-pane Organizer Checklist, connected progress rail, prerequisite-aware task states, current-action panel, Workflow Run preservation, and task-level notification links. |
| 2.5 Approval Trail, Digital Permit, and permit versioning | Partial | Existing `EventPermitService` creates immutable sequential `event_permit_versions`, preserves historical venue/PPA snapshots, handles pre-event amendments, revokes active scanner sessions, and keeps scanner assignments. Missing revised scope: immutable workflow-specific Approval Trails, all-required-task plus OSA gating, approval metadata blocks, workflow-linked permit snapshots generated from the approved Canonical PPA Packet, and Permit Copy Owner records for OSA, Security, and the organizer. |
| 2.6 Participant PPAs and distributed attendance ownership | Partial | `event_participation_scopes` model/migration, organizer attendance scopes, Organized/Participating Events dashboard tabs, in-app invitation notifications/acknowledgements/reminders, participant PPA submit/review/approve/request-revision/reject routes, scope-aware scanner assignment/revocation, runtime hydrate/sync gating, and roll-up analytics implemented. Missing revised scope: participant PPAs must reuse the Canonical PPA Packet with `activity_role=Participant`, bind to participant-specific template/workflow behavior where configured, inherit parent venue/date/permit context, and avoid duplicate venue confirmation or venue-custodian routing by default. |
| 2.7 Event domains | Done | `events.event_domain`, HR Admin role/permissions, employee roster models, non-student Google onboarding separation, HR no-PPA event creation, and HR roster hydration path implemented; HR Admin provisioning is not handled through OSA Admin Access. |
| 4.2 Organizer-owned late cutoff | Done | Revised from OSA-owned dual thresholds to one official late-cutoff policy: organizer event cutoff is managed from separate Attendance Rules UI, applies first, locks after event start, and falls back to participant scope cutoffs only when unset; legacy status columns are preserved as compatibility mirrors |
| 4.3 Multi-day and multi-session attendance management | Done | EILO groups scans by participation scope, day, and configured `event_day_sessions`; scanner sync resolves session windows with the existing early Time-In / Time-Out grace periods, rejects between-session captures, and writes canonical attendance per `event_day_session_id` with `completion_status`. |

### Epic 5: Evaluation Portal & Gate

| Feature | Status | Evidence / Notes |
|---------|--------|------------------|
| 5.1 Digital evaluation form | Done | Event proposals now store locked event-level evaluation form snapshots with organizer-drafted dynamic 1-5 Likert questions plus one open comment; OSA proposal review shows the form before approval; student validation, analytics, and exports resolve question labels from the event snapshot with legacy default fallback |
| 5.2 Evaluation gate (`valid` flag, 24h window) | Done | Toggles record validity on evaluation store; validates 24h time-limit |

### Epic 6: Analytics & Export

| Feature | Status | Evidence / Notes |
|---------|--------|------------------|
| 6.1 Aggregated analytics dashboards | Partial | Existing event and Organization Performance analytics provide shared metric logic, Program/Year Level demographics, evaluations, sentiment, participant-scope/scanner context, and SY/Semester/College/Organization/Program/Year Level/Event Type filters. Revised PRD requires first-class School Year records, Department between College and Program, corrected Society eligibility, School-Year-aware authority, cascading scope validation, and immutable historical hierarchy snapshots. |
| 6.2 PDF/Excel export engine | Done | Dashboard export routes generate scoped PDF/XLSX attendance and evaluation reports with event metadata, participant scope, PPA hardcopy status, student Program/Year Level columns, validity, ratings, comments, sentiment fields, timestamp, and generated-by user |
| 6.3 Gawad Parangal metrics | Partial | Web analytics and the Gawad API share `OrganizationAnalyticsService` with organizer-scope attendance denominators, active-member counts, evaluation scores, and current filters. Revised PRD requires the corrected School Year, Department, Program, Society/Organization, membership-status, and Participation Scope model before the metrics are complete. |

---

## §5 Algorithms

| Item | Status | Evidence / Notes |
|------|--------|------------------|
| EILO sync resolution (§5.1) | Done | Validated by PHPUnit feature tests including day-level legacy coverage in `UsmEaesTest` and session-level resolution/window coverage in `EventDaySessionTest` |

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
| `departments` | Partial | First-class `departments` UUID table, College -> Department relationship, Department model/factory, OSA-only dashboard API CRUD routes, explicit CEIT Department seed mappings, Program/Department-backed Organization relationships, and Student Academic Path profile/membership consumption exist. Proposal, scanner, and analytics consumers still need full Department ownership migration. |
| `school_years` | Partial | First-class `school_years` UUID table, OSA-only dashboard API create/list/activate/open-transition/close-transition routes, transactional active-year resolver, database-level single-active partial unique index, lifecycle metadata, audit rows, and active-year student profile membership assignment exist. Officer rosters, authority projections, proposals, scanner scopes, and analytics still need `school_year_id` integration. |
| `organizations` | Partial | UUID organization schema, status/logo fields, College compatibility identity, nullable `department_id`, Department relationship, OSA org-management validation for Department-backed Societies/Academic Units, and CEIT demo society mappings exist. Downstream contracts still need explicit University/College/Department scope cleanup. |
| `users` (PRD shape) | Done | Custom user columns now include College, Department, Program, Organization, Program Code, Year Level, Google identity, and user type profile projections, with Student Academic Path validation on profile save. |
| `programs` | Partial | Programs now have nullable `department_id`, Department relationship, and compatibility College resolution while retaining direct `college_id` for existing callers. Explicit CEIT Program -> Department seed mappings exist; later contract work must remove direct College storage once downstream consumers migrate. |
| `organization_programs` | Partial | The organization/program pivot exists; OSA organization create/update rejects Department-backed Society/Academic Unit coverage outside the org Department; Student Academic Path now filters and validates selected Organizations/Societies through covered Programs. Direct pivot writes and remaining proposal/scanner/analytics consumers still need migration to the corrected Department contract. |
| `organization_memberships` | Partial | UUID multi-membership, profile demographic attribution, nullable Department, and active-School-Year primary profile membership persistence exist. Revised PRD still requires replacing remaining `academic_year` contracts, removing officer membership as the authority model, and extending corrected hierarchy validation into downstream scanner/analytics consumers. |
| `organization_officer_rosters` / `organization_officers` | Partial | First-class `organization_officer_rosters` and `organization_officers` UUID tables, School Year binding, status lifecycle (draft, submitted, changes_requested, verified, locked, superseded), draft save vs submission gates, registered member eligibility for student officers, eligible institutional account requirement for Adviser, supporting evidence file / attestation upload, preparer authorization for outgoing officers / adviser fallback, Adviser Verification decision point (Verify or Request Changes with mandatory remarks), and governance activation exist. Authority Projection sync (#26) remains for complete integration. |
| `officer_change_requests` | Done | UUID table, model/factory, lifecycle (pending, approved, rejected, cancelled), request types (resignation, replacement, revocation, correction), registered member checks, adviser approval/rejection decision endpoints, prior-officer status transitions (`replaced`, `revoked`, `expired`), full history preservation, and audit logging implemented. |
| `employee_profiles` / `employee_roster_entries` | Done | UUID HR/faculty/staff profile and roster tables added for employee verification and HR scanner hydration |
| `admin_applications` | Off-PRD | Legacy table remains physically present although routes/controllers/UI are removed. Revised PRD cleanly removes this pre-deployment authority model rather than preserving it for production history. |
| `admin_assignments` Authority Projection | Partial | Existing table drives admin authority and tenant scope by role/scope/academic-year string. Revised PRD makes it a projection sourced from verified Officer Rosters, audited OSA overrides, bootstrap, or HR authority, with `school_year_id` and Department scope. |
| `osa_authority_overrides` | Not Started | No dedicated reasoned, audited OSA-only grant/replace/lock/revoke/correct exception model exists. |
| `events` | Partial | Custom PPA columns, lifecycle phase, and event domain exist. Revised PRD adds `school_year_id` and historical hierarchy/authority context. |
| `event_days` | Done | UUID schema defined and migrated |
| `event_day_sessions` | Done | UUID configurable same-day session table added under `event_days`, with ordered non-overlapping session rows and required-for-attendance metadata |
| `event_participation_scopes` | Partial | UUID organizer/participant scope, PPA status, capture mode, delegated owner, analytics roll-up, and participant late-cutoff behavior exist. Revised PRD adds School Year and Department scope and requires corrected hierarchy/membership/PPA resolver behavior. |
| `event_scanner_assignments` | Done | UUID scanner assignment table separates admin intent from runtime sessions, with event/scope/user/assigned-by, audience type, status, revocation, expiry, max devices, notes, and last activation fields |
| `scanner_sessions` | Done | UUID scanner-session table added with event/scope/user/token, assignment link, label, device binding, status, expiry, revocation, and last-seen fields |
| `raw_scans` | Done | Nullable student, QR, dedup_key, scanner-session/user audit, participation scope, captured-by organization/admin scope, event-day/session ids, server received, and processed markers migrated with UUID |
| `attendance_records` | Done | EILO official attendance status, compatibility status mirror, participation scope, event-day/session identity, per-session completion status, validity, and override columns migrated with UUID |
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
| 2026-09-17 | Read-only venue usage and availability view for organizing admins, based only on EAES-recorded on-campus event schedules. Approved/completed events show as used with one-hour setup/cleanup buffers; submitted/under-review events show as tentative; drafts do not block; proposal creation shows inline warnings and supports manual campus venue names without creating catalog records. | Approved narrow deviation from PRD Epic 2, which excluded reservation calendars/schedule-conflict detection and assigned venue master-data maintenance to OSA. The implemented scope does not create holds, reservations, managing-office approvals, or reservation guarantees, and keeps external reservation confirmation required. | User |
| 2026-05-30 | Database Queue Fallback | Redis database extension missing on local environment; fallback used for jobs | Auto-approved for Dev |

---

## Sprint Notes

- **2026-09-26 (Adviser Verification and Officer Change Requests delivery - Issue #25):**
  - Added Adviser Verification decision flow (`verify` and `request-changes`) with mandatory remarks on submitted officer rosters, transitioning rosters into official governance (`verified`) or back to editable draft (`changes_requested`).
  - Added authority projection to `AdminAssignment` upon Adviser verification, granting verified Presidents official primary admin authority and assigning tenant admin roles (`Society Admin`, `LSG Admin`, etc.) while expiring previous active primary assignments.
  - Created `officer_change_requests` UUID table, model, relationships, and factories supporting mid-year officer transitions (`resignation`, `replacement`, `revocation`, `correction`) with evidence attachment support.
  - Implemented adviser review, approval, and rejection of change requests, preserving full officer history without silent deletion and marking prior officer records as `replaced`, `revoked`, or `expired` with `effective_end` and `replacement_officer_id` linking.
  - Enforced registered member eligibility checks for student replacement officers and institutional account eligibility checks for replacement advisers.
  - Added audit logging (`AuditLog`) for roster verification, changes requested, change request creation, approval, and rejection.
  - Expanded feature test coverage in `tests/Feature/OfficerRosterTest.php` with 10 new tests (24 total tests, 139 assertions passing).

- **2026-09-26 (Officer Roster preparation and submission delivery - Issue #24):**
  - Added first-class `organization_officer_rosters` and `organization_officers` UUID schema, models, relationships, and factories with School Year binding and status lifecycle (`draft`, `submitted`, `changes_requested`, `verified`, `locked`, `superseded`).
  - Implemented `OfficerRosterService` with preparer authorization (outgoing verified President or Secretary during active year or transition period, with eligible Adviser initiation fallback only when outgoing officers are unavailable).
  - Added registered member validation for student officers (President, Secretary) and institutional account eligibility checks for Adviser.
  - Enforced draft saving vs submission gates (requiring active President, Secretary, Adviser, and supporting evidence file or attestation upload prior to submission).
  - Added dashboard API endpoints for show, eligible-members, eligible-advisers, draft save, and submission.
  - Added comprehensive feature test coverage in `tests/Feature/OfficerRosterTest.php` (14 tests, 59 assertions passing).

- **2026-09-24 (organizer Canonical PPA Packet foundation):**
  - Added `canonical_ppa_packets` with UUID identity, one packet per event, activity role, proponent/contact, schedule, venue, budget, objectives, methodology, activity, output, beneficiary, and summary fields for PRD Feature 2.1.
  - Routed Livewire, multipart web fallback, and API proposal create/update payloads through shared packet synchronization in `EventProposalDraftService`, while keeping legacy `events` fields as compatibility projections during migration.
  - Updated the Event Proposal modal into a guided canonical packet flow with Organizer/Co-Organizer/Participant role selection, proponent defaults, and optional draft-safe packet fields; added focused PHPUnit coverage for single-entry capture, role selection, draft persistence, and compatibility synchronization.

- **2026-09-24 (Student Academic Path delivery):**
  - Added Student Academic Path profile persistence for College -> Department -> Program -> eligible Organization/Society -> Year Level, including `users.department_id` and Department/active-School-Year fields on profile-sourced `organization_memberships`.
  - Updated student profile onboarding/edit selectors to cascade from Department and Program-backed organization eligibility instead of the older compressed College -> Program -> Organization path.
  - Added backend validation and focused feature coverage for valid path persistence, invalid College/Department/Program/Organization combinations, and active School Year assignment.

- **2026-09-24 (Department-backed programs and academic organizations):**
  - Added nullable `department_id` compatibility columns to Programs and Organizations, plus Program/Organization/Department relationships and College resolution helpers so existing direct College callers keep working.
  - Updated OSA organization create/update to infer Department from selected Programs when possible and reject Department-backed Society/Academic Unit coverage that crosses Department boundaries.
  - Seeded explicit CEIT Program -> Department mappings and demo/dev PSITS Department context while leaving unknown Department mappings nullable instead of inventing data.

- **2026-09-24 (School Year lifecycle foundation):**
  - Added OSA-controlled School Year master data with UUID schema, unique labels, institutional date fields, draft/active/closed state, activation metadata, transition-window metadata, and a database-level single-active constraint.
  - Added dashboard API create/list/activate/open-transition/close-transition routes backed by a transactional lifecycle service so status changes are explicit OSA actions and never inferred from dates.
  - Added audit rows and focused feature coverage for OSA-only access, single-active enforcement, prior active-year closure, transition controls, and lifecycle attribution.

- **2026-09-24 (Department master data foundation):**
  - Added first-class Department master data under Colleges with UUID schema, scoped code uniqueness, model/factory support, OSA-only dashboard API create/list/update routes, audit rows for create/update actions, and focused feature coverage.
  - Seeded explicit CEIT Department records without migrating Programs away from their existing direct College relationship; Program -> College compatibility remains for downstream tickets.

- **2026-09-24 (PRD revision from completed Wayfinder map #12):**
  - Corrected the institutional hierarchy to University -> College -> Department -> Program and made Department-backed Academic Organizations cover only Programs from the same Department.
  - Replaced academic-year strings and automatic calendar assumptions with OSA-controlled first-class School Years, one active year, and an explicit transition period.
  - Made adviser-verified Officer Rosters the source of truth for normal President/Secretary organization authority; retained `admin_assignments` only as an Authority Projection and reserved reasoned advanced overrides for OSA.
  - Revised proposal assignment resolution, scanner/attendance scope, analytics/Gawad filters, and schema expectations around School Year, Department, Officer Roster, and Participation Scope.
  - Reclassified affected audit entries from Done to Partial or Not Started where the current implementation satisfies only the superseded model.
- **2026-09-18 (multi-session attendance implementation):**
  - Extended PRD Feature 4.3 from day-only attendance to configurable non-overlapping sessions per event day, preserving a one-session default for simple events and legacy schedules.
  - Added `event_day_sessions`, session ids on `raw_scans` and `attendance_records`, and per-session `completion_status`; updated scanner sync and `ResolveAttendanceJob` so EILO resolves earliest-in/latest-out per session while the evaluation form remains once per event.
  - Added proposal and Attendance Rules interfaces for adding/removing named same-day sessions, with validation that sessions stay inside the event day and do not overlap.
  - Updated Flutter scanner schedule/offline scan models so cached scans can include day/session ids when the activated schedule is available, while server validation remains authoritative.
  - Verified with focused PHPUnit coverage for default sessions, overlap rejection, between-session scan rejection, raw-scan session persistence, and session-level canonical attendance resolution.
- **2026-09-17 (venue availability planned-deviation implementation):**
  - Added a read-only Venue Availability page for organizing admins at `/dashboard/venue-availability`, showing Used, Tentative, and Available venue states from EAES-recorded on-campus events only.
  - Added shared availability logic with normalized casing/whitespace venue-name matching, one-hour setup/cleanup buffers for approved/completed events, tentative submitted/under-review usage, draft exclusion, catalog/manual venue grouping, managing-office guidance, and the disclaimer that reservation must be confirmed externally.
  - Added inline proposal-form availability warnings plus manual campus venue entry that does not create catalog records; adjusted the submission guard so manual campus venue names satisfy the venue requirement when no catalog venue is selected.
  - Verified with focused PHPUnit coverage for buffers, tentative/manual normalization, draft exclusion, page permissions, inline proposal warnings, manual on-campus submission, and existing venue-confirmation regressions.
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
- **2026-09-13 (venue confirmation and permit amendments):**
  - Revised PRD Epic 2 around an external-reservation confirmation gate: OSA maintains venue master data and reviews evidence but does not determine availability; reservation calendars, conflict detection, payment, custodian/security accounts, digital signatures, and additional office routing remain out of scope.
  - Added the UUID venue catalog, private confirmation evidence, shared submission/approval guard, immutable permit versions, scoped evidence/history downloads, structured API responses, and OSA-only `manage-venues` permission with audit and database notifications.
  - Added the pre-event venue-amendment cycle with reason capture, permit supersession, scanner lock/session revocation, old-file preservation, replacement PPA and confirmation requirements, and sequential approval versions across Livewire, web fallback, and API workflows.
  - Added valid confirmed-venue factories/demo data and PHPUnit coverage for permissions, drafts, validation, tenant boundaries, rollback cleanup, initial versioning, amendment security, HR exemption, and API parity.
