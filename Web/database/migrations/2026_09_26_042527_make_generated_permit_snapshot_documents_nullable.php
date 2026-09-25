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
        Schema::table('event_permit_versions', function (Blueprint $table) {
            $table->string('confirmation_office')->nullable()->change();
            $table->date('confirmation_date')->nullable()->change();
            $table->string('confirmation_document_path')->nullable()->change();
            $table->string('confirmation_document_original_name')->nullable()->change();
            $table->string('ppa_document_path')->nullable()->change();
            $table->string('ppa_document_original_name')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('event_permit_versions')->whereNull('confirmation_office')->update(['confirmation_office' => 'N/A']);
        DB::table('event_permit_versions')->whereNull('confirmation_date')->update(['confirmation_date' => now()->toDateString()]);
        DB::table('event_permit_versions')->whereNull('confirmation_document_path')->update(['confirmation_document_path' => '']);
        DB::table('event_permit_versions')->whereNull('confirmation_document_original_name')->update(['confirmation_document_original_name' => '']);
        DB::table('event_permit_versions')->whereNull('ppa_document_path')->update(['ppa_document_path' => '']);
        DB::table('event_permit_versions')->whereNull('ppa_document_original_name')->update(['ppa_document_original_name' => '']);

        Schema::table('event_permit_versions', function (Blueprint $table) {
            $table->string('confirmation_office')->nullable(false)->change();
            $table->date('confirmation_date')->nullable(false)->change();
            $table->string('confirmation_document_path')->nullable(false)->change();
            $table->string('confirmation_document_original_name')->nullable(false)->change();
            $table->string('ppa_document_path')->nullable(false)->change();
            $table->string('ppa_document_original_name')->nullable(false)->change();
        });
    }
};
