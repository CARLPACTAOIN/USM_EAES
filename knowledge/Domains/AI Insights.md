---
type: domain
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# AI Insights

#domain

## Purpose

Provides sentiment analysis and scoped NLP query assistance through swappable AI services.

## Related Domains

- [[Evaluation]]
- [[Analytics and Exports]]
- [[Authentication and Access Control]]
- [[Queue and Concurrency]]

## Key Concepts

- [[Evaluation Submission]]
- [[Event]]
- [[Organization]]
- [[Admin Assignment]]

## Main Workflows

- [[AI Sentiment and NLP]]
- [[Organization Analytics]]

## Cross-Cutting Concerns

- [[Authorization and Tenant Scope]]
- [[Queue and Concurrency]]
- [[Caching]]

## Important Implementation

### Services and Support
- `Web/app/Services/Contracts/AiServiceInterface.php` - Graphify nodes: 3
- `Web/app/Services/GeminiService.php` - Graphify nodes: 7
- `Web/app/Services/OllamaService.php` - Graphify nodes: 7
- `Web/app/Support/NlpQueryExecutor.php` - Graphify nodes: 5

### Controllers and Livewire
- `Web/app/Http/Controllers/Web/AiInsightsController.php` - Graphify nodes: 6
- `Web/app/Http/Controllers/Api/NlpQueryController.php` - Graphify nodes: 3
- `Web/app/Livewire/Dashboard/AiInsightsQuery.php` - Graphify nodes: 8
- `Web/app/Livewire/Dashboard/EventSentimentAction.php` - Graphify nodes: 6

### Jobs and Commands
- `Web/app/Jobs/AnalyzeEventSentimentsJob.php` - Graphify nodes: 6
- `Web/routes/console.php` - Graphify nodes: 1

### Tests
- `Web/tests/Feature/UsmEaesTest.php` - Graphify nodes: 43
- `Web/tests/Feature/WebPortalTest.php` - Graphify nodes: 122
- `Web/tests/Feature/QueueResilienceTest.php` - Graphify nodes: 8

## Important Rules

- PRD §6.2 uses anonymized evaluation comments for sentiment classification.
- PRD §6.3 requires tenant scope to be hard-applied around NLP query execution.
- AI providers are behind AiServiceInterface, with Gemini and Ollama implementations present.
- AI jobs should use bounded retry and queue behavior rather than blocking user workflows.

## Change Impact

Changes in this domain may affect:

- [[Evaluation]]
- [[Analytics and Exports]]
- [[Authorization and Tenant Scope]]
- [[Queue and Concurrency]]

<!-- END GENERATED:eaes-knowledge -->
