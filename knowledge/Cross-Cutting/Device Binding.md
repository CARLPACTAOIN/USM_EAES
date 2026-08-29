---
type: cross-cutting
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Device Binding

#cross-cutting

## Purpose

Maintains scanner session continuity and blocks unsafe cross-device scanner token reuse.

## Related Domains

- [[Scanner Mobile App]]
- [[Authentication and Access Control]]
- [[Offline Sync]]

## Key Concepts

- [[Scanner Session]]
- [[Scanner Assignment]]
- [[Raw Scan]]

## Important Implementation

### Mobile
- `Application/lib/features/auth/data/device_identity_provider.dart` - Graphify nodes: 9
- `Application/test/device_identity_provider_test.dart` - Graphify nodes: 2

### Backend
- `Web/app/Services/ScannerSessionService.php` - Graphify nodes: 17
- `Web/app/Http/Controllers/Api/AttendanceSyncController.php` - Graphify nodes: 7

## Important Rules

- Device identity is sent during activation, hydration, validation, and sync.
- Same-device resume is allowed; unsafe transfer should be blocked.

## Change Impact

- [[Scanner Mobile App]]
- [[Attendance]]
- [[Authorization and Tenant Scope]]

<!-- END GENERATED:eaes-knowledge -->
