---
type: concept
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Participation Scope

#concept

## Meaning

The event audience and ownership unit for organizer and participant attendance scopes, PPA status, capture mode, scanner authority, and analytics roll-up.

## Belongs To

- [[Event Management]]
- [[Attendance]]

## Relationships

- belongs to [[Event]]
- targets [[Organization]], [[College]], or [[Program]]
- gates [[Scanner Assignment]] and [[Scanner Session]]
- scopes [[Raw Scan]] and [[Attendance Record]]

## Implementation

- `Web/app/Models/EventParticipationScope.php` - Graphify nodes: 21
- `Web/app/Services/ParticipationScopeService.php` - Graphify nodes: 17
- `Web/app/Livewire/Dashboard/EventParticipantScopes.php` - Graphify nodes: 31

## Important Constraints

- Participant PPAs must be approved or not required before official scanning/counting.
- Organizer scope is always represented for the organizing organization.

<!-- END GENERATED:eaes-knowledge -->
