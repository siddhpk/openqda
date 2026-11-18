<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('variables', function (Blueprint $table) {
            $table->uuid('project_id')
                ->nullable()
                ->after('source_id');

            // REFI guid
            $table->uuid('guid')
                ->nullable()
                ->after('project_id');

            $table->index('project_id');
            $table->unique('guid');
        });

        if (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite (laptop edition): same backfill without MySQL-only syntax
            DB::statement('
                UPDATE variables
                SET project_id = (SELECT s.project_id FROM sources s WHERE s.id = variables.source_id)
                WHERE project_id IS NULL
            ');
            DB::table('variables')->whereNull('guid')->pluck('id')->each(
                fn ($id) => DB::table('variables')->where('id', $id)->update(['guid' => (string) Str::uuid()])
            );

            return;
        }

        // Backfill project_id from the owning source so existing variables
        // (including lock variables created via Source::lock()) remain visible
        // in controllers and UI that query by project_id.
        DB::statement('
            UPDATE variables v
            JOIN sources s ON v.source_id = s.id
            SET v.project_id = s.project_id
            WHERE v.project_id IS NULL
        ');

        // Generate guid for old rows
        DB::statement('
            UPDATE variables
            SET guid = UUID()
            WHERE guid IS NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('variables', function (Blueprint $table) {
            $table->dropIndex(['project_id']);
            $table->dropUnique(['guid']);
            $table->dropColumn(['project_id', 'guid']);
        });
    }
};
