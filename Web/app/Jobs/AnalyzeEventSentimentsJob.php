<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\Evaluation;
use App\Services\Contracts\AiServiceInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AnalyzeEventSentimentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $eventId;

    /**
     * Create a new job instance.
     *
     * @param  string  $eventId
     * @return void
     */
    public function __construct($eventId)
    {
        $this->eventId = $eventId;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(AiServiceInterface $aiService)
    {
        $eventId = $this->eventId;
        $event = Event::findOrFail($eventId);

        Log::info("Running sentiment analysis for event: {$event->title} ({$eventId})");

        // Fetch all unprocessed comments for this event
        $evaluations = Evaluation::where('event_id', $eventId)
            ->where('sentiment', 'unprocessed')
            ->whereNotNull('open_comment')
            ->whereRaw("trim(coalesce(open_comment, '')) <> ''")
            ->get();

        if ($evaluations->isEmpty()) {
            Log::info("No unprocessed comments found for event: {$event->title}");
            return;
        }

        // Map evaluations to format needed by the AI service
        $comments = $evaluations->map(function ($evaluation) {
            return [
                'id' => $evaluation->id,
                'comment' => $evaluation->open_comment
            ];
        })->toArray();

        // Run sentiments
        $results = $aiService->analyzeSentiments($comments);

        if (empty($results)) {
            Log::warning("Sentiment analysis returned empty result set for event: {$eventId}");
            return;
        }

        // Update database in single batch transaction
        \Illuminate\Support\Facades\DB::transaction(function () use ($results) {
            foreach ($results as $result) {
                if (!isset($result['id'], $result['sentiment'], $result['score'])) {
                    continue;
                }

                Evaluation::where('id', $result['id'])->update([
                    'sentiment' => $result['sentiment'],
                    'sentiment_score' => $result['score'],
                ]);
            }
        });

        Log::info("Sentiment analysis complete. Processed " . count($results) . " feedback comments.");
    }
}
