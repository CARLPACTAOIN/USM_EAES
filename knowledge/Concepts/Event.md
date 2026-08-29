---
type: concept
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Event

#concept

## Meaning

The central proposal and runtime object for an activity, with approval status, event domain, schedule, lifecycle phase, attendance policy, evaluation form, and analytics context.

## Belongs To

- [[Event Management]]

## Relationships

- owned by [[Organization]]
- scheduled through [[Event Day]]
- has [[Participation Scope]] records
- produces [[Raw Scan]] and [[Attendance Record]] data
- may require [[Evaluation Form]] and [[Evaluation Submission]] records

## Implementation

- `Web/app/Models/Event.php` - Graphify nodes: 16
- `Web/app/Services/EventLifecycleService.php` - Graphify nodes: 15
- `Web/app/Services/EventProposalDraftService.php` - Graphify nodes: 7

## Important Constraints

- Proposal status and lifecycle phase are separate.
- Runtime scanners stay locked until proposal approval and scope approval rules pass.
- Event-level late cutoff overrides participant-scope fallback when set.

<!-- END GENERATED:eaes-knowledge -->
