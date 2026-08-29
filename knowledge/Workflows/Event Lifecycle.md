---
type: workflow
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Event Lifecycle

#workflow

## Summary

Approved events move through runtime phases based on schedule, evaluation window, pending scan state, processing, and completion requirements.

## Domains

- [[Event Management]]
- [[Attendance]]
- [[Evaluation]]
- [[Analytics and Exports]]

## Concepts

- [[Event]]
- [[Event Day]]
- [[Evaluation Submission]]
- [[Pending Student Link]]

## Flow

1. scheduled
2. active
3. ended
4. evaluation_open
5. evaluation_closed
6. processing
7. completed

## Implementation

- `Web/app/Services/EventLifecycleService.php` - Graphify nodes: 15
- `Web/app/Services/DashboardEventWorkspaceService.php` - Graphify nodes: 18
- `Web/routes/console.php` - Graphify nodes: 1

## Important Rules

- Lifecycle phase is separate from proposal status.
- Completion depends on schedule, evaluation, unresolved scans/pending links, sentiment processing, and scanner session closure.

<!-- END GENERATED:eaes-knowledge -->
