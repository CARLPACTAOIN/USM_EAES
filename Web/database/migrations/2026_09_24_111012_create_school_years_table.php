<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('school_years', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('label')->unique();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status')->default('draft')->index();
            $table->timestamp('activated_at')->nullable();
            $table->foreignUuid('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('transition_started_at')->nullable();
            $table->date('transition_ends_at')->nullable();
            $table->foreignUuid('transition_opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('transition_closed_at')->nullable();
            $table->foreignUuid('transition_closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement("CREATE UNIQUE INDEX school_years_one_active_unique ON school_years (status) WHERE status = 'active'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('school_years');
    }
};
