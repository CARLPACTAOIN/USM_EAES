---
type: workflow
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Participant Scope Approval

#workflow

## Summary

Invited organizations submit or acknowledge their own participation scope before delegated scanning and roll-up analytics are official.

## Domains

- [[Event Management]]
- [[Organizations and Membership]]
- [[Scanner Mobile App]]
- [[Analytics and Exports]]

## Concepts

- [[Participation Scope]]
- [[PPA]]
- [[Scanner Assignment]]
- [[Organization]]

## Flow

1. Parent event submitted
2. Participant scope invited
3. Participant acknowledges or submits PPA
4. OSA/organizer review
5. Approved/not-required scope unlocks scanner management
6. Analytics roll-up includes eligible scope

## Implementation

- `Web/app/Services/ParticipationScopeService.php` - Graphify nodes: 17
- `Web/app/Livewire/Dashboard/EventParticipantScopes.php` - Graphify nodes: 31
- `Web/tests/Feature/ParticipantScopeConcurrencyTest.php` - Graphify nodes: 16

## Important Rules

- Organizer cannot duplicate itself as a participant scope.
- Participant scanners cannot hydrate/sync before participant PPA approval or not-required status.

<!-- END GENERATED:eaes-knowledge -->
