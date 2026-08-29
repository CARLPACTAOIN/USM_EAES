---
type: concept
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Event Day

#concept

## Meaning

A scheduled day within an event used to derive scan windows, daily EILO grouping, and multi-day attendance records.

## Belongs To

- [[Event Management]]

## Relationships

- belongs to [[Event]]
- anchors [[Attendance Record]] records
- bounds [[Attendance Scanning]]

## Implementation

- `Web/app/Models/EventDay.php` - Graphify nodes: 5
- `Web/app/Services/EventLifecycleService.php` - Graphify nodes: 15
- `Application/lib/features/scanner/domain/event_schedule_day.dart` - Graphify nodes: 19

## Important Constraints

- Dates and times are treated as USM local schedule time.
- Multi-day events produce daily attendance interpretation.

<!-- END GENERATED:eaes-knowledge -->
