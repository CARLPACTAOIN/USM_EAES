<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('personal_access_tokens') || ! Schema::hasColumn('personal_access_tokens', 'tokenable_id')) {
            return;
        }

        if (DB::getDriverName() !== 'pgsql' || $this->tokenableIdType() === 'uuid') {
            return;
        }

        // Legacy bigint token owners cannot resolve against UUID users, so remove them before changing type.
        DB::statement("
            DELETE FROM personal_access_tokens
            WHERE tokenable_id::text !~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
        ");

        DB::statement('
            ALTER TABLE personal_access_tokens
            ALTER COLUMN tokenable_id TYPE uuid
            USING tokenable_id::text::uuid
        ');
    }

    public function down(): void
    {
        if (! Schema::hasTable('personal_access_tokens') || ! Schema::hasColumn('personal_access_tokens', 'tokenable_id')) {
            return;
        }

        if (DB::getDriverName() !== 'pgsql' || $this->tokenableIdType() !== 'uuid') {
            return;
        }

        DB::table('personal_access_tokens')->delete();

        DB::statement('
            ALTER TABLE personal_access_tokens
            ALTER COLUMN tokenable_id TYPE bigint
            USING tokenable_id::text::bigint
        ');
    }

    private function tokenableIdType(): ?string
    {
        $column = DB::selectOne("
            SELECT data_type
            FROM information_schema.columns
            WHERE table_schema = current_schema()
                AND table_name = 'personal_access_tokens'
                AND column_name = 'tokenable_id'
        ");

        return $column?->data_type;
    }
};
