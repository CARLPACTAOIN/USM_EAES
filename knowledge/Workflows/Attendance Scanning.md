---
type: workflow
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Attendance Scanning

#workflow

## Summary

Scanner mode captures known and unresolved Time-In/Time-Out records locally, preserving enough context for backend validation and resolution.

## Domains

- [[Scanner Mobile App]]
- [[Attendance]]
- [[Event Management]]

## Concepts

- [[Raw Scan]]
- [[Roster Student]]
- [[Scanner Session]]
- [[Event Day]]

## Flow

1. Hydrated roster loaded
2. Scanner selects Time-In or Time-Out
3. QR/manual ID captured
4. Local duplicate cooldown applies
5. Offline scan stored with event/scope/session/device context
6. Sync state remains pending

## Implementation

- `Application/lib/features/scanner/presentation/scanner_controller.dart` - Graphify nodes: 95
- `Application/lib/features/scanner/data/scanner_local_database.dart` - Graphify nodes: 27
- `Application/lib/features/scanner/presentation/screens/live_scanner_screen.dart` - Graphify nodes: 43

## Important Rules

- Client-side checks are user feedback; backend checks remain the security boundary.
- Unknown local identities are saved as unresolved instead of blocked.

<!-- END GENERATED:eaes-knowledge -->
