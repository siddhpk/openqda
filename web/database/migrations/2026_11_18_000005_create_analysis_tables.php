<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Method-neutral analysis objects: concepts, propositions and cases,
     * evidence linking them to coded segments (supporting, challenging,
     * illustrating, qualifying), revisions and case assessments.
     */
    public function up(): void
    {
        Schema::create('concepts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('definition')->nullable();
            $table->string('status', 40)->nullable();
            $table->json('properties')->nullable();
            $table->string('color', 30)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('creating_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'name']);
        });

        Schema::create('concept_code', function (Blueprint $table) {
            $table->foreignUuid('concept_id')->constrained('concepts')->cascadeOnDelete();
            $table->foreignUuid('code_id')->constrained('codes')->cascadeOnDelete();
            $table->primary(['concept_id', 'code_id']);
        });

        Schema::create('propositions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('statement')->nullable();
            $table->text('conditions')->nullable();
            $table->text('mechanism')->nullable();
            $table->text('scope')->nullable();
            $table->string('status', 40)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('creating_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('project_id');
        });

        Schema::create('proposition_concept', function (Blueprint $table) {
            $table->foreignUuid('proposition_id')->constrained('propositions')->cascadeOnDelete();
            $table->foreignUuid('concept_id')->constrained('concepts')->cascadeOnDelete();
            $table->string('role', 80)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->primary(['proposition_id', 'concept_id']);
        });

        Schema::create('analytic_evidence', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('target_type', 20); // concept | proposition
            $table->uuid('target_id');
            $table->foreignUuid('selection_id')->nullable()->constrained('selections')->nullOnDelete();
            $table->foreignUuid('source_id')->constrained('sources')->cascadeOnDelete();
            $table->unsignedInteger('start_position');
            $table->unsignedInteger('end_position');
            $table->text('text');
            $table->string('stance', 16); // supports | challenges | illustrates | qualifies
            $table->text('note')->nullable();
            // for challenging evidence: open | accounted_for | revised | unresolved
            $table->string('resolution', 16)->nullable();
            $table->text('resolution_note')->nullable();
            $table->foreignId('creating_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['project_id', 'target_type', 'target_id']);
            $table->unique(['target_type', 'target_id', 'selection_id']);
        });

        Schema::create('analytic_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('target_type', 20);
            $table->uuid('target_id');
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->string('reason', 500)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['target_type', 'target_id', 'version']);
        });

        Schema::create('cases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('creating_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'name']);
        });

        Schema::create('case_source', function (Blueprint $table) {
            $table->foreignUuid('case_id')->constrained('cases')->cascadeOnDelete();
            $table->foreignUuid('source_id')->constrained('sources')->cascadeOnDelete();
            $table->primary(['case_id', 'source_id']);
        });

        Schema::create('case_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('case_id')->constrained('cases')->cascadeOnDelete();
            $table->string('item_type', 20); // code | concept | proposition
            $table->uuid('item_id');
            $table->string('state', 16); // present | absent | unclear
            $table->text('note')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['case_id', 'item_type', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_assessments');
        Schema::dropIfExists('case_source');
        Schema::dropIfExists('cases');
        Schema::dropIfExists('analytic_revisions');
        Schema::dropIfExists('analytic_evidence');
        Schema::dropIfExists('proposition_concept');
        Schema::dropIfExists('propositions');
        Schema::dropIfExists('concept_code');
        Schema::dropIfExists('concepts');
    }
};
