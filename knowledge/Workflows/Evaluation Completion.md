---
type: workflow
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Evaluation Completion

#workflow

## Summary

Students answer the event-specific form during the evaluation window, and successful submission validates attendance where required.

## Domains

- [[Evaluation]]
- [[Attendance]]
- [[Analytics and Exports]]

## Concepts

- [[Evaluation Submission]]
- [[Evaluation Form]]
- [[Attendance Record]]
- [[Event]]

## Flow

1. Evaluation window opens
2. Student sees pending event
3. Question map validated
4. Evaluation stored
5. AttendanceRecord.valid toggled
6. Analytics/export inputs update

## Implementation

- `Web/app/Http/Controllers/Web/PortalController.php` - Graphify nodes: 9
- `Web/app/Http/Controllers/Api/EvaluationController.php` - Graphify nodes: 3
- `Web/app/Support/EvaluationWindow.php` - Graphify nodes: 6

## Important Rules

- Window closes 24 hours after event/final day end.
- Submission must match event-specific evaluation questions.

<!-- END GENERATED:eaes-knowledge -->
