---
type: workflow
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# AI Sentiment and NLP

#workflow

## Summary

Closed evaluations can be analyzed for sentiment, and admins can ask scoped analytics questions through the NLP query assistant.

## Domains

- [[AI Insights]]
- [[Evaluation]]
- [[Analytics and Exports]]
- [[Authentication and Access Control]]

## Concepts

- [[Evaluation Submission]]
- [[Event]]
- [[Organization]]
- [[Admin Assignment]]

## Flow

1. Evaluation window closes
2. AnalyzeEventSentimentsJob batches anonymized comments
3. AiServiceInterface classifies sentiment
4. Evaluation sentiment fields update
5. Admin NLP query is parsed
6. NlpQueryExecutor applies tenant scope

## Implementation

- `Web/app/Jobs/AnalyzeEventSentimentsJob.php` - Graphify nodes: 6
- `Web/app/Services/GeminiService.php` - Graphify nodes: 7
- `Web/app/Services/OllamaService.php` - Graphify nodes: 7
- `Web/app/Support/NlpQueryExecutor.php` - Graphify nodes: 5

## Important Rules

- Comments sent to AI are anonymized.
- NLP query execution must hard-apply active admin assignment scope.

<!-- END GENERATED:eaes-knowledge -->
