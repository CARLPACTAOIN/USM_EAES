---
type: concept
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Organization Membership

#concept

## Meaning

A user's membership relationship to an organization, with membership type, academic year, verification status, college/program attribution, and year-level metadata.

## Belongs To

- [[Organizations and Membership]]

## Relationships

- connects [[User]] to [[Organization]]
- contributes to scanner rosters
- feeds [[Analytics and Exports]] denominators

## Implementation

- `Web/app/Models/OrganizationMembership.php` - Graphify nodes: 7
- `Web/app/Services/ParticipationScopeService.php` - Graphify nodes: 17

## Important Constraints

- Membership supports student-affairs rosters and analytics; it does not grant admin authority by itself.

<!-- END GENERATED:eaes-knowledge -->
