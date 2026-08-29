---
type: concept
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Roster Student

#concept

## Meaning

The compact local scanner representation of a participant identity used for offline lookup and confirmation cards.

## Belongs To

- [[Scanner Mobile App]]
- [[Attendance]]

## Relationships

- hydrated from [[Participation Scope]]
- looked up during [[Attendance Scanning]]
- helps create [[Raw Scan]] records

## Implementation

- `Application/lib/features/scanner/domain/roster_student.dart` - Graphify nodes: 10
- `Application/lib/features/scanner/data/scanner_local_database.dart` - Graphify nodes: 27
- `Web/app/Http/Controllers/Api/AttendanceSyncController.php` - Graphify nodes: 7

## Important Constraints

- Roster data is limited to essential identity metadata for offline scanning.
- Backend scope truth remains authoritative when local roster data is stale.

<!-- END GENERATED:eaes-knowledge -->
