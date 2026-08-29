---
type: workflow
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Admin Assignment Governance

#workflow

## Summary

OSA manages organization/admin authority directly through active admin assignments rather than student self-application.

## Domains

- [[Organizations and Membership]]
- [[Authentication and Access Control]]

## Concepts

- [[Admin Assignment]]
- [[Organization]]
- [[College]]
- [[User]]

## Flow

1. OSA selects account
2. Assigns role and scope
3. Spatie role projection syncs
4. Active assignment checked by services
5. Audit entry records governance change

## Implementation

- `Web/app/Services/AdminAssignmentService.php` - Graphify nodes: 11
- `Web/app/Services/AdminScopeService.php` - Graphify nodes: 3
- `Web/app/Livewire/Dashboard/AdminAccessWorkspace.php` - Graphify nodes: 19

## Important Rules

- Admin applications are deprecated.
- Active term-bound assignments decide authority.

<!-- END GENERATED:eaes-knowledge -->
