<?php

namespace App\Console\Commands;

use App\Services\QueueOutboxService;
use Illuminate\Console\Command;

class DispatchQueueOutbox extends Command
{
    protected $signature = 'eaes:dispatch-queue-outbox {--limit=100 : Maximum pending entries to attempt}';

    protected $description = 'Dispatch durable EAES queue outbox entries after queue recovery.';

    public function handle(QueueOutboxService $outbox): int
    {
        $limit = max(1, min((int) $this->option('limit'), 1000));
        $dispatched = $outbox->dispatchPending($limit);

        $this->info("Dispatched {$dispatched} queue outbox entr".($dispatched === 1 ? 'y' : 'ies').'.');

        return self::SUCCESS;
    }
}
