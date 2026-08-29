---
type: concept
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Admin Assignment

#concept

## Meaning

The active, term-bound record that gives a user administrative authority over an organization, college, HR, academic unit, or office scope.

## Belongs To

- [[Authentication and Access Control]]
- [[Organizations and Membership]]

## Relationships

- grants authority to [[User]]
- scopes actions over [[Organization]] or [[College]]
- guards [[Event Management]], [[Analytics and Exports]], and scanner management

## Implementation

- `Web/app/Models/AdminAssignment.php` - Graphify nodes: 7
- `Web/app/Services/AdminAssignmentService.php` - Graphify nodes: 11
- `Web/app/Services/AdminScopeService.php` - Graphify nodes: 3
- `Web/app/Support/AdminScope.php` - Graphify nodes: 13

## Important Constraints

- Admin assignments are the source of truth for tenant authority.
- Spatie roles are synchronized as the permission projection.

<!-- END GENERATED:eaes-knowledge -->
