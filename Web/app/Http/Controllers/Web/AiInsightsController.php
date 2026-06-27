<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeEventSentimentsJob;
use App\Models\Evaluation;
use App\Models\Event;
use App\Services\Contracts\AiServiceInterface;
use App\Support\EvaluationWindow;
use App\Support\EventTenantScope;
use App\Support\NlpQueryExecutor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AiInsightsController extends Controller
{
    public function index(Request $request)
    {
        return view('dashboard.ai.index', $this->viewData($request));
    }

    public function query(Request $request, AiServiceInterface $aiService, NlpQueryExecutor $queryExecutor)
    {
        $validator = Validator::make($request->all(), [
            'query' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return redirect()->route('dashboard.ai')->withErrors($validator)->withInput();
        }

        $queryText = $request->input('query');
        $parsedQuery = $aiService->parseNaturalLanguageQuery($queryText, $request->user());

        if (isset($parsedQuery['error'])) {
            return view('dashboard.ai.index', $this->viewData($request, [
                'queryText' => $queryText,
                'aiError' => 'NLP Query parsing failed: ' . $parsedQuery['error'],
            ]));
        }

        try {
            $queryResult = $queryExecutor->execute($request->user(), $parsedQuery);
        } catch (HttpException $exception) {
            return view('dashboard.ai.index', $this->viewData($request, [
                'queryText' => $queryText,
                'aiError' => $exception->getMessage() ?: 'Query target table is unauthorized or invalid.',
            ]));
        }

        $queryResult['results'] = $queryResult['results']
            ->map(fn ($row): array => (array) $row)
            ->all();

        return view('dashboard.ai.index', $this->viewData($request, [
            'queryText' => $queryText,
            'queryResult' => $queryResult,
        ]));
    }

    public function analyzeEventSentiments(Request $request, Event $event)
    {
        EventTenantScope::authorize($event, $request->user());

        if (!EvaluationWindow::isClosed($event)) {
            return redirect()->back()->with('error', 'Sentiment analysis is available after the evaluation window closes.');
        }

        $pendingComments = Evaluation::where('event_id', $event->id)
            ->where('sentiment', 'unprocessed')
            ->whereNotNull('open_comment')
            ->whereRaw("trim(coalesce(open_comment, '')) <> ''")
            ->count();

        if ($pendingComments === 0) {
            return redirect()->back()->with('success', 'No unprocessed evaluation comments found for this event.');
        }

        AnalyzeEventSentimentsJob::dispatch($event->id);

        return redirect()->back()->with('success', "Queued sentiment analysis for {$pendingComments} evaluation comment(s).");
    }

    private function viewData(Request $request, array $extra = []): array
    {
        $eventsQuery = Event::with(['organization', 'eventDays'])
            ->withCount([
                'evaluations as unprocessed_comment_count' => function ($query): void {
                    $query->where('sentiment', 'unprocessed')
                        ->whereNotNull('open_comment')
                        ->whereRaw("trim(coalesce(open_comment, '')) <> ''");
                },
                'evaluations as positive_comment_count' => fn ($query) => $query->where('sentiment', 'positive'),
                'evaluations as neutral_comment_count' => fn ($query) => $query->where('sentiment', 'neutral'),
                'evaluations as negative_comment_count' => fn ($query) => $query->where('sentiment', 'negative'),
            ])
            ->whereHas('evaluations');

        EventTenantScope::apply($eventsQuery, $request->user());

        $events = $eventsQuery
            ->latest()
            ->take(8)
            ->get()
            ->map(function (Event $event): Event {
                $event->evaluation_window_closes_at = EvaluationWindow::closesAt($event);
                $event->evaluation_window_closed = EvaluationWindow::isClosed($event);

                return $event;
            });

        return array_merge([
            'events' => $events,
            'queryText' => old('query', ''),
            'queryResult' => null,
            'aiError' => null,
        ], $extra);
    }
}
