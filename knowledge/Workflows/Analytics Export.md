---
type: workflow
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Analytics Export

#workflow

## Summary

Scoped PDF/XLSX exports package attendance and evaluation summaries for official printed attachment workflows.

## Domains

- [[Analytics and Exports]]
- [[Attendance]]
- [[Evaluation]]
- [[Event Management]]

## Concepts

- [[Event]]
- [[Attendance Record]]
- [[Evaluation Submission]]
- [[Evaluation Form]]

## Flow

1. Authorized admin opens event analytics
2. Export payload built
3. Attendance or evaluation sheet rendered
4. PDF/XLSX generated with metadata
5. File downloaded

## Implementation

- `Web/app/Http/Controllers/Web/EventExportController.php` - Graphify nodes: 8
- `Web/app/Support/EventExportData.php` - Graphify nodes: 13
- `Web/app/Exports/EventAttendanceExport.php` - Graphify nodes: 4
- `Web/app/Exports/EventEvaluationExport.php` - Graphify nodes: 4

## Important Rules

- Exports must be tenant-scoped and timestamped.
- Exported evaluation labels resolve from the event form snapshot.

<!-- END GENERATED:eaes-knowledge -->
