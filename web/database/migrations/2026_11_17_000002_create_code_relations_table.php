<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Non-hierarchical relationships between codes. The types come from the
     * project's vocabulary. Hierarchy stays in codes.parent_id.
     */
    public function up(): void
    {
        Schema::create('code_relations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignUuid('from_code_id')->constrained('codes')->cascadeOnDelete();
            $table->foreignUuid('to_code_id')->constrained('codes')->cascadeOnDelete();
            $table->string('type', 64);
            $table->string('label')->nullable();
            $table->boolean('directed')->default(true);
            // one relationship per pair and type (label for custom types), see CodeRelation::dedupeKey()
            $table->char('dedupe_key', 40)->nullable();
            $table->tinyInteger('valence')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('creating_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id']);
            $table->index(['from_code_id', 'to_code_id']);
            $table->unique(['project_id', 'dedupe_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('code_relations');
    }
};
