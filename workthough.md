# EAES System Workflow Walkthrough

This walkthrough explains how the USM Event Attendance & Evaluation System (EAES) moves from account setup to event proposal, approval, scanning, evaluation, analytics, and export.

It is based on:

- `PRD_V1.md`, which defines the intended product behavior.
- `PRD_PROGRESS_AUDIT.md`, last reviewed on 2026-09-29, which shows the current implementation status.
- The generated workflow notes in `knowledge/Workflows/`.

## 1. What the System Does

EAES is a multi-platform event system for USM. The web app handles institutional login, organization governance, event proposals, approval workflows, attendance records, evaluations, analytics, and exports. The Flutter mobile app acts as the offline-capable scanner companion for QR-based attendance capture.

The main workflow is:

1. Users sign in with institutional Google accounts.
2. Students complete profile and QR registration.
3. OSA and authorized officers manage organization authority.
4. An organizer creates and submits an event proposal.
5. The proposal follows a configured approval workflow.
6. Approved events unlock scanner assignment and event runtime actions.
7. Assigned scanners activate a device-bound scanner session in the Flutter app.
8. Scanners hydrate the scoped roster and capture Time-In/Time-Out records.
9. Raw scans sync to the Laravel backend, then resolve into official attendance records.
10. Students complete the event evaluation.
11. Evaluation completion validates attendance where required.
12. Admins review analytics, sentiment insights, and export PDF/XLSX reports.

## 2. Main Actors

| Actor | Main Responsibility |
| --- | --- |
| OSA / Super Admin | Maintains master data, school years, organization governance, templates, approval authority, analytics visibility, and final permit control. |
| USG / LSG / Society / Academic Unit / Office Admin | Creates proposals, manages owned events, assigns scanners, sets attendance rules, reviews own analytics, and manages participant scopes where allowed. |
| Institutional Approver | Acts on assigned workflow tasks such as organization head, adviser, department chairperson, college dean, chief security, venue custodian, or OSA approval. |
| Scanner | Uses the Flutter app to activate assigned event scopes, hydrate rosters, scan attendees, and sync captured records. |
| Student | Registers a profile and QR identity, attends events, views history, and submits event evaluations. |
| HR Admin | Creates HR-domain faculty/staff attendance events and manages HR-specific rosters/scanners outside the normal OSA PPA route. |

## 3. Account and Authority Setup

The starting point is institutional Google login.

1. The user signs in with a `@usm.edu.ph` account.
2. The backend creates or resumes the account.
3. Student accounts complete onboarding with College, Department, Program, Organization/Society, Year Level, and QR registration.
4. Faculty/staff accounts enter a pending or HR-verified path depending on roster records.
5. Admin authority is resolved from active assignments, officer rosters, and scoped projections.

Important rule: login is only identity. It does not automatically grant admin, scanner, or approver authority.

Deeper references:

- [Google Authentication](knowledge/Workflows/Google%20Authentication.md)
- [Admin Assignment Governance](knowledge/Workflows/Admin%20Assignment%20Governance.md)
- [Authorization and Tenant Scope](knowledge/Cross-Cutting/Authorization%20and%20Tenant%20Scope.md)

## 4. Organization and School Year Governance

OSA controls official institutional master data:

- Colleges
- Departments
- Programs
- Organizations
- School Years
- Officer rosters
- Institutional approver accounts
- Authority overrides

For recognized student organizations, verified President and Secretary roster entries are the normal source of organization admin authority. These generate scoped admin projections for the current School Year. Broad roles are not enough by themselves; authority must match the exact school year, organization, department, college, or workflow task scope.

Current audit note: most authority and tenant enforcement is implemented, but source normalization for bootstrap/HR authority remains a partial item in the progress audit.

## 5. Event Proposal Workflow

The proposal workflow is the core web workflow.

