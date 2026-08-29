---
type: concept
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Scanner Assignment

#concept

## Meaning

The admin-created assignment that authorizes a specific institutional account to activate scanner mode for an event participation scope.

## Belongs To

- [[Scanner Mobile App]]
- [[Authentication and Access Control]]

## Relationships

- assigned to [[User]]
- targets [[Event]] and [[Participation Scope]]
- activates [[Scanner Session]]

## Implementation

- `Web/app/Models/EventScannerAssignment.php` - Graphify nodes: 12
- `Web/app/Services/ScannerAssignmentService.php` - Graphify nodes: 19
- `Web/app/Http/Controllers/Api/ScannerAssignmentController.php` - Graphify nodes: 14

## Important Constraints

- Assignment is account-scoped and revocable.
- It does not itself replace the runtime scanner session token.

<!-- END GENERATED:eaes-knowledge -->
