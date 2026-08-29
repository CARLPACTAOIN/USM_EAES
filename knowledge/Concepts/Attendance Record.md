---
type: concept
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Attendance Record

#concept

## Meaning

The canonical attendance row for a participant/event/day/scope after EILO resolution and validity gating.

## Belongs To

- [[Attendance]]

## Relationships

- resolved from [[Raw Scan]]
- belongs to [[Event]] and [[Participation Scope]]
- is validated by [[Evaluation Submission]]
- feeds [[Analytics and Exports]]

## Implementation

- `Web/app/Models/AttendanceRecord.php` - Graphify nodes: 7
- `Web/app/Jobs/ResolveAttendanceJob.php` - Graphify nodes: 7
- `Web/app/Services/EventAnalyticsService.php` - Graphify nodes: 28

## Important Constraints

- Official punctuality status uses event cutoff first, then participant-scope fallback.
- The valid flag is controlled by evaluation-gate rules unless force validation applies.

<!-- END GENERATED:eaes-knowledge -->
