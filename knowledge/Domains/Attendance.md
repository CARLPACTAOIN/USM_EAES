---
type: domain
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Attendance

#domain

## Purpose

Turns raw scanner captures into canonical attendance records using EILO, duplicate protection, pending-link handling, and evaluation-gated validity.

## Related Domains

- [[Event Management]]
- [[Scanner Mobile App]]
- [[Evaluation]]
- [[Analytics and Exports]]
- [[Organizations and Membership]]
- [[Queue and Concurrency]]

## Key Concepts

- [[Raw Scan]]
- [[Attendance Record]]
- [[Pending Student Link]]
- [[Scanner Session]]
- [[Participation Scope]]

## Main Workflows

- [[Attendance Scanning]]
- [[Offline Attendance Sync]]
- [[EILO Resolution]]
- [[Evaluation Completion]]
- [[Attendance Rules]]

## Cross-Cutting Concerns

- [[Offline Sync]]
- [[Queue and Concurrency]]
- [[Evaluation Gate]]
- [[Audit Logging]]
- [[Authorization and Tenant Scope]]

## Important Implementation

### Models
- `Web/app/Models/RawScan.php` - Graphify nodes: 9
- `Web/app/Models/AttendanceRecord.php` - Graphify nodes: 7
- `Web/app/Models/PendingStudentLink.php` - Graphify nodes: 9

### Services and Jobs
- `Web/app/Services/AttendanceSyncPersistenceService.php` - Graphify nodes: 7
- `Web/app/Services/QueueOutboxService.php` - Graphify nodes: 5
- `Web/app/Jobs/ResolveAttendanceJob.php` - Graphify nodes: 7

### Controllers and Livewire
- `Web/app/Http/Controllers/Api/AttendanceSyncController.php` - Graphify nodes: 7
- `Web/app/Http/Controllers/Api/PendingStudentLinkController.php` - Graphify nodes: 9
- `Web/app/Livewire/Dashboard/PendingQrLinks.php` - Graphify nodes: 14
- `Web/app/Livewire/Dashboard/EventAttendanceRules.php` - Graphify nodes: 20

### Tests
- `Web/tests/Feature/AttendanceSyncConcurrencyTest.php` - Graphify nodes: 20
- `Web/tests/Postgres/AttendanceSyncPostgresConcurrencyTest.php` - Graphify nodes: 6
- `Web/tests/Feature/UsmEaesTest.php` - Graphify nodes: 43

## Important Rules

- PRD §3.3 requires idempotent bulk sync and queued attendance resolution for high-concurrency events.
- Raw scans are persisted first; canonical attendance records are resolved by background processing.
- EILO keeps earliest time-in and latest time-out per participant/day/scope.
- Attendance validity remains false until required evaluation completion unless force validation applies.

## Change Impact

Changes in this domain may affect:

- [[Scanner Mobile App]]
- [[Evaluation]]
- [[Analytics and Exports]]
- [[Event Management]]
- [[Queue and Concurrency]]

<!-- END GENERATED:eaes-knowledge -->
