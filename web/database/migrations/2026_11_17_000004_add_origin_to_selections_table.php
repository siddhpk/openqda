<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Provenance of a coded segment. NULL = coded by a person;
     * "autocode:<run id>" = suggested by autocoding and accepted by a person.
     */
    public function up(): void
    {
        Schema::table('selections', function (Blueprint $table) {
            $table->string('origin', 64)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('selections', function (Blueprint $table) {
            $table->dropColumn('origin');
        });
    }
};
