---
type: concept
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# College

#concept

## Meaning

Institutional academic grouping used for programs, LSG scope, participant scopes, and analytics filters.

## Belongs To

- [[Organizations and Membership]]

## Relationships

- contains [[Program]] records
- may bound [[Organization]] records
- can be the tenant scope of an [[Admin Assignment]]

## Implementation

- `Web/app/Models/College.php` - Graphify nodes: 5
- `Web/app/Models/Program.php` - Graphify nodes: 5
- `Web/app/Support/AdminScope.php` - Graphify nodes: 13

## Important Constraints

- Colleges are official master data maintained by OSA/Super Admin.

<!-- END GENERATED:eaes-knowledge -->
