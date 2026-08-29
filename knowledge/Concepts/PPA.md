---
type: concept
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# PPA

#concept

## Meaning

The official proposal/permit packet for event approval, including proposal metadata, schedule, budget, official document, signatures, review status, and evaluation form.

## Belongs To

- [[Event Management]]

## Relationships

- approves [[Event]]
- may be required on [[Participation Scope]]
- locks [[Evaluation Form]] for attendees

## Implementation

- `Web/app/Services/EventProposalDraftService.php` - Graphify nodes: 7
- `Web/app/Livewire/Dashboard/EventProposalWorkflow.php` - Graphify nodes: 19
- `Web/app/Http/Controllers/Api/EventProposalController.php` - Graphify nodes: 13

## Important Constraints

- Scanner access remains locked until the relevant proposal/scope approval passes.
- Revision requests return the proposal to the organizer; rejection is final unless administratively reopened.

<!-- END GENERATED:eaes-knowledge -->
