---
type: concept
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Device Identity

#concept

## Meaning

The stable mobile-device identity used to bind scanner sessions and support same-device resume while blocking unauthorized transfer.

## Belongs To

- [[Scanner Mobile App]]

## Relationships

- binds [[Scanner Session]]
- sent during [[Scanner Assignment and Activation]]
- stamps [[Raw Scan]] context

## Implementation

- `Application/lib/features/auth/data/device_identity_provider.dart` - Graphify nodes: 9
- `Application/test/device_identity_provider_test.dart` - Graphify nodes: 2
- `Web/app/Services/ScannerSessionService.php` - Graphify nodes: 17

## Important Constraints

- Device identity is not a durable attendance record; it is a scanner security and continuity signal.

<!-- END GENERATED:eaes-knowledge -->