1. An authorized organizer opens the Event Proposals workspace.
2. The organizer creates a Canonical PPA Packet.
3. The packet captures event details, activity role, schedule, venue, budget, beneficiaries, methodology, objectives, required evidence, and the event evaluation form.
4. Drafts can be saved permissively.
5. On first submission, the system validates required fields and evidence.
6. The system binds the proposal to the applicable published process template.
7. A Workflow Run is created with ordered Workflow Tasks.
8. Approvers receive task assignments.
9. Approvers approve, reject, request revision, or mark optional steps as Not Required.
10. Every decision writes Approval Trail evidence.
11. If revision is requested, the organizer corrects the packet and resubmits through the same Workflow Run.
12. Once all permit-gating tasks pass and OSA gives final approval, the Digital Permit is generated.

Important rules:

- Proposal status is separate from event lifecycle phase.
- Legacy signature or hardcopy fields are read-only compatibility projections.
- Workflow Task decisions are the source of approval truth.
- Digital Permit generation depends on completed permit-gating tasks.

Deeper references:

- [PPA Approval](knowledge/Workflows/PPA%20Approval.md)
- [Event Lifecycle](knowledge/Workflows/Event%20Lifecycle.md)
- [PPA](knowledge/Concepts/PPA.md)

## 6. Participant Scope Workflow

Some events involve participating organizations under a parent organizer event.

1. The parent organizer submits the main event proposal.
2. The organizer invites participant scopes.
3. The participating organization acknowledges the invitation or submits its own participant PPA packet.
4. Participant packets inherit the parent event venue, dates, and permit context.
5. Participant packets follow participant-specific workflow approval when required.
6. Approved or Not Required participant scopes unlock scanner assignment for that scope.
7. Only approved eligible scopes are counted in official roll-up analytics.

Important rules:

- The organizer cannot duplicate itself as a participant.
- Participant organizations do not create a separate venue confirmation route unless a future approved template explicitly requires it.
- Participant scanners cannot hydrate, activate, sync, or count toward official analytics before participant PPA approval or Not Required status.

Deeper reference:

- [Participant Scope Approval](knowledge/Workflows/Participant%20Scope%20Approval.md)

## 7. Scanner Assignment and Activation

Scanner access has two layers:

1. Dashboard assignment: an authorized admin assigns a scanner account to a specific event participation scope.
2. Runtime session: the Flutter app activates the assignment on a device and receives a scanner session token.

The workflow is:

1. The event or participant scope becomes eligible after approval gates pass.
2. An authorized admin assigns one or more scanner accounts.
3. The scanner signs in to the Flutter app with Google.
4. The app lists only that account's active assignments.
5. The scanner selects an event assignment.
6. The app sends device identity to activate the assignment.
7. The backend creates or resumes a device-bound `scanner_sessions` token.
8. The scanner hydrates the scoped roster before scanning.

Important rules:

- Normal student login cannot call scanner hydrate or sync endpoints.
- Only activated scanner session tokens can validate, hydrate, and sync.
- Revoked, expired, wrong-device, wrong-event, and wrong-scope sessions must fail backend validation.

Deeper reference:

- [Scanner Assignment and Activation](knowledge/Workflows/Scanner%20Assignment%20and%20Activation.md)

## 8. Roster Hydration

Hydration prepares the mobile app for offline scanning.

1. The scanner activates an assignment while online.
2. The app requests the scoped roster for the active `event_participation_scope_id`.
3. The backend returns only the allowed identification metadata.
4. The Flutter app stores the roster in local SQLite.
5. The scanner can inspect the hydrated participant list without exposing raw QR values.

The roster is scope-aware:

- Society scanners hydrate only their approved society scope.
- LSG/USG scanners hydrate only allowed college or university scopes.
- Non-academic organization scanners hydrate active memberships for that organization.
- HR scanners hydrate verified faculty/staff roster entries.

## 9. Attendance Scanning

During the event:

