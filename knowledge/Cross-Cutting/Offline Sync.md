---
type: cross-cutting
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Offline Sync

#cross-cutting

## Purpose

Preserves scanner work across connectivity loss, app restart, backend drift, retries, and grace-period sync.

## Related Domains

- [[Scanner Mobile App]]
- [[Attendance]]
- [[Queue and Concurrency]]
- [[Device Identity]]

## Key Concepts

- [[Raw Scan]]
- [[Scanner Session]]
- [[Pending Student Link]]
- [[Attendance Record]]

## Important Implementation

### Mobile
- `Application/lib/features/scanner/data/scanner_local_database.dart` - Graphify nodes: 27
- `Application/lib/features/scanner/presentation/scanner_controller.dart` - Graphify nodes: 95
- `Application/lib/features/scanner/data/scanner_api_client.dart` - Graphify nodes: 16

### Backend
- `Web/app/Http/Controllers/Api/AttendanceSyncController.php` - Graphify nodes: 7
- `Web/app/Services/AttendanceSyncPersistenceService.php` - Graphify nodes: 7
- `Web/app/Services/QueueOutboxService.php` - Graphify nodes: 5

### Tests
- `Application/test/scanner_controller_test.dart` - Graphify nodes: 56
- `Web/tests/Feature/AttendanceSyncConcurrencyTest.php` - Graphify nodes: 20

## Important Rules

- Do not mark local scans synchronized before backend confirmation.
- Preserve failed/pending rows for retry.
- Scope every scan by event, participation scope, scanner session, device, and scan mode.

## Change Impact

- [[Attendance]]
- [[Scanner Mobile App]]
- [[Queue and Concurrency]]

<!-- END GENERATED:eaes-knowledge -->
