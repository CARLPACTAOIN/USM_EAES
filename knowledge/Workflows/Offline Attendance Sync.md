---
type: workflow
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Offline Attendance Sync

#workflow

## Summary

Pending local scans are uploaded in retry-safe batches, inserted idempotently as raw scans, then handed to queue-backed resolution.

## Domains

- [[Scanner Mobile App]]
- [[Attendance]]
- [[Queue and Concurrency]]

## Concepts

- [[Raw Scan]]
- [[Scanner Session]]
- [[Pending Student Link]]
- [[Attendance Record]]

## Flow

1. Connectivity detected
2. Batch payload prepared
3. Backend validates scanner session and scope
4. AttendanceSyncPersistenceService inserts winners
5. QueueOutboxService dispatches resolution
6. Failed rows remain retryable

## Implementation

- `Application/lib/features/scanner/data/scanner_api_client.dart` - Graphify nodes: 16
- `Web/app/Http/Controllers/Api/AttendanceSyncController.php` - Graphify nodes: 7
- `Web/app/Services/AttendanceSyncPersistenceService.php` - Graphify nodes: 7
- `Web/app/Services/QueueOutboxService.php` - Graphify nodes: 5

## Important Rules

- Sync of valid captured scans can continue through approved grace paths.
- Idempotency keys and database indexes are the duplicate defense.

<!-- END GENERATED:eaes-knowledge -->
