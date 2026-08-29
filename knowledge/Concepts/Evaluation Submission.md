---
type: concept
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Evaluation Submission

#concept

## Meaning

A student's event evaluation submission with scored responses, optional comment, sentiment result, and submitted timestamp.

## Belongs To

- [[Evaluation]]

## Relationships

- belongs to [[Event]] and [[User]]
- answers [[Evaluation Form]]
- validates [[Attendance Record]]
- feeds [[Analytics and Exports]] and [[AI Insights]]

## Implementation

- `Web/app/Models/Evaluation.php` - Graphify nodes: 6
- `Web/app/Http/Controllers/Api/EvaluationController.php` - Graphify nodes: 3
- `Web/app/Http/Controllers/Web/PortalController.php` - Graphify nodes: 9

## Important Constraints

- Submission is event-specific and must match the locked question map.
- The submission window closes 24 hours after the event/final day ends.

<!-- END GENERATED:eaes-knowledge -->
