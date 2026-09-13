<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('campus_location');
            $table->foreignUuid('college_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('managing_office');
            $table->text('inquiry_instructions')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->unique(['name', 'campus_location']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('venues');
    }
};
