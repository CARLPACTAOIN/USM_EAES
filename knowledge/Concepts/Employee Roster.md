---
type: concept
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Employee Roster

#concept

## Meaning

Faculty/staff verification data used by HR-domain attendance and scanner hydration.

## Belongs To

- [[HR Attendance]]

## Relationships

- verifies faculty/staff [[User]] accounts
- feeds [[Scanner Mobile App]] hydration for HR events
- produces [[Attendance Record]] data for HR events

## Implementation

- `Web/app/Models/EmployeeProfile.php` - Graphify nodes: 5
- `Web/app/Models/EmployeeRosterEntry.php` - Graphify nodes: 5

## Important Constraints

- HR roster verification is the trust boundary for faculty/staff attendance scanning.

<!-- END GENERATED:eaes-knowledge -->
