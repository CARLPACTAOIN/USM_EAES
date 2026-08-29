---
type: cross-cutting
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Evaluation Gate

#cross-cutting

## Purpose

Ensures attendance validity follows required evaluation completion and window rules.

## Related Domains

- [[Attendance]]
- [[Evaluation]]
- [[Event Management]]
- [[Analytics and Exports]]

## Key Concepts

- [[Attendance Record]]
- [[Evaluation Submission]]
- [[Evaluation Form]]
- [[Event]]

## Important Implementation

### Core
- `Web/app/Http/Controllers/Api/EvaluationController.php` - Graphify nodes: 3
- `Web/app/Http/Controllers/Web/PortalController.php` - Graphify nodes: 9
- `Web/app/Support/EvaluationWindow.php` - Graphify nodes: 6

### Analytics
- `Web/app/Services/EventAnalyticsService.php` - Graphify nodes: 28
- `Web/app/Support/EventExportData.php` - Graphify nodes: 13

### Tests
- `Web/tests/Feature/WebPortalTest.php` - Graphify nodes: 122
- `Web/tests/Feature/UsmEaesTest.php` - Graphify nodes: 43

## Important Rules

- AttendanceRecord.valid stays false until required evaluation completion.
- The window closes 24 hours after event/final day conclusion.
- Exports and analytics must reflect gate status.

## Change Impact

- [[Attendance]]
- [[Evaluation]]
- [[Analytics and Exports]]

<!-- END GENERATED:eaes-knowledge -->
