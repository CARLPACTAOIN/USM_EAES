---
type: domain
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Scanner Mobile App

#domain

## Purpose

Provides the Flutter companion app for account-authenticated scanner assignment activation, roster hydration, offline capture, and retry-safe sync.

## Related Domains

- [[Attendance]]
- [[Event Management]]
- [[Authentication and Access Control]]
- [[Offline Sync]]
- [[Device Identity]]

## Key Concepts

- [[Scanner Assignment]]
- [[Scanner Session]]
- [[Raw Scan]]
- [[Roster Student]]
- [[Device Identity]]

## Main Workflows

- [[Scanner Assignment and Activation]]
- [[Attendance Scanning]]
- [[Offline Attendance Sync]]

## Cross-Cutting Concerns

- [[Offline Sync]]
- [[Device Binding]]
- [[Authorization and Tenant Scope]]
- [[Queue and Concurrency]]

## Important Implementation

### Flutter State and API
- `Application/lib/features/scanner/presentation/scanner_controller.dart` - Graphify nodes: 95
- `Application/lib/features/scanner/data/scanner_api_client.dart` - Graphify nodes: 16
- `Application/lib/features/scanner/data/scanner_local_database.dart` - Graphify nodes: 27
- `Application/lib/features/scanner/data/scanner_contracts.dart` - Graphify nodes: 30

### Flutter Domain
- `Application/lib/features/scanner/domain/scanner_session.dart` - Graphify nodes: 26
- `Application/lib/features/scanner/domain/offline_scan.dart` - Graphify nodes: 22
- `Application/lib/features/scanner/domain/roster_student.dart` - Graphify nodes: 10
- `Application/lib/features/scanner/domain/scan_type.dart` - Graphify nodes: 3
- `Application/lib/features/scanner/domain/scan_sync_status.dart` - Graphify nodes: 11
- `Application/lib/features/scanner/domain/scanner_error_mapper.dart` - Graphify nodes: 11

### Flutter Screens and Widgets
- `Application/lib/features/scanner/presentation/scanner_home_screen.dart` - Graphify nodes: 22
- `Application/lib/features/scanner/presentation/screens/session_setup_screen.dart` - Graphify nodes: 19
- `Application/lib/features/scanner/presentation/screens/live_scanner_screen.dart` - Graphify nodes: 43
- `Application/lib/features/scanner/presentation/screens/offline_tools_screen.dart` - Graphify nodes: 21
- `Application/lib/features/scanner/presentation/screens/hydrated_roster_screen.dart` - Graphify nodes: 33
- `Application/lib/features/scanner/presentation/widgets/scanner_viewfinder.dart` - Graphify nodes: 18
- `Application/lib/features/scanner/presentation/widgets/sync_status_panel.dart` - Graphify nodes: 17

### Backend Scanner Boundary
- `Web/app/Models/EventScannerAssignment.php` - Graphify nodes: 12
- `Web/app/Models/ScannerSession.php` - Graphify nodes: 8
- `Web/app/Services/ScannerAssignmentService.php` - Graphify nodes: 19
- `Web/app/Services/ScannerSessionService.php` - Graphify nodes: 17
- `Web/app/Http/Controllers/Api/ScannerAssignmentController.php` - Graphify nodes: 14
- `Web/app/Support/ScannerAccess.php` - Graphify nodes: 12

### Tests
- `Application/test/scanner_controller_test.dart` - Graphify nodes: 56
- `Application/test/scanner_api_client_test.dart` - Graphify nodes: 2
- `Application/test/scanner_session_test.dart` - Graphify nodes: 2
- `Web/tests/Feature/UsmEaesTest.php` - Graphify nodes: 43

## Important Rules

- PRD Epic 3 requires account-authenticated event-scoped scanner sessions; normal student login is not enough for hydrate or sync.
- The mobile app can continue syncing previously captured valid offline scans during allowed grace paths.
- The backend remains the security boundary for scanner access, scope, scan windows, and session validity.
- The local SQLite store must preserve failed and pending scans for retry.

## Change Impact

Changes in this domain may affect:

- [[Attendance]]
- [[Authorization and Tenant Scope]]
- [[Queue and Concurrency]]
- [[Analytics and Exports]]

<!-- END GENERATED:eaes-knowledge -->
