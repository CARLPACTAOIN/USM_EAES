---
type: concept
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# User

#concept

## Meaning

The authenticated institutional account for students, faculty/staff, admins, scanners, and portal users.

## Belongs To

- [[Authentication and Access Control]]

## Relationships

- has [[Organization Membership]] records
- may hold active [[Admin Assignment]] records
- may be assigned as a [[Scanner Assignment]]
- submits [[Evaluation Submission]]

## Implementation

- `Web/app/Models/User.php` - Graphify nodes: 15
- `Web/app/Http/Controllers/Auth/GoogleAuthController.php` - Graphify nodes: 6
- `Web/app/Http/Controllers/Api/AuthController.php` - Graphify nodes: 11

## Important Constraints

- Institutional Google login enforces usm.edu.ph.
- Student profile membership fields must not be treated as admin tenant scope.

<!-- END GENERATED:eaes-knowledge -->
