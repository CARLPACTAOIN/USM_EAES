---
type: concept
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Scanner Session

#concept

## Meaning

The runtime, device-bound scanner token used by the Flutter app for validation, roster hydration, offline capture context, and sync.

## Belongs To

- [[Scanner Mobile App]]
- [[Attendance]]

## Relationships

- created from [[Scanner Assignment]]
- scopes [[Raw Scan]] ingestion
- uses [[Device Identity]]
- validates access to [[Offline Sync]]

## Implementation

- `Web/app/Models/ScannerSession.php` - Graphify nodes: 8
- `Web/app/Services/ScannerSessionService.php` - Graphify nodes: 17
- `Application/lib/features/scanner/domain/scanner_session.dart` - Graphify nodes: 26

## Important Constraints

- Hydrate and sync require the activated scanner session token.
- Device binding is validated at activation/hydration/sync time.

<!-- END GENERATED:eaes-knowledge -->
