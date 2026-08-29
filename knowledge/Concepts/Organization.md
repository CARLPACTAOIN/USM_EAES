---
type: concept
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Organization

#concept

## Meaning

A USM governance or student-affairs unit such as USG, LSG, society, non-academic organization, academic unit, or office organizer.

## Belongs To

- [[Organizations and Membership]]

## Relationships

- contains [[Organization Membership]]
- belongs under [[College]] when scoped
- owns [[Event]] proposals
- receives [[Participation Scope]] invitations
- feeds [[Analytics and Exports]]

## Implementation

- `Web/app/Models/Organization.php` - Graphify nodes: 10
- `Web/app/Services/OrganizationManagementService.php` - Graphify nodes: 6
- `Web/app/Http/Controllers/Web/OrganizationProfileController.php` - Graphify nodes: 7

## Important Constraints

- Organization creation and maintenance are OSA-controlled.
- Organization identity is not equivalent to admin authority; active admin assignments are checked separately.

<!-- END GENERATED:eaes-knowledge -->