1. The scanner opens the active scanner session.
2. The scanner chooses Time-In or Time-Out mode.
3. The app validates the scan window locally for user feedback.
4. The QR code or manual student ID is captured.
5. Known roster identities show confirmation details.
6. Unknown local identities are saved as unresolved records instead of being blocked.
7. The local SQLite database stores each scan with event, scope, session, device, scan type, timestamp, and sync status.

Important rules:

- Client-side checks improve user feedback.
- Backend checks remain the real security boundary.
- Unknown QR values are preserved for server-side lookup and pending-link review.

Deeper reference:

- [Attendance Scanning](knowledge/Workflows/Attendance%20Scanning.md)

## 10. Offline Sync and Attendance Resolution

When connectivity is available:

1. The Flutter app prepares pending scans in retry-safe batches.
2. The backend validates the scanner session, event, scope, device, scan mode, and scan window.
3. `AttendanceSyncPersistenceService` inserts accepted raw scans idempotently.
4. Duplicate records are rejected by idempotency keys and database indexes.
5. Unresolved QR values can create pending student link records.
6. A queue-backed resolution job processes accepted raw scans.
7. `ResolveAttendanceJob` groups scans by event, participation scope, student, event day, and session.
8. The job picks earliest Time-In and latest Time-Out.
9. It calculates late, left-early, and session completion status.
10. It upserts the official `attendance_records` row.

Important rules:

- Failed rows remain retryable.
- Valid captured offline scans can continue syncing through approved grace paths.
- The queue and database indexes protect high-concurrency sync from multiple scanner devices.
- Local development may use the database queue fallback; production posture expects Redis-backed queues.

Deeper references:

- [Offline Attendance Sync](knowledge/Workflows/Offline%20Attendance%20Sync.md)
- [EILO Resolution](knowledge/Workflows/EILO%20Resolution.md)
- [Queue and Concurrency](knowledge/Cross-Cutting/Queue%20and%20Concurrency.md)

## 11. Pending QR Resolution

If a QR value was not in the local roster:

1. The scan is still stored locally.
2. On sync, the backend checks the master PostgreSQL records.
3. If a matching student is found, the scan can be linked to the canonical user.
4. If no match is found, the system creates or updates a pending student link.
5. Authorized admins review pending links.
6. The admin either maps the QR value to a student or flags it as an invalid/unaccredited participant.
7. The system audits the action and can reprocess attendance.

Important rule: pending QR resolution must respect participant scope and tenant boundaries.

## 12. Event Lifecycle

Approved events move through runtime lifecycle phases:

1. `scheduled`
2. `active`
3. `ended`
4. `evaluation_open`
5. `evaluation_closed`
6. `processing`
7. `completed`

Lifecycle is based on event days, sessions, scan windows, evaluation windows, unresolved scans, pending links, sentiment processing, and scanner session closure.

Important rules:

- Lifecycle phase is not the same thing as proposal approval status.
- Scanner access can be locked by lifecycle state, permit state, venue amendments, assignment revocation, or session expiration.
- Venue amendments before the event can supersede permits and revoke active scanner sessions until required re-approvals finish.

Deeper reference:

- [Event Lifecycle](knowledge/Workflows/Event%20Lifecycle.md)

## 13. Evaluation Completion and Attendance Validity

After the event:

1. The evaluation window opens.
2. Students see pending event evaluations in the portal or API.
3. The student answers the event-specific locked evaluation form.
4. The backend validates that the answer map matches the event snapshot.
5. The evaluation is stored.
6. The linked attendance record's `valid` flag becomes true where the evaluation gate applies.
7. Evaluation results feed analytics and exports.

Important rules:

- The evaluation window closes 24 hours after the final configured event/session end.
- The event-specific evaluation form snapshot is the question source of truth.
- Attendance can exist but remain not valid until the evaluation gate is satisfied.

Deeper references:

- [Evaluation Completion](knowledge/Workflows/Evaluation%20Completion.md)
- [Evaluation Gate](knowledge/Cross-Cutting/Evaluation%20Gate.md)

## 14. Analytics, AI Insights, and Exports

After attendance and evaluations are processed:

