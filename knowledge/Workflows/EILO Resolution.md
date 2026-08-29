---
type: workflow
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# EILO Resolution

#workflow

## Summary

Background resolution groups raw scans and upserts canonical attendance using earliest-in/latest-out semantics.

## Domains

- [[Attendance]]
- [[Analytics and Exports]]

## Concepts

- [[Raw Scan]]
- [[Attendance Record]]
- [[Event Day]]
- [[Participation Scope]]

## Flow

1. ResolveAttendanceJob starts
2. Raw scans grouped by event/day/student/scope
3. Earliest Time-In selected
4. Latest Time-Out selected
5. Late/left-early status calculated
6. Attendance record upserted

## Implementation

- `Web/app/Jobs/ResolveAttendanceJob.php` - Graphify nodes: 7
- `Web/app/Models/AttendanceRecord.php` - Graphify nodes: 7
- `Web/tests/Feature/AttendanceSyncConcurrencyTest.php` - Graphify nodes: 20

## Important Rules

- Participant-scope row locking or fallback event locking prevents concurrent write collisions.
- Evaluation gate fields must be preserved by resolution.

<!-- END GENERATED:eaes-knowledge -->
