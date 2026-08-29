---
type: workflow
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Google Authentication

#workflow

## Summary

Institutional login creates the base account context and API tokens; admin and scanner authority are resolved later through assignments.

## Domains

- [[Authentication and Access Control]]
- [[Organizations and Membership]]
- [[Scanner Mobile App]]

## Concepts

- [[User]]
- [[Admin Assignment]]
- [[Scanner Assignment]]
- [[Device Identity]]

## Flow

1. Google login
2. usm.edu.ph validation
3. User profile or staff pending state
4. Sanctum token issue
5. Admin assignment or scanner assignment lookup

## Implementation

- `Web/app/Http/Controllers/Auth/GoogleAuthController.php` - Graphify nodes: 6
- `Web/app/Http/Controllers/Api/AuthController.php` - Graphify nodes: 11
- `Application/lib/features/auth/data/google_auth_gateway.dart` - Graphify nodes: 22

## Important Rules

- Account login alone does not authorize scanner hydrate/sync.
- Non-student institutional users follow faculty/staff pending or HR verification paths.

<!-- END GENERATED:eaes-knowledge -->
