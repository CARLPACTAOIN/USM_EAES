---
type: concept
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Program

#concept

## Meaning

Academic program under a college, used for student profile demographics, organization membership, roster scope, and analytics filtering.

## Belongs To

- [[Organizations and Membership]]

## Relationships

- belongs to [[College]]
- maps to [[Organization]] through organization_programs
- classifies [[User]] profile and [[Organization Membership]] records

## Implementation

- `Web/app/Models/Program.php` - Graphify nodes: 5
- `Web/app/Support/YearLevels.php` - Graphify nodes: 5

## Important Constraints

- Program and year level are analytics/profile dimensions, not admin tenant authority.

<!-- END GENERATED:eaes-knowledge -->
