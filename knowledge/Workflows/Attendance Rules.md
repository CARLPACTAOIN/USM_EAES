---
type: workflow
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Attendance Rules

#workflow

## Summary

Organizer-owned late cutoff policy is configured before event start and interpreted during attendance resolution and analytics.

## Domains

- [[Event Management]]
- [[Attendance]]
- [[Analytics and Exports]]

## Concepts

- [[Event]]
- [[Participation Scope]]
- [[Attendance Record]]

## Flow

1. Organizer opens Attendance Rules
2. Event cutoff set or left blank
3. Participant fallback cutoff may be set
4. Rules lock after event start
5. Resolution applies event-first fallback policy

## Implementation

- `Web/app/Livewire/Dashboard/EventAttendanceRules.php` - Graphify nodes: 20
- `Web/app/Http/Controllers/Web/DashboardController.php` - Graphify nodes: 48
- `Web/app/Jobs/ResolveAttendanceJob.php` - Graphify nodes: 7

## Important Rules

- OSA does not approve organizer-owned late cutoff.
- Event cutoff applies first; scope cutoff applies only when event cutoff is unset.

<!-- END GENERATED:eaes-knowledge -->
