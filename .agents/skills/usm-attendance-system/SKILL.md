---
name: usm-attendance-system
description: Project-specific EAES rules for the USM Event Attendance & Evaluation System. Use when implementing, reviewing, or debugging this repository's Laravel Web app, Flutter scanner app, PostgreSQL schema, Redis/database queues, scanner sessions, offline sync, tenant/RBAC boundaries, event lifecycle, attendance processing, analytics, exports, or deployment posture.
---

# USM Attendance System

Use this skill as project-domain guardrails for EAES work. It complements `AGENTS.md`; it does not replace `AGENTS.md`, `PRD_V1.md`, or `PRD_PROGRESS_AUDIT.md`.

## First Pass

Before changing behavior:

- Read the relevant `PRD_V1.md` section first; PRD scope wins unless the user explicitly approves a deviation.
- Read the matching `PRD_PROGRESS_AUDIT.md` entry to distinguish implemented behavior from remaining gaps.
- Trace the exact user path through real code, data, config, and tests before proposing a fix.
- For `Web/`, inspect routes, controller/service/policy code, migrations, factories/seeders, Blade/JS, and focused tests.
- For `Application/`, inspect controller/service/repository code, local SQLite schema, sync API client, state restoration, and focused Flutter tests.
- If the task changes actual PRD progress, update the audit in the same task.

For UI/UX work, also use the repo UI/UX skill required by `AGENTS.md` before choosing layout, color, typography, or interaction patterns.

## Source-Of-Truth Rules

- Treat Laravel/PostgreSQL as authoritative for permanent attendance, event, user, organization, roster, evaluation, export, and audit records.
- Treat Redis as temporary infrastructure for queues, locks, cache, throttling, and coordination. Never make Redis the only durable store for attendance or sync data.
- Keep local dev queue/cache fallbacks separate from PRD production posture; document meaningful deviations in the audit.
- Do not log full QR values, credentials, OAuth tokens, scanner tokens, or API tokens.

## Scanner And Sync Invariants

Scanner changes must be retry-safe and scope-safe:

- Use stable local scan identifiers, batch identifiers, and idempotency or dedup keys.
- Preserve failed or pending records for retry; never mark a scan synchronized before the backend confirms persistence.
- Enforce duplicate protection on the backend with database constraints, scoped lookups, transactions, idempotent writes, or queued resolution.
- Assume multiple scanners can hydrate, scan, sync, retry, and reconnect at the same time.
- Verify roster counts against backend scope truth before trusting displayed totals or local cache counts.
- Scope hydration and sync through approved event participation scope, scanner assignment, scanner session, event, device, and scan mode.
- Keep sync of previously captured valid offline scans available through the approved grace path even when new capture is no longer allowed.
- Do not wipe scanner data just because an event ended; clear local event data only after all scans are confirmed synced or after explicit authorized risk acknowledgement.

Useful scanner searches:

```powershell
rg "AttendanceSyncController|ParticipationScopeService|rosterCount|replaceRoster|scanner_sessions|event_scanner_assignments|dedup_key|ResolveAttendanceJob" Web Application
```

## Authorization And Scope

- Resolve admin authority from active `admin_assignments` and scoped services, not from role names alone.
- Keep student profile membership fields separate from admin tenant scope.
- Prevent horizontal privilege escalation across organizations, colleges, participant scopes, events, scanner assignments, scanner sessions, analytics, exports, and pending QR actions.
- Validate scanner access both at activation/hydration time and again during sync.
- Treat OSA, USG, LSG, Society, Academic Unit, Office Event, HR, organizer, participant, and scanner paths as separate authorization cases when the code path touches scope.

## Event Lifecycle And Attendance

- Enforce scan windows, proposal approval, lifecycle phase, event days, sync grace, token status, scanner assignment status, and scan mode on the backend.
- Client-side checks are user feedback; backend checks are the security boundary.
- Keep proposal status separate from runtime lifecycle phase.
- Preserve EILO attendance resolution semantics unless the PRD changes.
- Keep evaluation-gate behavior intact: attendance validity depends on required evaluation completion where the PRD requires it.

## Verification

For significant changes, add or update focused coverage for the actual risk:

- Valid scan, duplicate scan, replayed batch, partial sync failure, and concurrent sync behavior.
- Expired, revoked, wrong-event, wrong-scope, wrong-device, and unauthorized scanner/session/token cases.
- Before-window, after-window, ended-event, cancelled-event, and grace-period behavior.
- Tenant scope for dashboard actions, APIs, analytics, exports, pending QR resolution, and scanner assignment.
- Roster hydration counts, displayed local counts, backend count truth, and stale cache replacement.

Run commands from the owning project folder: Laravel commands from `Web/`, Flutter commands from `Application/`. If local Flutter/Dart commands report success but fail only on blocked telemetry writes, treat the telemetry failure as environment noise and report it.