1. Authorized admins open event or organization analytics.
2. Metrics are scoped by role, authority projection, school year, college, department, program, organization, participation scope, and year level.
3. Attendance summaries, evaluation averages, demographics, and participant roll-ups are calculated.
4. Closed evaluation comments can be processed through sentiment analysis.
5. Admins can export timestamped PDF/XLSX attendance and evaluation reports.
6. Officers print exports and attach them to physical accomplishment report workflows outside EAES.

Important rules:

- Historical reports should use locked snapshots rather than silently changing when master data changes later.
- Exports must remain tenant-scoped.
- Evaluation labels come from the event evaluation form snapshot.
- Digital semestral accomplishment report generation is out of scope; only supporting evaluation and attendance exports are in scope.

Deeper references:

- [Organization Analytics](knowledge/Workflows/Organization%20Analytics.md)
- [Analytics Export](knowledge/Workflows/Analytics%20Export.md)
- [AI Sentiment and NLP](knowledge/Workflows/AI%20Sentiment%20and%20NLP.md)

## 15. Happy Path Summary

This is the simple end-to-end happy path:

1. Student logs in, completes profile, and registers QR identity.
2. OSA sets up school year, organizations, officers, approvers, and templates.
3. Society Admin creates an event proposal with schedule, venue, PPA packet, and evaluation form.
4. Organizer submits the proposal.
5. Workflow tasks are assigned to the right institutional approvers.
6. Approvers approve every required gate.
7. OSA final approval creates the Digital Permit.
8. Organizer assigns scanners to the approved attendance scope.
9. Scanner logs in to Flutter, activates the assigned event, and hydrates the roster.
10. Scanner captures Time-In and Time-Out records, online or offline.
11. Scans sync to the backend.
12. EILO resolution creates official attendance records.
13. Student submits the event evaluation within 24 hours after event end.
14. Attendance becomes valid.
15. Admin reviews analytics and exports official PDF/XLSX summaries.

## 16. Common Alternate Paths

| Scenario | What Happens |
| --- | --- |
| Proposal revision requested | The organizer corrects the targeted packet section or attachment, then resubmits through the same Workflow Run. |
| Participant organization invited | The participant submits or acknowledges its own participant scope before scanner access and analytics roll-up become official. |
| Scanner loses internet | Scans are stored locally in SQLite and sync later with idempotency protection. |
| Student QR is not in local roster | The scan is saved unresolved, then server-side lookup or pending QR resolution handles it after sync. |
| Duplicate scanner upload | Idempotency keys and database indexes prevent duplicate official attendance records. |
| Event venue changes before event day | Active permit is superseded, scanner sessions are revoked, venue-related tasks reopen, and a new permit version is required. |
| Student does not evaluate | Attendance may remain present but not valid where the evaluation gate applies. |
| Evaluation window closes | Submissions are no longer accepted after the configured 24-hour post-event window. |

## 17. Current Implementation Notes

According to `PRD_PROGRESS_AUDIT.md`:

- PPA digitization, workflow tasks, approval trails, Digital Permit gating, participant PPAs, scanner assignments, offline sync, EILO processing, evaluation gating, analytics, exports, and AI insights are marked Done.
- Redis queue production posture is mostly prepared, but local runtime may still use the database queue fallback unless Redis is running and `.env` is switched.
- Tenant-scoped middleware and authority projections are mostly implemented, with source normalization for bootstrap/HR authority still listed as Partial.
- Digital accomplishment report generation is intentionally out of scope; exports support the manual physical accomplishment report workflow.

## 18. Where to Read More

Start with:

- [System Overview](knowledge/00%20-%20System%20Overview.md)
- [Architecture Map](knowledge/Maps/Architecture%20Map.md)
- [Domain Index](knowledge/Maps/Domain%20Index.md)

Then follow the workflow files under:

- `knowledge/Workflows/`
- `knowledge/Cross-Cutting/`
- `knowledge/Domains/`
- `knowledge/Concepts/`

