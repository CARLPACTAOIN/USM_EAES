---
type: concept
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Pending Student Link

#concept

## Meaning

A dashboard-reviewable unresolved scan that could not be matched to a registered user during server-side resolution.

## Belongs To

- [[Attendance]]

## Relationships

- created from [[Raw Scan]]
- resolved or flagged by authorized admins
- updates [[Attendance Record]] possibilities

## Implementation

- `Web/app/Models/PendingStudentLink.php` - Graphify nodes: 9
- `Web/app/Livewire/Dashboard/PendingQrLinks.php` - Graphify nodes: 14
- `Web/app/Http/Controllers/Api/PendingStudentLinkController.php` - Graphify nodes: 9

## Important Constraints

- Unknown QR/manual IDs should be reviewable without exposing raw secrets unnecessarily.
- Resolution is tenant-scoped and audited.

<!-- END GENERATED:eaes-knowledge -->
