<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laptop collaboration: an operation id ("<node>:<seq>") counts per project,
 * so it is unique per project, not across all of them. With the old global
 * index the first operations of a node's second collaboration (or of a
 * project collaborated again after leaving) were dropped, among them the
 * entry that makes the node Primary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collab_ops', function (Blueprint $table) {
            $table->dropUnique(['op_id']);
            $table->unique(['project_gid', 'op_id']);
        });
    }

    public function down(): void
    {
        Schema::table('collab_ops', function (Blueprint $table) {
            $table->dropUnique(['project_gid', 'op_id']);
            $table->unique(['op_id']);
        });
    }
};
