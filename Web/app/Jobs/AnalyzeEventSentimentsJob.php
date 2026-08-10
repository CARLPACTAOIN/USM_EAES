<?php

namespace App\Jobs;

use App\Models\Evaluation;
use App\Models\Event;
use App\Services\Contracts\AiServiceInterface;
use App\Services\EventAnalyticsCache;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnalyzeEventSentimentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    protected string $eventId;

    public function __construct(string $eventId)
    {
        $this->onQueue('ai');
        $this->eventId = $eventId;
    }

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('event-sentiment-analysis:'.$this->eventId))
                ->releaseAfter(30)
                ->expireAfter(240),
        ];
    }

    public function handle(AiServiceInterface $aiService, EventAnalyticsCache $analyticsCache): void
    {
        $eventId = $this->eventId;
        Event::findOrFail($eventId);

        Log::info('Running event sentiment analysis.', ['event_id' => $eventId]);

        $evaluations = Evaluation::where('event_id', $eventId)
            ->pendingSentimentComments()
            ->limit(config('services.eaes.sentiment_queue_batch_size', 100))
            ->get();

        if ($evaluations->isEmpty()) {
            Log::info('No unprocessed sentiment comments found.', ['event_id' => $eventId]);

            return;
        }

        $comments = $evaluations->map(function (Evaluation $evaluation): array {
            return [
                'id' => $evaluation->id,
                'comment' => $evaluation->open_comment,
            ];
        })->toArray();

        $results = $aiService->analyzeSentiments($comments);

        if (empty($results)) {
            Log::warning('Sentiment analysis returned no results.', ['event_id' => $eventId]);

            return;
        }

        DB::transaction(function () use ($eventId, $results): void {
            foreach ($results as $result) {
                if (! isset($result['id'], $result['sentiment'], $result['score'])) {
                    continue;
                }

                Evaluation::where('event_id', $eventId)->where('id', $result['id'])->update([
                    'sentiment' => $result['sentiment'],
                    'sentiment_score' => $result['score'],
                ]);
            }
        });

        $analyticsCache->invalidateEvent($eventId);

        Log::info('Event sentiment analysis complete.', [
            'event_id' => $eventId,
            'processed_count' => count($results),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Event sentiment analysis job failed.', [
            'event_id' => $this->eventId,
            'exception_class' => $exception ? $exception::class : null,
        ]);
    }
}
