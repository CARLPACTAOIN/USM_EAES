---
type: cross-cutting
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Queue and Concurrency

#cross-cutting

## Purpose

Protects sync, resolution, and AI jobs from duplicate processing, contention, and local Redis/database fallback differences.

## Related Domains

- [[Attendance]]
- [[AI Insights]]
- [[Offline Sync]]
- [[Analytics and Exports]]

## Key Concepts

- [[Raw Scan]]
- [[Attendance Record]]
- [[Evaluation Submission]]

## Important Implementation

### Jobs and Commands
- `Web/app/Jobs/ResolveAttendanceJob.php` - Graphify nodes: 7
- `Web/app/Jobs/AnalyzeEventSentimentsJob.php` - Graphify nodes: 6
- `Web/app/Console/Commands/DispatchQueueOutbox.php` - Graphify nodes: 3
- `Web/app/Console/Commands/CheckQueueHealth.php` - Graphify nodes: 3

### Services
- `Web/app/Services/QueueOutboxService.php` - Graphify nodes: 5
- `Web/app/Services/AttendanceSyncPersistenceService.php` - Graphify nodes: 7

### Tests
- `Web/tests/Feature/QueueResilienceTest.php` - Graphify nodes: 8
- `Web/tests/Feature/AttendanceSyncConcurrencyTest.php` - Graphify nodes: 20
- `Web/tests/Postgres/AttendanceSyncPostgresConcurrencyTest.php` - Graphify nodes: 6

## Important Rules

- Redis is preferred for production queue/cache coordination.
- Database queue fallback is local-dev posture, not the production durability boundary.
- Database constraints remain final duplicate authority.

## Change Impact

- [[Attendance]]
- [[AI Insights]]
- [[Analytics and Exports]]

<!-- END GENERATED:eaes-knowledge -->
