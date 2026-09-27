<?php

namespace App\Livewire\Dashboard;

use App\Jobs\AnalyzeEventSentimentsJob;
use App\Models\Evaluation;
use App\Models\Event;
use App\Support\EvaluationWindow;
use App\Support\EventTenantScope;
use Livewire\Attributes\Locked;
use Livewire\Component;

class EventSentimentAction extends Component
{
    #[Locked]
    public string $eventId;

    public int $pendingComments = 0;

    public bool $queued = false;

    public ?string $notice = null;

    public ?string $errorNotice = null;

    public string $buttonLabel = 'AI';

    public bool $fullWidth = false;

    public function mount(string $eventId, int $pendingComments = 0, string $buttonLabel = 'AI', bool $fullWidth = false): void
    {
        $this->eventId = $eventId;
        $this->pendingComments = $pendingComments;
        $this->buttonLabel = $buttonLabel;
        $this->fullWidth = $fullWidth;
    }

    public function analyze(): void
    {
        $this->notice = null;
        $this->errorNotice = null;

        $event = Event::findOrFail($this->eventId);
        EventTenantScope::authorize($event, auth()->user());

        if (! EvaluationWindow::isClosed($event)) {
            $this->errorNotice = 'Sentiment analysis is available after the evaluation window closes.';

            return;
        }

        $pendingComments = $this->pendingCommentCount();

        if ($pendingComments === 0) {
            $this->pendingComments = 0;
            $this->notice = 'No unprocessed evaluation comments found for this event.';

            return;
        }

        AnalyzeEventSentimentsJob::dispatch($event->id);

        $this->pendingComments = $pendingComments;
        $this->queued = true;
        $this->notice = "Queued sentiment analysis for {$pendingComments} evaluation comment(s).";
    }

    public function render()
    {
        return view('livewire.dashboard.event-sentiment-action');
    }

    private function pendingCommentCount(): int
    {
        return Evaluation::query()
            ->where('event_id', $this->eventId)
            ->pendingSentimentComments()
            ->count();
    }
}
