---
type: domain
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Evaluation

#domain

## Purpose

Manages event-specific evaluation forms, student submissions, validity gates, evaluation windows, and sentiment inputs.

## Related Domains

- [[Event Management]]
- [[Attendance]]
- [[Analytics and Exports]]
- [[AI Insights]]

## Key Concepts

- [[Evaluation Submission]]
- [[Evaluation Form]]
- [[Attendance Record]]
- [[Event]]

## Main Workflows

- [[Evaluation Completion]]
- [[AI Sentiment and NLP]]

## Cross-Cutting Concerns

- [[Evaluation Gate]]
- [[Authorization and Tenant Scope]]
- [[Audit Logging]]

## Important Implementation

### Models and Support
- `Web/app/Models/Evaluation.php` - Graphify nodes: 6
- `Web/app/Models/EvaluationFormVersion.php` - Graphify nodes: 4
- `Web/app/Support/EventEvaluationForm.php` - Graphify nodes: 6
- `Web/app/Support/EvaluationWindow.php` - Graphify nodes: 6
- `Web/app/Support/EvaluationQuestions.php` - Graphify nodes: 9

### Controllers and Livewire
- `Web/app/Http/Controllers/Api/EvaluationController.php` - Graphify nodes: 3
- `Web/app/Http/Controllers/Web/PortalController.php` - Graphify nodes: 9
- `Web/app/Livewire/Dashboard/EventSentimentAction.php` - Graphify nodes: 6

### Jobs and Services
- `Web/app/Jobs/AnalyzeEventSentimentsJob.php` - Graphify nodes: 6
- `Web/app/Services/GeminiService.php` - Graphify nodes: 7
- `Web/app/Services/EventAnalyticsService.php` - Graphify nodes: 28

### Tests
- `Web/tests/Feature/WebPortalTest.php` - Graphify nodes: 122
- `Web/tests/Feature/UsmEaesTest.php` - Graphify nodes: 43
- `Web/tests/Feature/EventAnalyticsPageTest.php` - Graphify nodes: 17

## Important Rules

- PRD Epic 5 requires event-specific locked form snapshots approved with the event proposal.
- Evaluation submission toggles the attendance valid flag when the gate applies.
- The evaluation window closes 24 hours after the event or final event day ends.
- Sentiment analysis uses anonymized comments and writes sentiment fields back to evaluations.

## Change Impact

Changes in this domain may affect:

- [[Attendance]]
- [[Analytics and Exports]]
- [[AI Insights]]
- [[Event Management]]

<!-- END GENERATED:eaes-knowledge -->
