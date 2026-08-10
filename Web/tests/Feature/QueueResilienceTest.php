<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeEventSentimentsJob;
use App\Jobs\ResolveAttendanceJob;
use App\Services\QueueOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Mockery;
use Predis\Connection\ConnectionException;
use Predis\Connection\NodeConnectionInterface;
use Tests\TestCase;

class QueueResilienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_attendance_outbox_dispatches_once_and_preserves_a_durable_record(): void
    {
        Queue::fake();
        $outbox = app(QueueOutboxService::class);
        $outboxEntryId = $outbox->recordAttendanceResolution(
            (string) Str::uuid(),
            (string) Str::uuid(),
            [(string) Str::uuid()],
            [(string) Str::uuid()],
        );

        $this->assertNotNull($outboxEntryId);
        $this->assertTrue($outbox->dispatch($outboxEntryId));
        $this->assertFalse($outbox->dispatch($outboxEntryId));
        $this->assertDatabaseHas('queue_outbox_entries', [
            'id' => $outboxEntryId,
            'dispatch_attempts' => 1,
        ]);
        $this->assertNotNull(DB::table('queue_outbox_entries')->where('id', $outboxEntryId)->value('dispatched_at'));
        Queue::assertPushedOn('attendance', ResolveAttendanceJob::class);
        Queue::assertPushed(ResolveAttendanceJob::class, 1);
    }

    public function test_scheduled_outbox_dispatch_is_idempotent(): void
    {
        Queue::fake();
        $outbox = app(QueueOutboxService::class);
        $outbox->recordAttendanceResolution(
            (string) Str::uuid(),
            (string) Str::uuid(),
            [(string) Str::uuid()],
            [(string) Str::uuid()],
        );

        $this->assertSame(1, $outbox->dispatchPending());
        $this->assertSame(0, $outbox->dispatchPending());
        Queue::assertPushed(ResolveAttendanceJob::class, 1);
    }

    public function test_queue_jobs_use_prioritized_queues_and_bounded_overlap_policy(): void
    {
        $attendanceJob = new ResolveAttendanceJob('event-id', ['student-id'], 'scope-id');
        $sentimentJob = new AnalyzeEventSentimentsJob('event-id');
        $middleware = $sentimentJob->middleware();

        $this->assertSame('attendance', $attendanceJob->queue);
        $this->assertSame('ai', $sentimentJob->queue);
        $this->assertSame(3, $sentimentJob->tries);
        $this->assertSame(180, $sentimentJob->timeout);
        $this->assertSame([30, 120, 300], $sentimentJob->backoff);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame('event-sentiment-analysis:event-id', $middleware[0]->key);
    }

    public function test_redis_connection_failures_have_a_retryable_api_response(): void
    {
        Route::get('/api/testing/redis-unavailable', function (): void {
            throw new ConnectionException(
                Mockery::mock(NodeConnectionInterface::class),
                'Redis connection refused.',
            );
        });

        $this->getJson('/api/testing/redis-unavailable')
            ->assertServiceUnavailable()
            ->assertHeader('Retry-After', '30')
            ->assertJson([
                'success' => false,
                'retry_after' => 30,
            ]);
    }

    public function test_queue_health_check_passes_without_pending_work_on_database_fallback(): void
    {
        config([
            'queue.default' => 'database',
            'cache.default' => 'database',
        ]);

        $this->artisan('eaes:queue-health')->assertExitCode(0);
    }

    public function test_queue_visibility_timeout_exceeds_the_longest_job_timeout(): void
    {
        $this->assertSame(300, config('queue.connections.database.retry_after'));
        $this->assertSame(300, config('queue.connections.redis.retry_after'));
        $this->assertSame(5, config('queue.connections.redis.block_for'));
    }
}
