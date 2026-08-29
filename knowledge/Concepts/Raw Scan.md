---
type: concept
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Raw Scan

#concept

## Meaning

A persisted scan candidate from the scanner, including event, scope, day, scan type, device/session audit fields, and deduplication identity.

## Belongs To

- [[Attendance]]

## Relationships

- captured by [[Scanner Session]]
- belongs to [[Event]] and [[Participation Scope]]
- resolves into [[Attendance Record]]
- may create [[Pending Student Link]]

## Implementation

- `Web/app/Models/RawScan.php` - Graphify nodes: 9
- `Web/app/Services/AttendanceSyncPersistenceService.php` - Graphify nodes: 7
- `Application/lib/features/scanner/domain/offline_scan.dart` - Graphify nodes: 22

## Important Constraints

- Dedup keys and database constraints protect idempotency.
- Unresolved scans are preserved rather than discarded.

<!-- END GENERATED:eaes-knowledge -->
