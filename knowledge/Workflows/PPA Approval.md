---
type: workflow
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# PPA Approval

#workflow

## Summary

Organizer-created proposal data flows through OSA review before scanners and official event workflows unlock.

## Domains

- [[Event Management]]
- [[Organizations and Membership]]
- [[Evaluation]]

## Concepts

- [[PPA]]
- [[Event]]
- [[Evaluation Form]]
- [[Participation Scope]]

## Flow

1. Draft proposal
2. Attach official PPA and schedule
3. Submit
4. OSA review
5. Approve, reject, or request revision
6. Approved event unlocks downstream scanner paths

## Implementation

- `Web/app/Services/EventProposalDraftService.php` - Graphify nodes: 7
- `Web/app/Livewire/Dashboard/EventProposalWorkflow.php` - Graphify nodes: 19
- `Web/app/Http/Controllers/Api/EventProposalController.php` - Graphify nodes: 13

## Important Rules

- Organizer drafts remain private until submitted.
- Approval requires the relevant PPA/evaluation constraints to pass.

<!-- END GENERATED:eaes-knowledge -->
