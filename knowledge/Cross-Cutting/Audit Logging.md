---
type: cross-cutting
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Audit Logging

#cross-cutting

## Purpose

Captures sensitive administrative changes, force validations, pending-link actions, organization governance, and workflow review history.

## Related Domains

- [[Authentication and Access Control]]
- [[Event Management]]
- [[Attendance]]
- [[Organizations and Membership]]

## Key Concepts

- [[Admin Assignment]]
- [[Pending Student Link]]
- [[Attendance Record]]
- [[Organization]]

## Important Implementation

### Model
- `Web/app/Models/AuditLog.php` - Graphify nodes: 3

### Controllers and Services
- `Web/app/Services/OrganizationManagementService.php` - Graphify nodes: 6
- `Web/app/Services/AdminAssignmentService.php` - Graphify nodes: 11
- `Web/app/Http/Controllers/Web/DashboardController.php` - Graphify nodes: 48

### Tests
- `Web/tests/Feature/AdminOnboardingTest.php` - Graphify nodes: 32
- `Web/tests/Feature/WebPortalTest.php` - Graphify nodes: 122

## Important Rules

- Do not log full QR values, credentials, OAuth tokens, scanner tokens, or API tokens.
- Tenant-scoped audit visibility matters for OSA Admin Access and governance workflows.

## Change Impact

- [[Authorization and Tenant Scope]]
- [[Attendance]]
- [[Event Management]]

<!-- END GENERATED:eaes-knowledge -->
