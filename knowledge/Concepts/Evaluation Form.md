---
type: concept
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Evaluation Form

#concept

## Meaning

The locked event-level evaluation question snapshot reviewed with the proposal and answered by attendees.

## Belongs To

- [[Evaluation]]
- [[Event Management]]

## Relationships

- attached to [[Event]]
- answered by [[Evaluation Submission]]
- affects [[Analytics and Exports]] question labels

## Implementation

- `Web/app/Models/EvaluationFormVersion.php` - Graphify nodes: 4
- `Web/app/Support/EventEvaluationForm.php` - Graphify nodes: 6
- `Web/app/Support/EvaluationQuestions.php` - Graphify nodes: 9

## Important Constraints

- Approval requires a locked event-level snapshot where the PRD gate applies.

<!-- END GENERATED:eaes-knowledge -->
