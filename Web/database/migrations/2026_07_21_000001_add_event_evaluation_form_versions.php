<?php

use App\Support\EvaluationQuestions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_form_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->string('title')->default('Event Evaluation');
            $table->jsonb('questions');
            $table->uuid('created_by')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->uuid('evaluation_form_version_id')->nullable()->after('evaluation_open');
            $table->foreign('evaluation_form_version_id')
                ->references('id')
                ->on('evaluation_form_versions')
                ->onDelete('set null');
        });

        Schema::table('evaluations', function (Blueprint $table) {
            $table->uuid('evaluation_form_version_id')->nullable()->after('student_id');
            $table->foreign('evaluation_form_version_id')
                ->references('id')
                ->on('evaluation_form_versions')
                ->onDelete('set null');
        });

        $this->backfillExistingEvents();
    }

    public function down(): void
    {
        Schema::table('evaluations', function (Blueprint $table) {
            $table->dropForeign(['evaluation_form_version_id']);
            $table->dropColumn('evaluation_form_version_id');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign(['evaluation_form_version_id']);
            $table->dropColumn('evaluation_form_version_id');
        });

        Schema::dropIfExists('evaluation_form_versions');
    }

    private function backfillExistingEvents(): void
    {
        $questions = json_encode(EvaluationQuestions::defaultSchema());
        $now = now();

        DB::table('events')
            ->orderBy('id')
            ->select(['id', 'title'])
            ->chunk(100, function ($events) use ($questions, $now): void {
                foreach ($events as $event) {
                    $formId = (string) Str::uuid();

                    DB::table('evaluation_form_versions')->insert([
                        'id' => $formId,
                        'event_id' => $event->id,
                        'title' => 'Evaluation - ' . $event->title,
                        'questions' => $questions,
                        'created_by' => null,
                        'locked_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    DB::table('events')
                        ->where('id', $event->id)
                        ->update(['evaluation_form_version_id' => $formId]);

                    DB::table('evaluations')
                        ->where('event_id', $event->id)
                        ->whereNull('evaluation_form_version_id')
                        ->update(['evaluation_form_version_id' => $formId]);
                }
            });
    }
};
