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
        Schema::table('events', function (Blueprint $table) {
            $table->string('title')->nullable()->change();
            $table->date('start_date')->nullable()->change();
            $table->date('end_date')->nullable()->change();
            $table->string('location_type')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('events')->whereNull('title')->update(['title' => 'Untitled PPA Draft']);
        DB::table('events')->whereNull('start_date')->update(['start_date' => now()->toDateString()]);
        DB::table('events')->whereNull('end_date')->update(['end_date' => now()->toDateString()]);
        DB::table('events')->whereNull('location_type')->update(['location_type' => 'on-campus']);

        Schema::table('events', function (Blueprint $table) {
            $table->string('title')->nullable(false)->change();
            $table->date('start_date')->nullable(false)->change();
            $table->date('end_date')->nullable(false)->change();
            $table->string('location_type')->default('on-campus')->change();
        });
    }
};
