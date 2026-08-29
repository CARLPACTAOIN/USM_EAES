---
type: workflow
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Scanner Assignment and Activation

#workflow

## Summary

Admins assign scanner accounts, and the Flutter app activates one assignment into a device-bound runtime scanner session.

## Domains

- [[Scanner Mobile App]]
- [[Authentication and Access Control]]
- [[Event Management]]

## Concepts

- [[Scanner Assignment]]
- [[Scanner Session]]
- [[Device Identity]]
- [[Participation Scope]]

## Flow

1. Authorized admin assigns scanner
2. Flutter account logs in
3. Assigned events listed
4. Device identity sent
5. Assignment activated
6. Scanner session token issued
7. Roster hydration allowed

## Implementation

- `Web/app/Services/ScannerAssignmentService.php` - Graphify nodes: 19
- `Web/app/Services/ScannerSessionService.php` - Graphify nodes: 17
- `Application/lib/features/auth/presentation/screens/assigned_events_screen.dart` - Graphify nodes: 38
- `Application/lib/features/scanner/presentation/screens/session_setup_screen.dart` - Graphify nodes: 19

## Important Rules

- Only activated scanner-session tokens can call scanner validation, hydrate, and sync endpoints.
- Revoked/expired/wrong-device sessions must fail backend validation.

<!-- END GENERATED:eaes-knowledge -->
